<?php

namespace React\Tests\Dns\Query;

use React\Dns\Model\Message;
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
    private $wrapped;
    private $executor;
    private $loop;

    /**
     * @before
     */
    public function setUpExecutor()
    {
        $this->wrapped = $this->createMock(ExecutorInterface::class);

        $this->loop = $this->createMock(LoopInterface::class);

        $this->executor = new TimeoutExecutor($this->wrapped, 5.0, $this->loop);
    }

    public function testCtorWithoutLoopShouldAssignDefaultLoop()
    {
        $executor = new TimeoutExecutor($this->executor, 5.0);

        $ref = new \ReflectionProperty($executor, 'loop');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $loop = $ref->getValue($executor);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testCancellingPromiseWillCancelWrapped()
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

    public function testResolvesPromiseWithoutStartingTimerWhenWrappedReturnsResolvedPromise()
    {
        $this->loop->expects($this->never())->method('addTimer');
        $this->loop->expects($this->never())->method('cancelTimer');

        $this->wrapped
            ->expects($this->once())
            ->method('query')
            ->willReturn(resolve('0.0.0.0'));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $this->executor->query($query);

        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());
    }

    public function testResolvesPromiseAfterCancellingTimerWhenWrappedReturnsPendingPromiseThatResolves()
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

        $deferred->resolve('0.0.0.0');

        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());
    }

    public function testRejectsPromiseWithoutStartingTimerWhenWrappedReturnsRejectedPromise()
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

    public function testRejectsPromiseAfterCancellingTimerWhenWrappedReturnsPendingPromiseThatRejects()
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

    public function testRejectsPromiseAndCancelsPendingQueryWhenTimeoutTriggers()
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
}
