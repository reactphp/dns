<?php

namespace React\Tests\Dns\Query;

use PHPUnit\Framework\MockObject\MockObject;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\CancellationException;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\Query\RetryExecutor;
use React\Dns\Query\TimeoutException;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Tests\Dns\TestCase;
use function React\Promise\reject;
use function React\Promise\resolve;

class RetryExecutorTest extends TestCase
{
    /**
    * @covers \React\Dns\Query\RetryExecutor
    * @test
    */
    public function queryShouldDelegateToDecoratedExecutor(): void
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnValue($this->expectPromiseOnce()));

        $retryExecutor = new RetryExecutor($executor, 2);

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $retryExecutor->query($query);
    }

    /**
    * @covers \React\Dns\Query\RetryExecutor
    * @test
    */
    public function queryShouldRetryQueryOnTimeout(): void
    {
        $response = $this->createStandardResponse();

        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->exactly(2))
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->onConsecutiveCalls(
                $this->returnCallback(function ($query) {
                    return reject(new TimeoutException("timeout"));
                }),
                $this->returnCallback(function ($query) use ($response) {
                    return resolve($response);
                })
            ));

        /** @var (callable(Message): void)&MockObject $callback */
        $callback = $this->createCallableMock();
        $callback
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf(Message::class));

        $errorback = $this->expectCallableNever();

        $retryExecutor = new RetryExecutor($executor, 2);

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $retryExecutor->query($query)->then($callback, $errorback);
    }

    /**
    * @covers \React\Dns\Query\RetryExecutor
    * @test
    */
    public function queryShouldStopRetryingAfterSomeAttempts(): void
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->exactly(3))
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(function ($query) {
                return reject(new TimeoutException("timeout"));
            }));

        $retryExecutor = new RetryExecutor($executor, 2);

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $retryExecutor->query($query);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for igor.io (A) failed: too many retries', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertInstanceOf(TimeoutException::class, $exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    /**
    * @covers \React\Dns\Query\RetryExecutor
    * @test
    */
    public function queryShouldForwardNonTimeoutErrors(): void
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(function ($query) {
                return reject(new \Exception);
            }));

        $callback = $this->expectCallableNever();

        /** @var (callable(\Throwable): void)&MockObject $errorback */
        $errorback = $this->createCallableMock();
        $errorback
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf(\Exception::class));

        $retryExecutor = new RetryExecutor($executor, 2);

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $retryExecutor->query($query)->then($callback, $errorback);
    }

    /**
     * @covers \React\Dns\Query\RetryExecutor
     * @test
     */
    public function queryShouldCancelQueryOnCancel(): void
    {
        $cancelled = 0;

        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(function ($query) use (&$cancelled) {
                $deferred = new Deferred(function ($resolve, $reject) use (&$cancelled) {
                    ++$cancelled;
                    $reject(new CancellationException('Cancelled'));
                });

                return $deferred->promise();
            })
        );

        $retryExecutor = new RetryExecutor($executor, 2);

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $retryExecutor->query($query);

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());

        $this->assertEquals(0, $cancelled);
        $promise->cancel();
        $this->assertEquals(1, $cancelled);
    }

    /**
     * @covers \React\Dns\Query\RetryExecutor
     * @test
     */
    public function queryShouldCancelSecondQueryOnCancel(): void
    {
        $deferred = new Deferred();
        $cancelled = 0;

        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->exactly(2))
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->onConsecutiveCalls(
                $this->returnValue($deferred->promise()),
                $this->returnCallback(function ($query) use (&$cancelled) {
                    $deferred = new Deferred(function ($resolve, $reject) use (&$cancelled) {
                        ++$cancelled;
                        $reject(new CancellationException('Cancelled'));
                    });

                    return $deferred->promise();
                })
        ));

        $retryExecutor = new RetryExecutor($executor, 2);

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $retryExecutor->query($query);

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());

        // first query will time out after a while and this sends the next query
        $deferred->reject(new TimeoutException());

        $this->assertEquals(0, $cancelled);
        $promise->cancel();
        $this->assertEquals(1, $cancelled);
    }

    /**
     * @covers \React\Dns\Query\RetryExecutor
     * @test
     */
    public function queryShouldNotCauseGarbageReferencesOnSuccess(): void
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->willReturn(resolve($this->createStandardResponse()));

        $retryExecutor = new RetryExecutor($executor, 0);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $retryExecutor->query($query);

        $this->assertEquals(0, gc_collect_cycles());
    }

    /**
     * @covers \React\Dns\Query\RetryExecutor
     * @test
     */
    public function queryShouldNotCauseGarbageReferencesOnTimeoutErrors(): void
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->any())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->willReturn(reject(new TimeoutException("timeout")));

        $retryExecutor = new RetryExecutor($executor, 0);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $retryExecutor->query($query);

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $this->assertEquals(0, gc_collect_cycles());
    }

    /**
     * @covers \React\Dns\Query\RetryExecutor
     * @test
     */
    public function queryShouldNotCauseGarbageReferencesOnCancellation(): void
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $deferred = new Deferred(function () {
            throw new \RuntimeException();
        });

        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->willReturn($deferred->promise());

        $retryExecutor = new RetryExecutor($executor, 0);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $retryExecutor->query($query);
        $promise->cancel();
        $promise = null;

        $this->assertEquals(0, gc_collect_cycles());
    }

    /**
     * @covers \React\Dns\Query\RetryExecutor
     * @test
     */
    public function queryShouldNotCauseGarbageReferencesOnNonTimeoutErrors(): void
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(function ($query) {
                return reject(new \Exception);
            }));

        $retryExecutor = new RetryExecutor($executor, 2);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $promise = $retryExecutor->query($query);

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $this->assertEquals(0, gc_collect_cycles());
    }

    /**
     * @param ?mixed $return
     * @return PromiseInterface<?mixed>
     */
    protected function expectPromiseOnce($return = null): PromiseInterface
    {
        $mock = $this->createPromiseMock();
        $mock
            ->expects($this->once())
            ->method('then')
            ->will($this->returnValue(resolve($return)));

        return $mock;
    }

    /**
     * @return ExecutorInterface&MockObject
     */
    protected function createExecutorMock()
    {
        return $this->createMock(ExecutorInterface::class);
    }

    /**
     * @return PromiseInterface<mixed>&MockObject
     */
    protected function createPromiseMock()
    {
        return $this->createMock(PromiseInterface::class);
    }

    protected function createStandardResponse(): Message
    {
        $response = new Message();
        $response->qr = true;
        $response->questions[] = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $response->answers[] = new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131');

        return $response;
    }
}

