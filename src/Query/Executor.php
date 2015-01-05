<?php

namespace React\Dns\Query;

use React\Dns\BadServerException;
use React\Dns\Model\Message;
use React\Dns\Protocol\Parser;
use React\Dns\Protocol\BinaryDumper;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\SocketClient\Connector;
use React\Datagram\Socket;

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
        $transport = strlen($queryData) > 512 ? 'tcp' : 'udp';

        return $this->doQuery($nameserver, $transport, $queryData, $query->name);
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

    public function doQuery($nameserver, $transport, $queryData, $name)
    {
        $parser = $this->parser;
        $loop = $this->loop;

        $response = new Message();
        $deferred = new Deferred();

        $retryWithTcp = function () use ($nameserver, $queryData, $name) {
            return $this->doQuery($nameserver, 'tcp', $queryData, $name);
        };

        $timer = $this->loop->addTimer($this->timeout, function () use (&$conn, $name, $deferred) {
            $conn->close();
            $deferred->reject(new TimeoutException(sprintf("DNS query for %s timed out", $name)));
        });

        $conn = $this->createConnection($nameserver, $transport);
        $conn->on('data', function ($data) use ($retryWithTcp, $conn, $parser, $response, $transport, $deferred, $timer) {
            $responseReady = $parser->parseChunk($data, $response);

            if (!$responseReady) {
                return;
            }

            $timer->cancel();

            if ($response->header->isTruncated()) {
                if ('tcp' === $transport) {
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

        if ('tcp' === $transport) {
            $conn->write($queryData);
        } else { // udp
            $conn->send($queryData);
        }

        return $deferred->promise();
    }

    protected function generateId()
    {
        return mt_rand(0, 0xffff);
    }

    protected function createConnection($nameserver, $transport)
    {
        if ('tcp' === $transport) {
            $uri = parse_url("$transport://$nameserver");
            // TCP client connector
            $connector = new Connector($this->loop, null);
            $conn = $connector->create($uri['host'], $uri['port']);
        }
        else { // udp
            $socket = stream_socket_client("$transport://$nameserver", $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            stream_set_blocking($socket, 0);

            if (!$socket) {
                throw new Exception('Unable to create client socket: ' . $errstr, $errno);
            }

            // UDP datagram socket
            $conn = new Socket($this->loop, $socket);
        }

        return $conn;
    }
}
