<?php

namespace React\Tests\Dns\Query;

use React\Tests\Dns\TestCase;
use React\Dns\Query\DatagramTransportExecutor;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\EventLoop\Factory;

class DatagramTransportExecutorTest extends TestCase
{
    public function testQueryRejectsIfMessageExceedsUdpSize()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $dumper = $this->getMockBuilder('React\Dns\Protocol\BinaryDumper')->getMock();
        $dumper->expects($this->once())->method('toBinary')->willReturn(str_repeat('.', 513));

        $executor = new DatagramTransportExecutor($loop, null, $dumper);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query('8.8.8.8:53', $query);

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryRejectsIfServerConnectionFails()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $executor = new DatagramTransportExecutor($loop);

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

        $executor = new DatagramTransportExecutor($loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query('8.8.8.8:53', $query);
        $promise->cancel();

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryRejectsIfServerRejectsNetworkPacket()
    {
        $loop = Factory::create();

        $executor = new DatagramTransportExecutor($loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $promise = $executor->query('127.0.0.1:1', $query)->then(
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
            if ($wait) {
                $this->markTestSkipped('Did not receive an error (your OS may drop UDP packets to unbound port?)');
            }
        }

        $this->assertFalse($wait);
    }

    public function testQueryRejectsIfServerSendInvalidMessage()
    {
        $loop = Factory::create();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $loop->addReadStream($server, function ($server) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);
            stream_socket_sendto($server, 'invalid', 0, $peer);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new DatagramTransportExecutor($loop);

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

    public function testQueryRejectsIfServerSendsTruncatedResponse()
    {
        $response = new Message();
        $response->header->set('tc', 1);

        $parser = $this->getMockBuilder('React\Dns\Protocol\Parser')->getMock();
        $parser->expects($this->once())->method('parseMessage')->with('data')->willReturn($response);

        $loop = Factory::create();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $loop->addReadStream($server, function ($server) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);
            stream_socket_sendto($server, 'data', 0, $peer);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new DatagramTransportExecutor($loop, $parser);

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
        $response = new Message();

        $parser = $this->getMockBuilder('React\Dns\Protocol\Parser')->getMock();
        $parser->expects($this->once())->method('parseMessage')->with('data')->willReturn($response);

        $loop = Factory::create();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $loop->addReadStream($server, function ($server) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);
            stream_socket_sendto($server, 'data', 0, $peer);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new DatagramTransportExecutor($loop, $parser);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($address, $query);
        $response = \Clue\React\Block\await($promise, $loop, 0.2);

        $this->assertInstanceOf('React\Dns\Model\Message', $response);
    }
}
