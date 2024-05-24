<?php

namespace React\Tests\Dns\Query;

use PHPUnit\Framework\MockObject\MockObject;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\CancellationException;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\Query\TimeoutException;
use React\Dns\Query\TimeoutExecutor;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Tests\Dns\TestCase;
use function React\Promise\reject;
use function React\Promise\resolve;

class TimeoutExecutorTest extends TestCase
{
    /**
     * @var ExecutorInterface&MockObject
     */
    private $wrapped;

    /**
     * @var ExecutorInterface
     */
    private $executor;

    /**
     * @var LoopInterface&MockObject
     */
    private $loop;

    /**
     * @before
     */
    public function setUpExecutor(): void
    {
        $this->wrapped = $this->createMock(ExecutorInterface::class);

        $this->loop = $this->createMock(LoopInterface::class);

        $this->executor = new TimeoutExecutor($this->wrapped, 5.0, $this->loop);
    }

    public function testCtorWithoutLoopShouldAssignDefaultLoop(): void
    {
        $executor = new TimeoutExecutor($this->executor, 5.0);

        $ref = new \ReflectionProperty($executor, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($executor);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testCancellingPromiseWillCancelWrapped(): void
    {
        $timer = $this->createMock(TimerInterface::class);
        $this->loop->expects($this->once())->method('addTimer')->with(5.0, $this->anything())->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $cancelled = 0;

        $this->wrapped
            ->expects($this->once())
            ->method('query')
            ->will($this->returnCallback(function ($query) use (&$cancelled) {
                $deferred = new Deferred(function ($resolve, $reject) use (&$cancelled) {
                    ++$cancelled;
                    $reject(new CancellationException('Cancelled'));
                });

                return $deferred->promise();
            }));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $this->executor->query($query);

        $this->assertEquals(0, $cancelled);
        $promise->cancel();
        $this->assertEquals(1, $cancelled);

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testResolvesPromiseWithoutStartingTimerWhenWrappedReturnsResolvedPromise(): void
    {
        $this->loop->expects($this->never())->method('addTimer');
        $this->loop->expects($this->never())->method('cancelTimer');

        $this->wrapped
            ->expects($this->once())
            ->method('query')
            ->willReturn(resolve($this->createStandardResponse()));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $this->executor->query($query);

        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());
    }

    public function testResolvesPromiseAfterCancellingTimerWhenWrappedReturnsPendingPromiseThatResolves(): void
    {
        $timer = $this->createMock(TimerInterface::class);
        $this->loop->expects($this->once())->method('addTimer')->with(5.0, $this->anything())->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $this->wrapped
            ->expects($this->once())
            ->method('query')
            ->willReturn($deferred->promise());

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $this->executor->query($query);

        $deferred->resolve($this->createStandardResponse());

        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());
    }

    public function testRejectsPromiseWithoutStartingTimerWhenWrappedReturnsRejectedPromise(): void
    {
        $this->loop->expects($this->never())->method('addTimer');
        $this->loop->expects($this->never())->method('cancelTimer');

        $this->wrapped
            ->expects($this->once())
            ->method('query')
            ->willReturn(reject(new \RuntimeException()));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $this->executor->query($query);

        $promise->then($this->expectCallableNever(), $this->expectCallableOnceWith(new \RuntimeException()));
    }

    public function testRejectsPromiseAfterCancellingTimerWhenWrappedReturnsPendingPromiseThatRejects(): void
    {
        $timer = $this->createMock(TimerInterface::class);
        $this->loop->expects($this->once())->method('addTimer')->with(5.0, $this->anything())->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $this->wrapped
            ->expects($this->once())
            ->method('query')
            ->willReturn($deferred->promise());

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $this->executor->query($query);

        $deferred->reject(new \RuntimeException());

        $promise->then($this->expectCallableNever(), $this->expectCallableOnceWith(new \RuntimeException()));
    }

    public function testRejectsPromiseAndCancelsPendingQueryWhenTimeoutTriggers(): void
    {
        $timerCallback = null;
        $timer = $this->createMock(TimerInterface::class);
        $this->loop->expects($this->once())->method('addTimer')->with(5.0, $this->callback(function ($callback) use (&$timerCallback) {
            $timerCallback = $callback;
            return true;
        }))->willReturn($timer);
        $this->loop->expects($this->once())->method('cancelTimer')->with($timer);

        $cancelled = 0;

        $this->wrapped
            ->expects($this->once())
            ->method('query')
            ->will($this->returnCallback(function ($query) use (&$cancelled) {
                $deferred = new Deferred(function ($resolve, $reject) use (&$cancelled) {
                    ++$cancelled;
                    $reject(new CancellationException('Cancelled'));
                });

                return $deferred->promise();
            }));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $this->executor->query($query);

        $this->assertEquals(0, $cancelled);

        $this->assertNotNull($timerCallback);
        $timerCallback();

        $this->assertEquals(1, $cancelled);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof TimeoutException);
        $this->assertInstanceOf(TimeoutException::class, $exception);
        $this->assertEquals('DNS query for igor.io (A) timed out' , $exception->getMessage());
    }


    protected function createStandardResponse(): Message
    {
        $response = new Message();
        $response->qr = true;
        $response->questions[] = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $response->answers[] = new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '0.0.0.0');

        return $response;
    }
}
