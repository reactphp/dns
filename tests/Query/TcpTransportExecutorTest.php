<?php

namespace React\Tests\Dns\Query;

use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\Dns\Query\CancellationException;
use React\Dns\Query\Query;
use React\Dns\Query\TcpTransportExecutor;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use React\Tests\Dns\TestCase;
use function React\Async\await;
use function React\Promise\Timer\sleep;
use function React\Promise\Timer\timeout;

class TcpTransportExecutorTest extends TestCase
{
    /**
     * @dataProvider provideDefaultPortProvider
     * @param string $input
     * @param string $expected
     */
    public function testCtorShouldAcceptNameserverAddresses(string $input, string $expected): void
    {
        $loop = $this->createMock(LoopInterface::class);

        $executor = new TcpTransportExecutor($input, $loop);

        $ref = new \ReflectionProperty($executor, 'nameserver');
        $ref->setAccessible(true);
        $value = $ref->getValue($executor);

        $this->assertEquals($expected, $value);
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function provideDefaultPortProvider(): iterable
    {
        yield [
            '8.8.8.8',
            'tcp://8.8.8.8:53'
        ];
        yield [
            '1.2.3.4:5',
            'tcp://1.2.3.4:5'
        ];
        yield [
            'tcp://1.2.3.4',
            'tcp://1.2.3.4:53'
        ];
        yield [
            'tcp://1.2.3.4:53',
            'tcp://1.2.3.4:53'
        ];
        yield [
            '::1',
            'tcp://[::1]:53'
        ];
        yield [
            '[::1]:53',
            'tcp://[::1]:53'
        ];
    }

    public function testCtorWithoutLoopShouldAssignDefaultLoop(): void
    {
        $executor = new TcpTransportExecutor('127.0.0.1');

        $ref = new \ReflectionProperty($executor, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($executor);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testCtorShouldThrowWhenNameserverAddressIsInvalid(): void
    {
        $loop = $this->createMock(LoopInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        new TcpTransportExecutor('///', $loop);
    }

    public function testCtorShouldThrowWhenNameserverAddressContainsHostname(): void
    {
        $loop = $this->createMock(LoopInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        new TcpTransportExecutor('localhost', $loop);
    }

    public function testCtorShouldThrowWhenNameserverSchemeIsInvalid(): void
    {
        $loop = $this->createMock(LoopInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        new TcpTransportExecutor('udp://1.2.3.4', $loop);
    }

    public function testQueryRejectsIfMessageExceedsMaximumMessageSize(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->never())->method('addWriteStream');

        $executor = new TcpTransportExecutor('8.8.8.8:53', $loop);

        $query = new Query('google.' . str_repeat('.com', 60000), Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for '. $query->name . ' (A) failed: Query too large for TCP transport', $exception->getMessage());
    }

    public function testQueryRejectsIfServerConnectionFails(): void
    {
        $loop = $this->createMock(LoopInterface::class);
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
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Unable to connect to DNS server /// (Failed to parse address "///")', $exception->getMessage());
    }

    public function testQueryRejectsOnCancellationWithoutClosingSocketButStartsIdleTimer(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->never())->method('removeWriteStream');
        $loop->expects($this->never())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $timer = $this->createMock(TimerInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        /** @var string $address */
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
        $this->assertInstanceOf(CancellationException::class, $exception);
        $this->assertEquals('DNS query for google.com (A) has been cancelled', $exception->getMessage());
    }

    public function testTriggerIdleTimerAfterQueryRejectedOnCancellationWillCloseSocket(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->never())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $timer = $this->createMock(TimerInterface::class);
        $timerCallback = null;
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->callback(function ($cb) use (&$timerCallback) {
            $timerCallback = $cb;
            return true;
        }))->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        /** @var string $address */
        $address = stream_socket_get_name($server, false);

        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);
        $promise->cancel();

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then(null, $this->expectCallableOnce());

        // trigger idle timer
        $this->assertNotNull($timerCallback);
        $timerCallback();
    }

    public function testQueryRejectsOnCancellationWithoutClosingSocketAndWithoutStartingIdleTimerWhenOtherQueryIsStillPending(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->never())->method('removeWriteStream');
        $loop->expects($this->never())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $loop->expects($this->never())->method('addTimer');
        $loop->expects($this->never())->method('cancelTimer');

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        /** @var string $address */
        $address = stream_socket_get_name($server, false);

        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise1 = $executor->query($query);
        $promise2 = $executor->query($query);
        $promise2->cancel();

        $promise1->then($this->expectCallableNever(), $this->expectCallableNever());
        $promise2->then(null, $this->expectCallableOnce());
    }

    public function testQueryAgainAfterPreviousWasCancelledReusesExistingSocket(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->never())->method('removeWriteStream');
        $loop->expects($this->never())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        /** @var string $address */
        $address = stream_socket_get_name($server, false);

        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);
        $promise->cancel();

        $executor->query($query);
    }

    public function testQueryRejectsWhenServerIsNotListening(): void
    {
        $executor = new TcpTransportExecutor('127.0.0.1:1');

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        await(sleep(0.01));
        if ($exception === null) {
            await(sleep(0.2));
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Unable to connect to DNS server tcp://127.0.0.1:1 (Connection refused)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111, $exception->getCode());
    }

    public function testQueryStaysPendingWhenClientCanNotSendExcessiveMessageInOneChunk(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeWriteStream');
        $loop->expects($this->never())->method('removeReadStream');

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');

        /** @var string $address */
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

        $promise->then(null, static function(\Throwable $error): void {
            echo $error;
        });
        $promise->then($this->expectCallableNever(), $this->expectCallableNever());

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        /** @var bool $writePending */
        $writePending = $ref->getValue($executor);

        $this->assertTrue($writePending);
    }

    public function testQueryStaysPendingWhenClientCanNotSendExcessiveMessageInOneChunkWhenServerClosesSocket(): void
    {
        if (PHP_OS === 'Darwin') {
            // Skip on macOS because it exhibits what looks like a kernal race condition when sending excessive data to a socket that is about to shut down (EPROTOTYPE)
            // Due to this race condition, this is somewhat flaky. Happens around 75% of the time, use `--repeat=100` to reproduce.
            // fwrite(): Send of 4260000 bytes failed with errno=41 Protocol wrong type for socket
            // @link http://erickt.github.io/blog/2014/11/19/adventures-in-debugging-a-potential-osx-kernel-bug/
            $this->markTestSkipped('Skipped on macOS due to possible race condition');
        }

        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeWriteStream');
        $loop->expects($this->never())->method('removeReadStream');

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');

        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google' . str_repeat('.com', 100), Message::TYPE_A, Message::CLASS_IN);

        // send a bunch of queries and keep reference to last promise
        for ($i = 0; $i < 2000; ++$i) {
            $promise = $executor->query($query);
        }

        /** @var resource $client */
        $client = stream_socket_accept($server);
        fclose($client);

        $executor->handleWritable();

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());

        $ref = new \ReflectionProperty($executor, 'writePending');
        $ref->setAccessible(true);
        /** @var bool $writePending */
        $writePending = $ref->getValue($executor);

        $this->assertTrue($writePending);
    }

    public function testQueryRejectsWhenClientKeepsSendingWhenServerClosesSocketWithoutCallingCustomErrorHandler(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('removeReadStream');

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');

        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google' . str_repeat('.com', 100), Message::TYPE_A, Message::CLASS_IN);

        // send a bunch of queries and keep reference to last promise
        $exception = null;
        for ($i = 0; $i < 2000; ++$i) {
            $promise = $executor->query($query);
            $promise->then(null, function (\Throwable $reason) use (&$exception): void {
                $exception = $reason;
            });
        }

        /** @var resource $client */
        $client = stream_socket_accept($server);
        fclose($client);

        $error = null;
        set_error_handler(function (int $_, string $errstr) use (&$error): bool {
            $error = $errstr;

            return true;
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

        // expect EPIPE (Broken pipe), except for macOS kernel race condition
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to send query to DNS server tcp://' . $address . ' (');
        $this->expectExceptionCode(defined('SOCKET_EPIPE') ? (PHP_OS !== 'Darwin' || $writePending ? SOCKET_EPIPE : SOCKET_EPROTOTYPE) : PHP_INT_MIN);
        $this->assertNotNull($exception);

        throw $exception;
    }

    public function testQueryRejectsWhenServerClosesConnection(): void
    {
        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        Loop::addReadStream($server, function ($server) {
            /** @var resource $client */
            $client = stream_socket_accept($server);
            fclose($client);

            Loop::removeReadStream($server);
            fclose($server);
        });

        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        await(sleep(0.01));
        if ($exception === null) {
            await(sleep(0.2));
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Connection to DNS server tcp://' . $address . ' lost', $exception->getMessage());
    }

    public function testQueryKeepsPendingIfServerSendsIncompleteMessageLength(): void
    {
        $client = null;
        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        Loop::addReadStream($server, function ($server) use (&$client) {
            /** @var resource $client */
            $client = stream_socket_accept($server);
            Loop::addReadStream($client, function ($client) {
                Loop::removeReadStream($client);
                fwrite($client, "\x00");
            });

            Loop::removeReadStream($server);
            fclose($server);
        });

        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        await(sleep(0.2));
        $this->assertTrue($wait);

        $this->assertNotNull($client);
        fclose($client);
        Loop::removeReadStream($client);
    }

    public function testQueryKeepsPendingIfServerSendsIncompleteMessageBody(): void
    {
        $client = null;
        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        Loop::addReadStream($server, function ($server) use (&$client) {
            /** @var resource $client */
            $client = stream_socket_accept($server);
            Loop::addReadStream($client, function ($client) {
                Loop::removeReadStream($client);
                fwrite($client, "\x00\xff" . "some incomplete message data");
            });

            Loop::removeReadStream($server);
            fclose($server);
        });

        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        await(sleep(0.2));
        $this->assertTrue($wait);

        $this->assertNotNull($client);
        fclose($client);
        Loop::removeReadStream($client);
    }

    public function testQueryRejectsWhenServerSendsInvalidMessage(): void
    {
        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        Loop::addReadStream($server, function ($server) {
            /** @var resource $client */
            $client = stream_socket_accept($server);
            Loop::addReadStream($client, function ($client) {
                Loop::removeReadStream($client);
                fwrite($client, "\x00\x0f" . 'invalid message');
            });

            Loop::removeReadStream($server);
            fclose($server);
        });

        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        await(sleep(0.01));
        if ($exception === null) {
            await(sleep(0.2));
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Invalid message received from DNS server tcp://' . $address, $exception->getMessage());
    }

    public function testQueryRejectsWhenServerSendsInvalidId(): void
    {
        $parser = new Parser();
        $dumper = new BinaryDumper();

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        Loop::addReadStream($server, function ($server) use ($parser, $dumper) {
            /** @var resource $client */
            $client = stream_socket_accept($server);
            Loop::addReadStream($client, function ($client) use ($parser, $dumper) {
                Loop::removeReadStream($client);
                /** @var string $data */
                $data = fread($client, 512);

                /** @phpstan-ignore-next-line unpack won't error on this line as our format is correct */
                list(, $length) = unpack('n', substr($data, 0, 2));
                assert(strlen($data) - 2 === $length);
                $data = substr($data, 2);

                $message = $parser->parseMessage($data);
                $message->id = 0;

                $data = $dumper->toBinary($message);
                $data = pack('n', strlen($data)) . $data;

                fwrite($client, $data);
            });

            Loop::removeReadStream($server);
            fclose($server);
        });

        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        await(sleep(0.01));
        if ($exception === null) {
            await(sleep(0.2));
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Invalid response message received from DNS server tcp://' . $address, $exception->getMessage());
    }

    public function testQueryRejectsIfServerSendsTruncatedResponse(): void
    {
        $parser = new Parser();
        $dumper = new BinaryDumper();

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        Loop::addReadStream($server, function ($server) use ($parser, $dumper) {
            /** @var resource $client */
            $client = stream_socket_accept($server);
            Loop::addReadStream($client, function ($client) use ($parser, $dumper) {
                Loop::removeReadStream($client);
                /** @var string $data */
                $data = fread($client, 512);

                /** @phpstan-ignore-next-line unpack won't error on this line as our format is correct */
                list(, $length) = unpack('n', substr($data, 0, 2));
                assert(strlen($data) - 2 === $length);
                $data = substr($data, 2);

                $message = $parser->parseMessage($data);
                $message->tc = true;

                $data = $dumper->toBinary($message);
                $data = pack('n', strlen($data)) . $data;

                fwrite($client, $data);
            });

            Loop::removeReadStream($server);
            fclose($server);
        });

        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        await(sleep(0.01));
        if ($exception === null) {
            await(sleep(0.2));
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Invalid response message received from DNS server tcp://' . $address, $exception->getMessage());
    }

    public function testQueryResolvesIfServerSendsValidResponse(): void
    {
        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        Loop::addReadStream($server, function ($server) {
            /** @var resource $client */
            $client = stream_socket_accept($server);
            Loop::addReadStream($client, function ($client) {
                Loop::removeReadStream($client);
                /** @var string $data */
                $data = fread($client, 512);

                /** @phpstan-ignore-next-line unpack won't error on this line as our format is correct */
                list(, $length) = unpack('n', substr($data, 0, 2));
                assert(strlen($data) - 2 === $length);

                fwrite($client, $data);
            });

            Loop::removeReadStream($server);
            fclose($server);
        });

        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $response = await(timeout($promise, 0.2));

        $this->assertInstanceOf(Message::class, $response);
    }

    public function testQueryRejectsIfSocketIsClosedAfterPreviousQueryThatWasStillPending(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->exactly(2))->method('addWriteStream');
        $loop->expects($this->exactly(2))->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeReadStream');

        $loop->expects($this->never())->method('addTimer');
        $loop->expects($this->never())->method('cancelTimer');

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise1 = $executor->query($query);

        /** @var resource $client */
        $client = stream_socket_accept($server);

        $executor->handleWritable();

        // close client socket before processing second write
        fclose($client);

        $promise2 = $executor->query($query);

        $executor->handleWritable();

        $promise1->then(null, $this->expectCallableOnce());
        $promise2->then(null, $this->expectCallableOnce());
    }

    public function testQueryResolvesIfServerSendsBackResponseMessageAndWillStartIdleTimer(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything());
        $loop->expects($this->never())->method('cancelTimer');

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        /** @var string $data */
        $data = $ref->getValue($executor);

        /** @var resource $client */
        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $executor->handleWritable();
        $executor->handleRead();

        $promise->then($this->expectCallableOnce());
    }

    public function testQueryResolvesIfServerSendsBackResponseMessageAfterCancellingQueryAndWillStartIdleTimer(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $timer = $this->createMock(TimerInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->never())->method('cancelTimer');

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $promise->cancel();

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        /** @var string $data */
        $data = $ref->getValue($executor);

        /** @var resource $client */
        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $executor->handleWritable();
        $executor->handleRead();

        //$promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryResolvesIfServerSendsBackResponseMessageAfterCancellingOtherQueryAndWillStartIdleTimer(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything());
        $loop->expects($this->never())->method('cancelTimer');

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        /** @var string $data */
        $data = $ref->getValue($executor);

        /** @var resource $client */
        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $another = $executor->query($query);
        $another->cancel();

        $executor->handleWritable();
        $executor->handleRead();

        $promise->then($this->expectCallableOnce());
    }

    public function testTriggerIdleTimerAfterPreviousQueryResolvedWillCloseIdleSocketConnection(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeReadStream');

        $timer = $this->createMock(TimerInterface::class);
        $timerCallback = null;
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->callback(function ($cb) use (&$timerCallback) {
            $timerCallback = $cb;
            return true;
        }))->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        /** @var string $data */
        $data = $ref->getValue($executor);

        /** @var resource $client */
        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $executor->handleWritable();
        $executor->handleRead();

        $promise->then($this->expectCallableOnce());

        // trigger idle timer
        $this->assertNotNull($timerCallback);
        $timerCallback();
    }

    public function testClosingConnectionAfterPreviousQueryResolvedWillCancelIdleTimer(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeReadStream');

        $timer = $this->createMock(TimerInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        /** @var string $data */
        $data = $ref->getValue($executor);

        /** @var resource $client */
        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $executor->handleWritable();
        $executor->handleRead();

        $promise->then($this->expectCallableOnce());

        // trigger connection close condition
        fclose($client);
        $executor->handleRead();
    }

    public function testQueryAgainAfterPreviousQueryResolvedWillReuseSocketAndCancelIdleTimer(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->exactly(2))->method('addWriteStream');
        $loop->expects($this->once())->method('removeWriteStream');
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->never())->method('removeReadStream');

        $timer = $this->createMock(TimerInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.001, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        /** @var resource $server */
        $server = stream_socket_server('tcp://127.0.0.1:0');
        /** @var string $address */
        $address = stream_socket_get_name($server, false);
        $executor = new TcpTransportExecutor($address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        // use outgoing buffer as response message
        $ref = new \ReflectionProperty($executor, 'writeBuffer');
        $ref->setAccessible(true);
        /** @var string $data */
        $data = $ref->getValue($executor);

        /** @var resource $client */
        $client = stream_socket_accept($server);
        fwrite($client, $data);

        $executor->handleWritable();
        $executor->handleRead();

        $promise->then($this->expectCallableOnce());

        // trigger second query
        $executor->query($query);
    }
}
