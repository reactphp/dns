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
            array(
                '8.8.8.8',
                'tcp://8.8.8.8:53'
            ),
            array(
                '1.2.3.4:5',
                'tcp://1.2.3.4:5'
            ),
            array(
                'tcp://1.2.3.4',
                'tcp://1.2.3.4:53'
            ),
            array(
                'tcp://1.2.3.4:53',
                'tcp://1.2.3.4:53'
            ),
            array(
                '::1',
                'tcp://[::1]:53'
            ),
            array(
                '[::1]:53',
                'tcp://[::1]:53'
            )
        );
    }

    public function testCtorWithoutLoopShouldAssignDefaultLoop()
    {
        $executor = new TcpTransportExecutor('127.0.0.1');

        $ref = new \ReflectionProperty($executor, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($executor);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);
    }

    public function testCtorShouldThrowWhenNameserverAddressIsInvalid()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $this->setExpectedException('InvalidArgumentException');
        new TcpTransportExecutor('///', $loop);
    }

    public function testCtorShouldThrowWhenNameserverAddressContainsHostname()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $this->setExpectedException('InvalidArgumentException');
        new TcpTransportExecutor('localhost', $loop);
    }

    public function testCtorShouldThrowWhenNameserverSchemeIsInvalid()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $this->setExpectedException('InvalidArgumentException');
        new TcpTransportExecutor('udp://1.2.3.4', $loop);
    }

    public function testQueryRejectsIfMessageExceedsMaximumMessageSize()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addWriteStream');

        $executor = new TcpTransportExecutor('8.8.8.8:53', $loop);

        $query = new Query('google.' . str_repeat('.com', 60000), Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('DNS query for '. $query->name . ' (A) failed: Query too large for TCP transport', $exception->getMessage());
    }

    public function testQueryRejectsIfServerConnectionFails()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('HHVM reports different error message for invalid addresses');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addWriteStream');

        $executor = new TcpTransportExecutor('::1', $loop);

        $ref = new \ReflectionProperty($executor, 'nameserver');
        $ref->setAccessible(true);
        $ref->setValue($executor, '///');

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Unable to connect to DNS server /// (Failed to parse address "///")', $exception->getMessage());
    }

    public function testQueryRejectsOnCancellationWithoutClosingSocketButStartsIdleTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->never())->method('removeWriteStream');
        $loop->expects($this->never())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);

        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);
        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        /** @var \React\Dns\Query\CancellationException $exception */
        $this->assertInstanceOf('React\Dns\Query\CancellationException', $exception);
        $this->assertEquals('DNS query for google.com (A) has been cancelled', $exception->getMessage());
    }

    public function testTriggerIdleTimerAfterQueryRejectedOnCancellationWillCloseSocket()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->never())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $timerCallback = null;
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->callback(function ($cb) use (&$timerCallback) {
            $timerCallback = $cb;
            return true;
        }))->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);

        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);
        $promise->cancel();

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
        $promise->then(null, $this->expectCallableOnce());

        // trigger idle timer
        $this->assertNotNull($timerCallback);
        $timerCallback();
    }

    public function testQueryRejectsOnCancellationWithoutClosingSocketAndWithoutStartingIdleTimerWhenOtherQueryIsStillPending()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->never())->method('removeWriteStream');
        $loop->expects($this->never())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');
        $loop->expects($this->never())->method('cancelTimer');

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);

        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise1 = $executor->query($query);
        $promise2 = $executor->query($query);
        $promise2->cancel();

        $promise1->then($this->expectCallableNever(), $this->expectCallableNever());
        $promise2->then(null, $this->expectCallableOnce());
    }

    public function testQueryAgainAfterPreviousWasCancelledReusesExistingSocket()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->never())->method('removeWriteStream');
        $loop->expects($this->never())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);

        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);
        $promise->cancel();

        $executor->query($query);
    }

    public function testQueryRejectsWhenServerIsNotListening()
    {
        $loop = Factory::create();

        $executor = new TcpTransportExecutor('127.0.0.1:1', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        \Clue\React\Block\sleep(0.01, $loop);
        if ($exception === null) {
            \Clue\React\Block\sleep(0.2, $loop);
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Unable to connect to DNS server tcp://127.0.0.1:1 (Connection refused)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111, $exception->getCode());
    }

    public function testQueryStaysPendingWhenClientCanNotSendExcessiveMessageInOneChunk()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeWriteStream');
        $loop->expects($this->never())->method('removeReadStream');

        $server = stream_socket_server('tcp://127.0.0.1:0');

        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google' . str_repeat('.com', 100), Message::TYPE_A, Message::CLASS_IN);

        // send a bunch of queries and keep reference to last promise
        for ($i = 0; $i < 8000; ++$i) {
            $promise = $executor->query($query);
        }

        $client = stream_socket_accept($server);
        assert(is_resource($client));

        $executor->handleWritable();

        $promise->then(null, 'printf');
        $promise->then($this->expectCallableNever(), $this->expectCallableNever());

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        $writePending = $ref->getValue($executor);

        $this->assertTrue($writePending);
    }

    public function testQueryStaysPendingWhenClientCanNotSendExcessiveMessageInOneChunkWhenServerClosesSocket()
    {
        if (PHP_OS === 'Darwin') {
            // Skip on macOS because it exhibits what looks like a kernal race condition when sending excessive data to a socket that is about to shut down (EPROTOTYPE)
            // Due to this race condition, this is somewhat flaky. Happens around 75% of the time, use `--repeat=100` to reproduce.
            // fwrite(): Send of 4260000 bytes failed with errno=41 Protocol wrong type for socket
            // @link http://erickt.github.io/blog/2014/11/19/adventures-in-debugging-a-potential-osx-kernel-bug/
            $this->markTestSkipped('Skipped on macOS due to possible race condition');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeWriteStream');
        $loop->expects($this->never())->method('removeReadStream');

        $server = stream_socket_server('tcp://127.0.0.1:0');

        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google' . str_repeat('.com', 100), Message::TYPE_A, Message::CLASS_IN);

        // send a bunch of queries and keep reference to last promise
        for ($i = 0; $i < 2000; ++$i) {
            $promise = $executor->query($query);
        }

        $client = stream_socket_accept($server);
        fclose($client);

        $executor->handleWritable();

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        $writePending = $ref->getValue($executor);

        $this->assertTrue($writePending);
    }

    public function testQueryRejectsWhenClientKeepsSendingWhenServerClosesSocketWithoutCallingCustomErrorHandler()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('removeReadStream');

        $server = stream_socket_server('tcp://127.0.0.1:0');

        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google' . str_repeat('.com', 100), Message::TYPE_A, Message::CLASS_IN);

        // send a bunch of queries and keep reference to last promise
        for ($i = 0; $i < 2000; ++$i) {
            $promise = $executor->query($query);
        }

        $client = stream_socket_accept($server);
        fclose($client);

        $error = null;
        set_error_handler(function ($_, $errstr) use (&$error) {
            $error = $errstr;
        });

        $executor->handleWritable();

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        $writePending = $ref->getValue($executor);

        // We expect an EPIPE (Broken pipe) on second write.
        // However, macOS may report EPROTOTYPE (Protocol wrong type for socket) on first write due to kernel race condition.
        // fwrite(): Send of 4260000 bytes failed with errno=41 Protocol wrong type for socket
        // @link http://erickt.github.io/blog/2014/11/19/adventures-in-debugging-a-potential-osx-kernel-bug/
        if ($writePending) {
            $executor->handleWritable();
        }

        restore_error_handler();
        $this->assertNull($error);

        $exception = null;
        $promise->then($this->expectCallableNever(), function ($reason) use (&$exception) {
            $exception = $reason;
        });

        // expect EPIPE (Broken pipe), except for macOS kernel race condition or legacy HHVM
        $this->setExpectedException(
            'RuntimeException',
            'Unable to send query to DNS server tcp://' . $address . ' (',
            defined('SOCKET_EPIPE') && !defined('HHVM_VERSION') ? (PHP_OS !== 'Darwin' || $writePending ? SOCKET_EPIPE : SOCKET_EPROTOTYPE) : null
        );
        $this->assertNotNull($exception, 'Promise did not reject with an Exception');
        throw $exception;
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

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        \Clue\React\Block\sleep(0.01, $loop);
        if ($exception === null) {
            \Clue\React\Block\sleep(0.2, $loop);
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Connection to DNS server tcp://' . $address . ' lost', $exception->getMessage());
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

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        \Clue\React\Block\sleep(0.01, $loop);
        if ($exception === null) {
            \Clue\React\Block\sleep(0.2, $loop);
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Invalid message received from DNS server tcp://' . $address, $exception->getMessage());
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

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        \Clue\React\Block\sleep(0.01, $loop);
        if ($exception === null) {
            \Clue\React\Block\sleep(0.2, $loop);
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Invalid response message received from DNS server tcp://' . $address, $exception->getMessage());
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

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        \Clue\React\Block\sleep(0.01, $loop);
        if ($exception === null) {
            \Clue\React\Block\sleep(0.2, $loop);
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Invalid response message received from DNS server tcp://' . $address, $exception->getMessage());
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

    public function testQueryRejectsIfSocketIsClosedAfterPreviousQueryThatWasStillPending()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->exactly(2))->method('addWriteStream');
        $loop->expects($this->exactly(2))->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeReadStream');

        $loop->expects($this->never())->method('addTimer');
        $loop->expects($this->never())->method('cancelTimer');

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise1 = $executor->query($query);

        $client = stream_socket_accept($server);

        $executor->handleWritable();

        // close client socket before processing second write
        fclose($client);

        $promise2 = $executor->query($query);

        $executor->handleWritable();

        $promise1->then(null, $this->expectCallableOnce());
        $promise2->then(null, $this->expectCallableOnce());
    }

    public function testQueryResolvesIfServerSendsBackResponseMessageAndWillStartIdleTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything());
        $loop->expects($this->never())->method('cancelTimer');

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        $data = $ref->getValue($executor);

        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $executor->handleWritable();
        $executor->handleRead();

        $promise->then($this->expectCallableOnce());
    }

    public function testQueryResolvesIfServerSendsBackResponseMessageAfterCancellingQueryAndWillStartIdleTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $promise->cancel();

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        $data = $ref->getValue($executor);

        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $executor->handleWritable();
        $executor->handleRead();

        //$promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryResolvesIfServerSendsBackResponseMessageAfterCancellingOtherQueryAndWillStartIdleTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything());
        $loop->expects($this->never())->method('cancelTimer');

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        $data = $ref->getValue($executor);

        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $another = $executor->query($query);
        $another->cancel();

        $executor->handleWritable();
        $executor->handleRead();

        $promise->then($this->expectCallableOnce());
    }

    public function testTriggerIdleTimerAfterPreviousQueryResolvedWillCloseIdleSocketConnection()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeReadStream');

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $timerCallback = null;
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->callback(function ($cb) use (&$timerCallback) {
            $timerCallback = $cb;
            return true;
        }))->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        $data = $ref->getValue($executor);

        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $executor->handleWritable();
        $executor->handleRead();

        $promise->then($this->expectCallableOnce());

        // trigger idle timer
        $this->assertNotNull($timerCallback);
        $timerCallback();
    }

    public function testClosingConnectionAfterPreviousQueryResolvedWillCancelIdleTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeReadStream');

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        $data = $ref->getValue($executor);

        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $executor->handleWritable();
        $executor->handleRead();

        $promise->then($this->expectCallableOnce());

        // trigger connection close condition
        fclose($client);
        $executor->handleRead();
    }

    public function testQueryAgainAfterPreviousQueryResolvedWillReuseSocketAndCancelIdleTimer()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->exactly(2))->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        $data = $ref->getValue($executor);

        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $executor->handleWritable();
        $executor->handleRead();

        $promise->then($this->expectCallableOnce());

        // trigger second query
        $executor->query($query);
    }

    public function testQueryRejectsWhenTlsCannotBeEstablished()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = \stream_socket_server('tcp://127.0.0.1:0');
        $address = \stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor('tls://' . $address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the attempted TLS handshake
            $executor->handleWritable();
            @\stream_socket_accept($server,0);
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertContains($exception->getMessage(), array(
            'DNS query for google.com (A) failed: Connection lost during TLS handshake (ECONNRESET)',
            'DNS query for google.com (A) failed: SSL: Undefined error: 0',
        ));
    }

    public function testQueryRejectsWhenTlsClosedDuringHandshake()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = \stream_socket_server('tcp://127.0.0.1:0');
        $address = \stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor('tls://' . $address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the attempted TLS handshake
            $executor->handleWritable();
            $client = @\stream_socket_accept($server,0);
            if (false !== $client) {
                fclose($client);
            }
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertContains($exception->getMessage(), array(
            'DNS query for google.com (A) failed: Connection lost during TLS handshake (ECONNRESET)',
            'DNS query for google.com (A) failed: SSL: Undefined error: 0',
        ));
    }

    public function testQueryRejectsWhenTlsCertificateVerificationFails()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        // Connect to self-signed.badssl.com https://github.com/chromium/badssl.com
        $executor = new TcpTransportExecutor('tls://104.154.89.105:443', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertStringStartsWith('DNS query for google.com (A) failed: SSL operation failed with code ', $exception->getMessage());
        if (method_exists($this, 'assertStringContainsString')) {
            $this->assertStringContainsString('certificate verify failed', $exception->getMessage());
        } else {
            $this->assertContains('certificate verify failed', $exception->getMessage());
        }
    }

    public function testCryptoEnabledAfterConnectingToTlsDnsServer()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $executor = new TcpTransportExecutor('tls://8.8.8.8', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $executor->query($query);

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }

        $ref = new \ReflectionProperty($executor, 'cryptoEnabled');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue($executor));
    }

    public function testCryptoEnabledWithPeerFingerprintMatch()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        //1.1.1.1 used here. Google 8.8.8.8 uses two different certs so fingerprint match can fail
        $fingerprint = '099d03214d1414a5325db61090e73ddb94f37d72';
        $executor = new TcpTransportExecutor('tls://1.1.1.1?ssl[peer_fingerprint]=' . $fingerprint, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $executor->query($query);

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }

        $ref = new \ReflectionProperty($executor, 'cryptoEnabled');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue($executor));
    }

    public function testCryptoFailureWithPeerFingerprintMismatch()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $invalid_fingerprint = sha1('invalid');
        $executor = new TcpTransportExecutor('tls://8.8.8.8?ssl[peer_fingerprint]=' . $invalid_fingerprint, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }

        $ref = new \ReflectionProperty($executor, 'cryptoEnabled');
        $ref->setAccessible(true);
        $this->assertFalse($ref->getValue($executor));

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('DNS query for google.com (A) failed: peer_fingerprint match failure', $exception->getMessage());
    }

    public function testCryptoEnabledWithPeerNameVerified()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $executor = new TcpTransportExecutor('tls://8.8.8.8?ssl[peer_name]=dns.google', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $executor->query($query);

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }

        $ref = new \ReflectionProperty($executor, 'cryptoEnabled');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue($executor));
    }

    public function testCryptoFailureWithPeerNameVerified()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $executor = new TcpTransportExecutor('tls://8.8.8.8?ssl[peer_name]=notgoogle', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }

        $ref = new \ReflectionProperty($executor, 'cryptoEnabled');
        $ref->setAccessible(true);
        $this->assertFalse($ref->getValue($executor));

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Peer certificate CN=`dns.google\' did not match expected CN=`notgoogle\'', $exception->getMessage());
    }
}
