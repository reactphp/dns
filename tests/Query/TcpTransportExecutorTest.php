<?php

namespace React\Tests\Dns\Query;

use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\Dns\Query\Query;
use React\Dns\Query\TcpTransportExecutor;
use React\EventLoop\Factory;
use React\Tests\Dns\TestCase;

class TcpTransportExecutorTest extends TestCase
{
    /**
     * @dataProvider provideDefaultPortProvider
     * @param string $input
     * @param string $expected
     */
    public function testCtorShouldAcceptNameserverAddresses($input, $expected)
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $executor = new TcpTransportExecutor($input, $loop);

        $ref = new \ReflectionProperty($executor, 'nameserver');
        $ref->setAccessible(true);
        $value = $ref->getValue($executor);

        $this->assertEquals($expected, $value);
    }

    public static function provideDefaultPortProvider()
    {
        return array(
            array('8.8.8.8',        '8.8.8.8:53'),
            array('1.2.3.4:5',      '1.2.3.4:5'),
            array('::1',            '[::1]:53'),
            array('[::1]:53',       '[::1]:53')
        );
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorShouldThrowWhenNameserverAddressIsInvalid()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        new TcpTransportExecutor('///', $loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorShouldThrowWhenNameserverAddressContainsHostname()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        new TcpTransportExecutor('localhost', $loop);
    }

    public function testQueryRejectsIfMessageExceedsMaximumMessageSize()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $executor = new TcpTransportExecutor('8.8.8.8:53', $loop);

        $query = new Query('google.' . str_repeat('.com', 60000), Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryRejectsIfServerConnectionFails()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $executor = new TcpTransportExecutor('::1', $loop);

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
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeReadStream');

        $executor = new TcpTransportExecutor('8.8.8.8:53', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);
        $promise->cancel();

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryRejectsWhenServerIsNotListening()
    {
        $loop = Factory::create();

        $executor = new TcpTransportExecutor('127.0.0.1:1', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.01, $loop);
        if ($wait) {
            \Clue\React\Block\sleep(0.2, $loop);
        }

        $this->assertFalse($wait);
    }

    public function testQueryRejectsWhenClientCanNotSendExcessiveMessageInOneChunk()
    {
        $writableCallback = null;
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream')->with($this->anything(), $this->callback(function ($cb) use (&$writableCallback) {
            $writableCallback = $cb;
            return true;
        }));
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('removeReadStream');

        $server = stream_socket_server('tcp://127.0.0.1:0');

        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.' . str_repeat('.com', 1000), Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        // create new dummy socket and fill its outgoing write buffer
        $socket = stream_socket_client($address);
        stream_set_blocking($socket, false);
        @fwrite($socket, str_repeat('.', 10000000));

        // then manually invoke writable handler with dummy socket
        $this->assertNotNull($writableCallback);
        $writableCallback($socket);

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryRejectsWhenServerClosesConnection()
    {
        $loop = Factory::create();

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $loop->addReadStream($server, function ($server) use ($loop) {
            $client = stream_socket_accept($server);
            fclose($client);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.01, $loop);
        if ($wait) {
            \Clue\React\Block\sleep(0.2, $loop);
        }

        $this->assertFalse($wait);
    }

    public function testQueryKeepsPendingIfServerSendsIncompleteMessageLength()
    {
        $loop = Factory::create();

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $loop->addReadStream($server, function ($server) use ($loop) {
            $client = stream_socket_accept($server);
            $loop->addReadStream($client, function ($client) use ($loop) {
                $loop->removeReadStream($client);
                fwrite($client, "\x00");
            });

            // keep reference to client to avoid disconnecting
            $loop->addTimer(1, function () use ($client) { });
        });

        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.2, $loop);
        $this->assertTrue($wait);
    }

    public function testQueryKeepsPendingIfServerSendsIncompleteMessageBody()
    {
        $loop = Factory::create();

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $loop->addReadStream($server, function ($server) use ($loop) {
            $client = stream_socket_accept($server);
            $loop->addReadStream($client, function ($client) use ($loop) {
                $loop->removeReadStream($client);
                fwrite($client, "\x00\xff" . "some incomplete message data");
            });

            // keep reference to client to avoid disconnecting
            $loop->addTimer(1, function () use ($client) { });
        });

        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.2, $loop);
        $this->assertTrue($wait);
    }

    public function testQueryRejectsWhenServerSendsInvalidMessage()
    {
        $loop = Factory::create();

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $loop->addReadStream($server, function ($server) use ($loop) {
            $client = stream_socket_accept($server);
            $loop->addReadStream($client, function ($client) use ($loop) {
                $loop->removeReadStream($client);
                fwrite($client, "\x00\x0f" . 'invalid message');
            });
        });

        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.01, $loop);
        if ($wait) {
            \Clue\React\Block\sleep(0.2, $loop);
        }

        $this->assertFalse($wait);
    }

    public function testQueryRejectsWhenServerSendsInvalidId()
    {
        $parser = new Parser();
        $dumper = new BinaryDumper();

        $loop = Factory::create();

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $loop->addReadStream($server, function ($server) use ($loop, $parser, $dumper) {
            $client = stream_socket_accept($server);
            $loop->addReadStream($client, function ($client) use ($loop, $parser, $dumper) {
                $loop->removeReadStream($client);
                $data = fread($client, 512);

                list(, $length) = unpack('n', substr($data, 0, 2));
                assert(strlen($data) - 2 === $length);
                $data = substr($data, 2);

                $message = $parser->parseMessage($data);
                $message->id = 0;

                $data = $dumper->toBinary($message);
                $data = pack('n', strlen($data)) . $data;

                fwrite($client, $data);
            });
        });

        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.01, $loop);
        if ($wait) {
            \Clue\React\Block\sleep(0.2, $loop);
        }

        $this->assertFalse($wait);
    }

    public function testQueryRejectsIfServerSendsTruncatedResponse()
    {
        $parser = new Parser();
        $dumper = new BinaryDumper();

        $loop = Factory::create();

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $loop->addReadStream($server, function ($server) use ($loop, $parser, $dumper) {
            $client = stream_socket_accept($server);
            $loop->addReadStream($client, function ($client) use ($loop, $parser, $dumper) {
                $loop->removeReadStream($client);
                $data = fread($client, 512);

                list(, $length) = unpack('n', substr($data, 0, 2));
                assert(strlen($data) - 2 === $length);
                $data = substr($data, 2);

                $message = $parser->parseMessage($data);
                $message->tc = true;

                $data = $dumper->toBinary($message);
                $data = pack('n', strlen($data)) . $data;

                fwrite($client, $data);
            });
        });

        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        \Clue\React\Block\sleep(0.01, $loop);
        if ($wait) {
            \Clue\React\Block\sleep(0.2, $loop);
        }

        $this->assertFalse($wait);
    }

    public function testQueryResolvesIfServerSendsValidResponse()
    {
        $loop = Factory::create();

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $loop->addReadStream($server, function ($server) use ($loop) {
            $client = stream_socket_accept($server);
            $loop->addReadStream($client, function ($client) use ($loop) {
                $loop->removeReadStream($client);
                $data = fread($client, 512);

                list(, $length) = unpack('n', substr($data, 0, 2));
                assert(strlen($data) - 2 === $length);

                fwrite($client, $data);
            });
        });

        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $response = \Clue\React\Block\await($promise, $loop, 0.2);

        $this->assertInstanceOf('React\Dns\Model\Message', $response);
    }
}
