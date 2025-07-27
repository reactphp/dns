<?php

namespace React\Tests\Dns\Query;

use PHPUnit\Framework\MockObject\MockObject;
use React\Dns\Model\Message;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\Query\SelectiveTransportExecutor;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Tests\Dns\TestCase;
use function React\Promise\reject;
use function React\Promise\resolve;

class SelectiveTransportExecutorTest extends TestCase
{
    /**
     * @var ExecutorInterface&MockObject
     */
    private $datagram;

    /**
     * @var ExecutorInterface&MockObject
     */
    private $stream;

    /**
     * @var ExecutorInterface
     */
    private $executor;

    /**
     * @before
     */
    public function setUpMocks(): void
    {
        $this->datagram = $this->createMock(ExecutorInterface::class);
        $this->stream = $this->createMock(ExecutorInterface::class);

        $this->executor = new SelectiveTransportExecutor($this->datagram, $this->stream);
    }

    public function testQueryResolvesWhenDatagramTransportResolvesWithoutUsingStreamTransport(): void
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);

        $response = new Message();

        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(resolve($response));

        $this->stream
            ->expects($this->never())
            ->method('query');

        $promise = $this->executor->query($query);

        $promise->then($this->expectCallableOnceWith($response));
    }

    public function testQueryResolvesWhenStreamTransportResolvesAfterDatagramTransportRejectsWithSizeError(): void
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);

        $response = new Message();

        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(reject(new \RuntimeException('', defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90)));

        $this->stream
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(resolve($response));

        $promise = $this->executor->query($query);

        $promise->then($this->expectCallableOnceWith($response));
    }

    public function testQueryRejectsWhenDatagramTransportRejectsWithRuntimeExceptionWithoutUsingStreamTransport(): void
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);

        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(reject(new \RuntimeException()));

        $this->stream
            ->expects($this->never())
            ->method('query');

        $promise = $this->executor->query($query);

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testQueryRejectsWhenStreamTransportRejectsAfterDatagramTransportRejectsWithSizeError(): void
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);

        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(reject(new \RuntimeException('', defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90)));

        $this->stream
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(reject(new \RuntimeException()));

        $promise = $this->executor->query($query);

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testCancelPromiseWillCancelPromiseFromDatagramExecutor(): void
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);

        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(new Promise(function () {}, $this->expectCallableOnce()));

        $promise = $this->executor->query($query);
        $promise->cancel();
    }

    public function testCancelPromiseWillCancelPromiseFromStreamExecutorWhenDatagramExecutorRejectedWithTruncatedResponse(): void
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);

        $deferred = new Deferred();
        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn($deferred->promise());

        $this->stream
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(new Promise(function () {}, $this->expectCallableOnce()));

        $promise = $this->executor->query($query);
        $deferred->reject(new \RuntimeException('', defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90));
        $promise->cancel();
    }

    public function testCancelPromiseShouldNotCreateAnyGarbageReferences(): void
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);

        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(new Promise(function () {}, function () {
                throw new \RuntimeException('Cancelled');
            }));

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $promise = $this->executor->query($query);
        $promise->cancel();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testCancelPromiseAfterTruncatedResponseShouldNotCreateAnyGarbageReferences(): void
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);

        $deferred = new Deferred();
        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn($deferred->promise());

        $this->stream
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(new Promise(function () {}, function () {
                throw new \RuntimeException('Cancelled');
            }));

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $promise = $this->executor->query($query);
        $deferred->reject(new \RuntimeException('', defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90));
        $promise->cancel();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testRejectedPromiseAfterTruncatedResponseShouldNotCreateAnyGarbageReferences(): void
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);

        $this->datagram
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(reject(new \RuntimeException('', defined('SOCKET_EMSGSIZE') ? SOCKET_EMSGSIZE : 90)));

        $this->stream
            ->expects($this->once())
            ->method('query')
            ->with($query)
            ->willReturn(reject(new \RuntimeException()));

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $promise = $this->executor->query($query);

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }
}
