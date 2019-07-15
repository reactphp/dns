<?php

use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\Dns\Query\MultiServerExecutor;
use React\Dns\Query\Query;
use React\Dns\Query\UdpTransportExecutor;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Tests\Dns\TestCase;

class MultiServerExecutorTest extends TestCase
{
    public $serverConnectCount = 0;
    public $serverWriteCount = 0;

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage No executors provided
     */
    public function testNoExecutorsSupplied()
    {
        $loop = Factory::create();

        new MultiServerExecutor(array(), $loop);
    }

    public function testQueryWillResolve()
    {
        $loop = Factory::create();

        $server = $this->createAnsweringServer($loop);
        $address = new UdpTransportExecutor(stream_socket_get_name($server, false), $loop);
        $executor = new MultiServerExecutor(array($address), $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $response = \Clue\React\Block\await($promise, $loop, 0.5);

        $this->assertInstanceOf('React\Dns\Model\Message', $response);
        $this->assertSame(1, $this->serverConnectCount);
        $this->assertSame(1, $this->serverWriteCount);
    }

    public function testQueryWillBeSendToAllServers()
    {
        $loop = Factory::create();

        $answeringServer = $this->createWaitingAnsweringServer($loop, 0.1);
        $waitingServer = $this->createWaitingAnsweringServer($loop, 1);
        $answeringAddress = new UdpTransportExecutor(stream_socket_get_name($answeringServer, false), $loop);
        $waitingAddress = new UdpTransportExecutor(stream_socket_get_name($waitingServer, false), $loop);
        $executor = new MultiServerExecutor(array($answeringAddress, $waitingAddress), $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $response = \Clue\React\Block\await($promise, $loop, 0.5);

        $this->assertInstanceOf('React\Dns\Model\Message', $response);
        $this->assertSame(2, $this->serverConnectCount);
        $this->assertSame(1, $this->serverWriteCount);
    }

    public function testQueryWillNotFailWhenOneResponseIsTruncated()
    {
        $loop = Factory::create();

        $servers = array();
        $addresses = array();
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.1, true);
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.2);
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.2);
        foreach ($servers as $server) {
            $addresses[] = new UdpTransportExecutor(stream_socket_get_name($server, false), $loop);
        }
        $executor = new MultiServerExecutor($addresses, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $response = \Clue\React\Block\await($promise, $loop, 0.5);

        $this->assertInstanceOf('React\Dns\Model\Message', $response);
        $this->assertSame(3, $this->serverConnectCount);
        $this->assertSame(2, $this->serverWriteCount);
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage DNS query for google.com failed: The server returned a truncated result for a UDP query, but retrying via TCP is currently not supported
     */
    public function testQueryWillFailWhenAllResponseAraTruncated()
    {
        $loop = Factory::create();

        $servers = array();
        $addresses = array();
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.1, true);
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.2, true);
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.3, true);
        foreach ($servers as $server) {
            $addresses[] = new UdpTransportExecutor(stream_socket_get_name($server, false), $loop);
        }
        $executor = new MultiServerExecutor($addresses, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $response = \Clue\React\Block\await($promise, $loop, 0.5);

        $this->assertInstanceOf('React\Dns\Model\Message', $response);
        $this->assertSame(2, $this->serverConnectCount);
        $this->assertSame(2, $this->serverWriteCount);
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Lookup query has been canceled
     */
    public function testCancelPromiseWillCancelAllPendingQueries()
    {
        $loop = Factory::create();

        $servers = array();
        $addresses = array();
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.1, true);
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.2, true);
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.3, true);
        foreach ($servers as $server) {
            $addresses[] = new UdpTransportExecutor(stream_socket_get_name($server, false), $loop);
        }
        $executor = new MultiServerExecutor($addresses, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $loop->futureTick(function () use ($promise) {
            $promise->cancel();
        });
        $response = \Clue\React\Block\await($promise, $loop, 0.5);

        $this->assertInstanceOf('React\Dns\Model\Message', $response);
        $this->assertSame(2, $this->serverConnectCount);
        $this->assertSame(2, $this->serverWriteCount);
    }

    public function testResolvingWilCancelActiveTimer()
    {
        $loop = Factory::create();

        $servers = array();
        $addresses = array();
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.0001);
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.2);
        $servers[] = $this->createWaitingAnsweringServer($loop, 0.3);
        foreach ($servers as $server) {
            $addresses[] = new UdpTransportExecutor(stream_socket_get_name($server, false), $loop);
        }
        $executor = new MultiServerExecutor($addresses, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $response = \Clue\React\Block\await($promise, $loop, 0.5);

        $this->assertInstanceOf('React\Dns\Model\Message', $response);
        $this->assertSame(1, $this->serverConnectCount);
        $this->assertSame(1, $this->serverWriteCount);
    }

    private function createAnsweringServer(LoopInterface $loop)
    {
        $that = $this;
        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $loop->addReadStream($server, function ($server) use ($that) {
            $that->serverConnectCount++;
            $parser = new Parser();
            $dumper = new BinaryDumper();

            $data = stream_socket_recvfrom($server, 512, 0, $peer);

            $message = $parser->parseMessage($data);

            stream_socket_sendto($server, $dumper->toBinary($message), 0, $peer);
            $that->serverWriteCount++;
        });

        return $server;
    }

    private function createWaitingAnsweringServer(LoopInterface $loop, $timerout, $truncated = false)
    {
        $that = $this;
        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $loop->addReadStream($server, function ($server) use ($loop, $timerout, $that, $truncated) {
            $that->serverConnectCount++;
            $parser = new Parser();

            $data = stream_socket_recvfrom($server, 512, 0, $peer);

            $message = $parser->parseMessage($data);
            $message->tc = $truncated;

            $loop->addTimer($timerout, function () use ($server, $message, $peer, $that) {
                $dumper = new BinaryDumper();

                stream_socket_sendto($server, $dumper->toBinary($message), 0, $peer);
                $that->serverWriteCount++;
            });
        });

        return $server;
    }
}
