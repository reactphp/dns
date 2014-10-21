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
        $query->nameserver = $nameserver;
        $request = $this->prepareRequest($query);
        $queryData = $this->dumper->toBinary($request);
        $query->attempts++;

        if ($query->transport == 'udp' && strlen($queryData) > 512) {
            $query->transport = $request->transport = 'tcp';
            $queryData = $this->dumper->toBinary($request);
        }

        return $this->doQuery($nameserver, $query, $queryData);
    }

    public function prepareRequest(Query $query)
    {
        $request = new Message();
        $request->transport = $query->transport;
        $request->nameserver = $query->nameserver;
        $request->header->set('id', $this->generateId());
        $request->header->set('rd', 1);
        $request->questions[] = $query;
        $request->prepare();

        return $request;
    }

    public function doQuery($nameserver, Query $query, $queryData)
    {
        $transport = $query->transport;
        $name = $query->name;
        $parser = $this->parser;
        $deferred = new Deferred();
        $response = new Message();
        $response->transport = $transport;
        $response->nameserver = $query->nameserver;

        $retryWithTcp = function () use ($nameserver, $query, $queryData) {
            $query->transport = 'tcp';
            return $this->doQuery($nameserver, $query, $queryData);
        };

        $timer = $this->loop->addTimer($this->timeout, function () use (&$conn, $name, $deferred) {
            $conn->close();
            $deferred->reject(new TimeoutException(sprintf("DNS query for %s timed out", $name)));
        });

        $conn = $this->createConnection($nameserver, $transport);
        $conn->on('data', function ($data) use ($retryWithTcp, $conn, $parser, $response, $deferred, $timer) {
            $response->meta->markEndTime();
            $responseReady = $parser->parseChunk($data, $response);

            if (!$responseReady) {
                return;
            }

            $timer->cancel();

            if ($response->header->isTruncated()) {
                if ('tcp' === $response->transport) {
                    $deferred->reject(new BadServerException('The server set the truncated bit although we issued a TCP request'));
                } else {
                    $conn->end();
                    $deferred->resolve($retryWithTcp());
                }

                return;
            }

            $conn->end();
            $deferred->resolve($response);
        });
        $conn->write($queryData);

        return $deferred->promise();
    }

    protected function generateId()
    {
        return mt_rand(0, 0xffff);
    }

    protected function createConnection($nameserver, $transport)
    {
        $fd = stream_socket_client("$transport://$nameserver", $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        stream_set_blocking($fd, 0);
        $conn = new Connection($fd, $this->loop);

        return $conn;
    }
}
