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
    /**
     * @dataProvider provideDefaultPortProvider
     * @param string $input
     * @param string $expected
     */
    public function testCtorShouldAcceptNameserverAddresses($input, $expected)
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $executor = new UdpTransportExecutor($input, $loop);

        $ref = new \ReflectionProperty($executor, 'nameserver');
        $ref->setAccessible(true);
        $value = $ref->getValue($executor);

        $this->assertEquals($expected, $value);
    }

    public static function provideDefaultPortProvider()
    {
        return array(
            array(
                '8.8.8.8',
                'udp://8.8.8.8:53'
            ),
            array(
                '1.2.3.4:5',
                'udp://1.2.3.4:5'
            ),
            array(
                'udp://1.2.3.4',
                'udp://1.2.3.4:53'
            ),
            array(
                'udp://1.2.3.4:53',
                'udp://1.2.3.4:53'
            ),
            array(
                '::1',
                'udp://[::1]:53'
            ),
            array(
                '[::1]:53',
                'udp://[::1]:53'
            )
        );
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorShouldThrowWhenNameserverAddressIsInvalid()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        new UdpTransportExecutor('///', $loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorShouldThrowWhenNameserverAddressContainsHostname()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        new UdpTransportExecutor('localhost', $loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorShouldThrowWhenNameserverSchemeIsInvalid()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        new UdpTransportExecutor('tcp://1.2.3.4', $loop);
    }

    public function testQueryRejectsIfMessageExceedsUdpSize()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $executor = new UdpTransportExecutor('8.8.8.8:53', $loop);

        $query = new Query('google.' . str_repeat('.com', 200), Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        $this->setExpectedException('RuntimeException', '', defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90);
        throw $exception;
    }

    public function testQueryRejectsIfServerConnectionFails()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $executor = new UdpTransportExecutor('::1', $loop);

        $ref = new \ReflectionProperty($executor, 'nameserver');
        $ref->setAccessible(true);
        $ref->setValue($executor, '///');

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);

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

        $executor = new UdpTransportExecutor('8.8.8.8:53', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);
        $promise->cancel();

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryKeepsPendingIfServerRejectsNetworkPacket()
    {
        $loop = Factory::create();

        $executor = new UdpTransportExecutor('127.0.0.1:1', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $promise = $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.2, $loop);
        $this->assertTrue($wait);
    }

    public function testQueryKeepsPendingIfServerSendsInvalidMessage()
    {
        $loop = Factory::create();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $loop->addReadStream($server, function ($server) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);
            stream_socket_sendto($server, 'invalid', 0, $peer);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new UdpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $promise = $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.2, $loop);
        $this->assertTrue($wait);
    }

    public function testQueryKeepsPendingIfServerSendsInvalidId()
    {
        $parser = new Parser();
        $dumper = new BinaryDumper();

        $loop = Factory::create();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $loop->addReadStream($server, function ($server) use ($parser, $dumper) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);

            $message = $parser->parseMessage($data);
            $message->id = 0;

            stream_socket_sendto($server, $dumper->toBinary($message), 0, $peer);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new UdpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $promise = $executor->query($query)->then(
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
            $message->tc = true;

            stream_socket_sendto($server, $dumper->toBinary($message), 0, $peer);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new UdpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        $this->setExpectedException('RuntimeException', '', defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90);
        \Clue\React\Block\await($promise, $loop, 0.1);
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
        $executor = new UdpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $response = \Clue\React\Block\await($promise, $loop, 0.2);

        $this->assertInstanceOf('React\Dns\Model\Message', $response);
    }
}
