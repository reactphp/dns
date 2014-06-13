<?php

namespace React\Dns\Query;

use React\Dns\BadServerException;
use React\Dns\Model\Message;
use React\Dns\Protocol\Parser;
use React\Dns\Protocol\BinaryDumper;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Socket\Connection;

class Executor implements ExecutorInterface
{
    private $loop;
    private $parser;
    private $dumper;
    private $timeout;

    public function __construct(LoopInterface $loop, Parser $parser, BinaryDumper $dumper, $timeout = 5)
    {
        $this->loop = $loop;
        $this->parser = $parser;
        $this->dumper = $dumper;
        $this->timeout = $timeout;
    }

    public function query($nameserver, Query $query)
    {
        $request = $this->prepareRequest($query);

        $queryData = $this->dumper->toBinary($request);

        // Allowed to guess the best scheme:
        if (false === strpos($nameserver, '://') || 0 === strpos($nameserver, 'dummy://')) {
            $scheme = strlen($queryData) > 512 ? 'tcp' : 'udp';
            $nameserver = str_replace('dummy://', null, $nameserver);
            $nameserver = "{$scheme}://{$nameserver}";
            $keepScheme = false;
        }

        // Do not allow scheme changes:
        else {
            $keepScheme = true;
        }

        return $this->doQuery($nameserver, $keepScheme, $queryData, $query->name);
    }

    public function prepareRequest(Query $query)
    {
        $request = new Message();
        $request->header->set('id', $this->generateId());
        $request->header->set('rd', 1);
        $request->questions[] = (array) $query;
        $request->prepare();

        return $request;
    }

    public function doQuery($nameserver, $keepScheme, $queryData, $name)
    {
        $parser = $this->parser;
        $loop = $this->loop;

        $response = new Message();
        $deferred = new Deferred();

        $handleTruncated = function() use ($nameserver, $keepScheme, $queryData, $name, $deferred) {
            // Cannot change the scheme to TCP:
            if ($keepScheme && 0 === strpos($nameserver, 'udp://')) {
                $deferred->reject(new BadServerException('The server set the truncated bit but we could not retry using TCP because UDP was specified'));
            }

            // Scheme is already TCP:
            else if (0 === strpos($nameserver, 'tcp://')) {
                $deferred->reject(new BadServerException('The server set the truncated bit although we issued a TCP request'));
            }

            // Try using TCP:
            else {
                $nameserver = 'tcp' . substr($nameserver, strpos($nameserver, '://'));
                $deferred->resolve($this->doQuery($nameserver, true, $queryData, $name));
            }
        };

        $timer = $this->loop->addTimer($this->timeout, function () use (&$conn, $name, $deferred) {
            $conn->close();
            $deferred->reject(new TimeoutException(sprintf("DNS query for %s timed out", $name)));
        });

        $conn = $this->createConnection($nameserver);
        $conn->on('data', function ($data) use ($handleTruncated, $conn, $parser, $response, $deferred, $timer) {
            $responseReady = $parser->parseChunk($data, $response);

            if (!$responseReady) {
                return;
            }

            $timer->cancel();
            $conn->end();

            if ($response->header->isTruncated()) {
                $handleTruncated($conn);

                return;
            }

            $deferred->resolve($response);
        });
        $conn->write($queryData);

        return $deferred->promise();
    }

    protected function generateId()
    {
        return mt_rand(0, 0xffff);
    }

    protected function createConnection($nameserver)
    {
        $fd = stream_socket_client($nameserver);
        $conn = new Connection($fd, $this->loop);

        return $conn;
    }
}
