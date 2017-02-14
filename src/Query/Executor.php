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

    /**
     *
     * Note that albeit supported, the $timeout parameter is deprecated!
     * You should pass a `null` value here instead. If you need timeout handling,
     * use the `TimeoutConnector` instead.
     *
     * @param LoopInterface $loop
     * @param Parser $parser
     * @param BinaryDumper $dumper
     * @param null|float $timeout DEPRECATED: timeout for DNS query or NULL=no timeout
     */
    public function __construct(LoopInterface $loop, Parser $parser, BinaryDumper $dumper, $timeout = 5)
    {
        $this->loop = $loop;
        $this->parser = $parser;
        $this->dumper = $dumper;
        $this->timeout = $timeout;
    }

    public function query($nameserver, Query $query)
    {
        $request = Message::createRequestForQuery($query);

        $queryData = $this->dumper->toBinary($request);
        $transport = strlen($queryData) > 512 ? 'tcp' : 'udp';

        return $this->doQuery($nameserver, $transport, $queryData, $query->name);
    }

    /**
     * @deprecated unused, exists for BC only
     */
    public function prepareRequest(Query $query)
    {
        return Message::createRequestForQuery($query);
    }

    public function doQuery($nameserver, $transport, $queryData, $name)
    {
        $that = $this;
        $parser = $this->parser;
        $loop = $this->loop;

        $deferred = new Deferred(function ($resolve, $reject) use (&$timer, &$conn, $name) {
            $reject(new CancellationException(sprintf('DNS query for %s has been cancelled', $name)));

            if ($timer !== null) {
                $timer->cancel();
            }
            $conn->close();
        });

        $retryWithTcp = function () use ($that, $nameserver, $queryData, $name) {
            return $that->doQuery($nameserver, 'tcp', $queryData, $name);
        };

        $timer = null;
        if ($this->timeout !== null) {
            $timer = $this->loop->addTimer($this->timeout, function () use (&$conn, $name, $deferred) {
                $conn->close();
                $deferred->reject(new TimeoutException(sprintf("DNS query for %s timed out", $name)));
            });
        }

        try {
            try {
                $conn = $this->createConnection($nameserver, $transport);
            } catch (\Exception $e) {
                if ($transport === 'udp') {
                    // UDP failed => retry with TCP
                    $transport = 'tcp';
                    $conn = $this->createConnection($nameserver, $transport);
                } else {
                    // TCP failed (UDP must already have been checked before)
                    throw $e;
                }
            }
        } catch (\Exception $e) {
            // both UDP and TCP failed => reject
            if ($timer !== null) {
                $timer->cancel();
            }
            $deferred->reject(new \RuntimeException('Unable to connect to DNS server: ' . $e->getMessage(), 0, $e));

            return $deferred->promise();
        }

        $conn->on('data', function ($data) use ($retryWithTcp, $conn, $parser, $transport, $deferred, $timer) {
            if ($timer !== null) {
                $timer->cancel();
            }

            try {
                $response = $parser->parseMessage($data);
            } catch (\Exception $e) {
                $conn->end();
                $deferred->reject($e);
                return;
            }

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
        $conn->write($queryData);

        return $deferred->promise();
    }

    /**
     * @deprecated unused, exists for BC only
     */
    protected function generateId()
    {
        return mt_rand(0, 0xffff);
    }

    protected function createConnection($nameserver, $transport)
    {
        $fd = @stream_socket_client("$transport://$nameserver", $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        $conn = new Connection($fd, $this->loop);
        $conn->bufferSize = null; // Temporary fix for Windows 10 users

        return $conn;
    }
}
