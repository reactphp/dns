<?php

namespace React\Tests\Dns\Query;

use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\Dns\Query\CancellationException;
use React\Dns\Query\Query;
use React\Dns\Query\UdpTransportExecutor;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Tests\Dns\TestCase;
use function React\Async\await;
use function React\Promise\Timer\sleep;
use function React\Promise\Timer\timeout;

class UdpTransportExecutorTest extends TestCase
{
    /**
     * @dataProvider provideDefaultPortProvider
     * @param string $input
     * @param string $expected
     */
    public function testCtorShouldAcceptNameserverAddresses($input, $expected)
    {
        $loop = $this->createMock(LoopInterface::class);

        $executor = new UdpTransportExecutor($input, $loop);

        $ref = new \ReflectionProperty($executor, 'nameserver');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $value = $ref->getValue($executor);

        $this->assertEquals($expected, $value);
    }

    public static function provideDefaultPortProvider()
    {
        yield [
            '8.8.8.8',
            'udp://8.8.8.8:53'
        ];
        yield [
            '1.2.3.4:5',
            'udp://1.2.3.4:5'
        ];
        yield [
            'udp://1.2.3.4',
            'udp://1.2.3.4:53'
        ];
        yield [
            'udp://1.2.3.4:53',
            'udp://1.2.3.4:53'
        ];
        yield [
            '::1',
            'udp://[::1]:53'
        ];
        yield [
            '[::1]:53',
            'udp://[::1]:53'
        ];
    }

    public function testCtorWithoutLoopShouldAssignDefaultLoop()
    {
        $executor = new UdpTransportExecutor('127.0.0.1');

        $ref = new \ReflectionProperty($executor, 'loop');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $loop = $ref->getValue($executor);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testCtorShouldThrowWhenNameserverAddressIsInvalid()
    {
        $loop = $this->createMock(LoopInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        new UdpTransportExecutor('///', $loop);
    }

    public function testCtorShouldThrowWhenNameserverAddressContainsHostname()
    {
        $loop = $this->createMock(LoopInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        new UdpTransportExecutor('localhost', $loop);
    }

    public function testCtorShouldThrowWhenNameserverSchemeIsInvalid()
    {
        $loop = $this->createMock(LoopInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        new UdpTransportExecutor('tcp://1.2.3.4', $loop);
    }

    public function testQueryRejectsIfMessageExceedsUdpSize()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->never())->method('addReadStream');

        $executor = new UdpTransportExecutor('8.8.8.8:53', $loop);

        $query = new Query('google.' . str_repeat('.com', 200), Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        $this->expectException(
            \RuntimeException::class,
            'DNS query for ' . $query->name . ' (A) failed: Query too large for UDP transport',
            defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90
        );
        throw $exception;
    }

    public function testQueryRejectsIfServerConnectionFails()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->never())->method('addReadStream');

        $executor = new UdpTransportExecutor('::1', $loop);

        $ref = new \ReflectionProperty($executor, 'nameserver');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($executor, '///');

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        $this->expectException(
            \RuntimeException::class,
            'DNS query for google.com (A) failed: Unable to connect to DNS server /// (Failed to parse address "///")'
        );
        throw $exception;
    }

    public function testQueryRejectsIfSendToServerFailsAfterConnectionWithoutCallingCustomErrorHandler()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->never())->method('addReadStream');

        $executor = new UdpTransportExecutor('0.0.0.0', $loop);

        // increase hard-coded maximum packet size to allow sending excessive data
        $ref = new \ReflectionProperty($executor, 'maxPacketSize');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($executor, PHP_INT_MAX);

        $error = null;
        set_error_handler(function ($_, $errstr) use (&$error) {
            $error = $errstr;
        });

        $query = new Query(str_repeat('a.', 100000) . '.example', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);

        restore_error_handler();
        $this->assertNull($error);

        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        // ECONNREFUSED (Connection refused) on Linux, EMSGSIZE (Message too long) on macOS
        $this->expectException(
            \RuntimeException::class,
            'DNS query for ' . $query->name . ' (A) failed: Unable to send query to DNS server udp://0.0.0.0:53 ('
        );
        throw $exception;
    }

    public function testQueryKeepsPendingIfReadFailsBecauseServerRefusesConnection()
    {
        $socket = null;
        $callback = null;
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addReadStream')->with($this->callback(function ($ref) use (&$socket) {
            $socket = $ref;
            return true;
        }), $this->callback(function ($ref) use (&$callback) {
            $callback = $ref;
            return true;
        }));

        $executor = new UdpTransportExecutor('0.0.0.0', $loop);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $promise = $executor->query($query);

        $this->assertNotNull($socket);
        $callback($socket);

        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $pending = true;
        $promise->then(function () use (&$pending) {
            $pending = false;
        }, function () use (&$pending) {
            $pending = false;
        });

        $this->assertTrue($pending);
    }

    /**
     * @group internet
     */
    public function testQueryRejectsOnCancellation()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addReadStream');
        $loop->expects($this->once())->method('removeReadStream');

        $executor = new UdpTransportExecutor('8.8.8.8:53', $loop);

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

    public function testQueryKeepsPendingIfServerSendsInvalidMessage()
    {
        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        Loop::addReadStream($server, function ($server) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);
            stream_socket_sendto($server, 'invalid', 0, $peer);

            Loop::removeReadStream($server);
            fclose($server);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new UdpTransportExecutor($address);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $promise = $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        await(sleep(0.2));
        $this->assertTrue($wait);

        $promise->cancel();
    }

    public function testQueryKeepsPendingIfServerSendsInvalidId()
    {
        $parser = new Parser();
        $dumper = new BinaryDumper();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        Loop::addReadStream($server, function ($server) use ($parser, $dumper) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);

            $message = $parser->parseMessage($data);
            $message->id = 0;

            stream_socket_sendto($server, $dumper->toBinary($message), 0, $peer);

            Loop::removeReadStream($server);
            fclose($server);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new UdpTransportExecutor($address);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $wait = true;
        $promise = $executor->query($query)->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        await(sleep(0.2));
        $this->assertTrue($wait);

        $promise->cancel();
    }

    public function testQueryRejectsIfServerSendsTruncatedResponse()
    {
        $parser = new Parser();
        $dumper = new BinaryDumper();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        Loop::addReadStream($server, function ($server) use ($parser, $dumper) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);

            $message = $parser->parseMessage($data);
            $message->tc = true;

            stream_socket_sendto($server, $dumper->toBinary($message), 0, $peer);

            Loop::removeReadStream($server);
            fclose($server);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new UdpTransportExecutor($address);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        $this->expectException(
            \RuntimeException::class,
            'DNS query for google.com (A) failed: The DNS server udp://' . $address . ' returned a truncated result for a UDP query',
            defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90
        );
        await(timeout($promise, 0.1));
    }

    public function testQueryResolvesIfServerSendsValidResponse()
    {
        $parser = new Parser();
        $dumper = new BinaryDumper();

        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        Loop::addReadStream($server, function ($server) use ($parser, $dumper) {
            $data = stream_socket_recvfrom($server, 512, 0, $peer);

            $message = $parser->parseMessage($data);

            stream_socket_sendto($server, $dumper->toBinary($message), 0, $peer);

            Loop::removeReadStream($server);
            fclose($server);
        });

        $address = stream_socket_get_name($server, false);
        $executor = new UdpTransportExecutor($address);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $response = await(timeout($promise, 0.2));

        $this->assertInstanceOf(Message::class, $response);
    }
}
