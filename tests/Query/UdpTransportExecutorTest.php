<?php

namespace React\Tests\Dns\Query;

use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\Dns\Query\Query;
use React\Dns\Query\UdpTransportExecutor;
use React\EventLoop\Factory;
use React\Tests\Dns\TestCase;

class UdpTransportExecutorTest extends TestCase
{
    public function testQueryRejectsIfMessageExceedsUdpSize()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $dumper = $this->getMockBuilder('React\Dns\Protocol\BinaryDumper')->getMock();
        $dumper->expects($this->once())->method('toBinary')->willReturn(str_repeat('.', 513));

        $executor = new UdpTransportExecutor($loop, null, $dumper);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query('8.8.8.8:53', $query);

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryRejectsIfServerConnectionFails()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $executor = new UdpTransportExecutor($loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query('///', $query);

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());
    }

    /**
     * @group internet
     */
    public function testQueryRejectsOnCancellation()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeReadStream');

        $executor = new UdpTransportExecutor($loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query('8.8.8.8:53', $query);
        $promise->cancel();

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryKeepsPendingIfServerRejectsNetworkPacket()
    {
        $loop = Factory::create();

        $executor = new UdpTransportExecutor($loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $promise = $executor->query('127.0.0.1:1', $query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.2, $loop);
        $this->assertTrue($wait);
    }

    public function testQueryKeepsPendingIfServerSendInvalidMessage()
    {
        $loop = Factory::create();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $loop->addReadStream($server, function ($server) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);
            stream_socket_sendto($server, 'invalid', 0, $peer);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new UdpTransportExecutor($loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $promise = $executor->query($address, $query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.2, $loop);
        $this->assertTrue($wait);
    }

    public function testQueryKeepsPendingIfServerSendInvalidId()
    {
        $parser = new Parser();
        $dumper = new BinaryDumper();

        $loop = Factory::create();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $loop->addReadStream($server, function ($server) use ($parser, $dumper) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);

            $message = $parser->parseMessage($data);
            $message->header->set('id', 0);

            stream_socket_sendto($server, $dumper->toBinary($message), 0, $peer);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new UdpTransportExecutor($loop, $parser, $dumper);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $promise = $executor->query($address, $query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.2, $loop);
        $this->assertTrue($wait);
    }

    public function testQueryRejectsIfServerSendsTruncatedResponse()
    {
        $parser = new Parser();
        $dumper = new BinaryDumper();

        $loop = Factory::create();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $loop->addReadStream($server, function ($server) use ($parser, $dumper) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);

            $message = $parser->parseMessage($data);
            $message->header->set('tc', 1);

            stream_socket_sendto($server, $dumper->toBinary($message), 0, $peer);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new UdpTransportExecutor($loop, $parser, $dumper);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $promise = $executor->query($address, $query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        // run loop for short period to ensure we detect connection ICMP rejection error
        \Clue\React\Block\sleep(0.01, $loop);
        if ($wait) {
            \Clue\React\Block\sleep(0.2, $loop);
        }

        $this->assertFalse($wait);
    }

    public function testQueryResolvesIfServerSendsValidResponse()
    {
        $parser = new Parser();
        $dumper = new BinaryDumper();

        $loop = Factory::create();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $loop->addReadStream($server, function ($server) use ($parser, $dumper) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);

            $message = $parser->parseMessage($data);

            stream_socket_sendto($server, $dumper->toBinary($message), 0, $peer);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new UdpTransportExecutor($loop, $parser, $dumper);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($address, $query);
        $response = \Clue\React\Block\await($promise, $loop, 0.2);

        $this->assertInstanceOf('React\Dns\Model\Message', $response);
    }
}
