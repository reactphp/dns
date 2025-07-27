<?php

use React\Dns\Model\Message;
use React\Dns\Query\CoopExecutor;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Tests\Dns\TestCase;
use function React\Promise\reject;
use function React\Promise\resolve;

class CoopExecutorTest extends TestCase
{
    public function testQueryOnceWillPassExactQueryToBaseExecutor(): void
    {
        $pending = new Promise(function () { });
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->once())->method('query')->with($query)->willReturn($pending);
        $connector = new CoopExecutor($base);

        $connector->query($query);
    }

    public function testQueryOnceWillResolveWhenBaseExecutorResolves(): void
    {
        $message = new Message();

        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->once())->method('query')->willReturn(resolve($message));
        $connector = new CoopExecutor($base);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $promise = $connector->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $promise->then($this->expectCallableOnceWith($message));
    }

    public function testQueryOnceWillRejectWhenBaseExecutorRejects(): void
    {
        $exception = new RuntimeException();

        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->once())->method('query')->willReturn(reject($exception));
        $connector = new CoopExecutor($base);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $promise = $connector->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $promise->then(null, $this->expectCallableOnceWith($exception));
    }

    public function testQueryTwoDifferentQueriesWillPassExactQueryToBaseExecutorTwice(): void
    {
        $pending = new Promise(function () { });
        $query1 = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $query2 = new Query('reactphp.org', Message::TYPE_AAAA, Message::CLASS_IN);
        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->exactly(2))->method('query')->withConsecutive(
            [$query1],
            [$query2]
        )->willReturn($pending);
        $connector = new CoopExecutor($base);

        $connector->query($query1);
        $connector->query($query2);
    }

    public function testQueryTwiceWillPassExactQueryToBaseExecutorOnceWhenQueryIsStillPending(): void
    {
        $pending = new Promise(function () { });
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->once())->method('query')->with($query)->willReturn($pending);
        $connector = new CoopExecutor($base);

        $connector->query($query);
        $connector->query($query);
    }

    public function testQueryTwiceWillPassExactQueryToBaseExecutorTwiceWhenFirstQueryIsAlreadyResolved(): void
    {
        $deferred = new Deferred();
        $pending = new Promise(function () { });
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->exactly(2))->method('query')->with($query)->willReturnOnConsecutiveCalls($deferred->promise(), $pending);

        $connector = new CoopExecutor($base);

        $connector->query($query);

        $deferred->resolve(new Message());

        $connector->query($query);
    }

    public function testQueryTwiceWillPassExactQueryToBaseExecutorTwiceWhenFirstQueryIsAlreadyRejected(): void
    {
        $deferred = new Deferred();
        $pending = new Promise(function () { });
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->exactly(2))->method('query')->with($query)->willReturnOnConsecutiveCalls($deferred->promise(), $pending);

        $connector = new CoopExecutor($base);

        $promise = $connector->query($query);

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $deferred->reject(new RuntimeException());

        $connector->query($query);
    }

    public function testCancelQueryWillCancelPromiseFromBaseExecutorAndReject(): void
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());

        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->once())->method('query')->willReturn($promise);
        $connector = new CoopExecutor($base);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $promise = $connector->query($query);

        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for reactphp.org (A) has been cancelled', $exception->getMessage());
    }

    public function testCancelOneQueryWhenOtherQueryIsStillPendingWillNotCancelPromiseFromBaseExecutorAndRejectCancelled(): void
    {
        $promise = new Promise(function () { }, $this->expectCallableNever());

        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->once())->method('query')->willReturn($promise);
        $connector = new CoopExecutor($base);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $promise1 = $connector->query($query);
        $promise2 = $connector->query($query);

        $promise1->cancel();

        $promise1->then(null, $this->expectCallableOnce());
        $promise2->then(null, $this->expectCallableNever());
    }

    public function testCancelSecondQueryWhenFirstQueryIsStillPendingWillNotCancelPromiseFromBaseExecutorAndRejectCancelled(): void
    {
        $promise = new Promise(function () { }, $this->expectCallableNever());

        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->once())->method('query')->willReturn($promise);
        $connector = new CoopExecutor($base);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $promise1 = $connector->query($query);
        $promise2 = $connector->query($query);

        $promise2->cancel();

        $promise2->then(null, $this->expectCallableOnce());
        $promise1->then(null, $this->expectCallableNever());
    }

    public function testCancelAllPendingQueriesWillCancelPromiseFromBaseExecutorAndRejectCancelled(): void
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());

        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->once())->method('query')->willReturn($promise);
        $connector = new CoopExecutor($base);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $promise1 = $connector->query($query);
        $promise2 = $connector->query($query);

        $promise1->cancel();
        $promise2->cancel();

        $promise1->then(null, $this->expectCallableOnce());
        $promise2->then(null, $this->expectCallableOnce());
    }

    public function testQueryTwiceWillQueryBaseExecutorTwiceIfFirstQueryHasAlreadyBeenCancelledWhenSecondIsStarted(): void
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());
        $pending = new Promise(function () { });

        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->exactly(2))->method('query')->willReturnOnConsecutiveCalls($promise, $pending);
        $connector = new CoopExecutor($base);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $promise1 = $connector->query($query);
        $promise1->cancel();

        $promise2 = $connector->query($query);

        $promise1->then(null, $this->expectCallableOnce());

        $promise2->then(null, $this->expectCallableNever());
    }

    public function testCancelQueryShouldNotCauseGarbageReferences(): void
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $deferred = new Deferred(function () {
            throw new \RuntimeException();
        });

        $base = $this->createMock(ExecutorInterface::class);
        $base->expects($this->once())->method('query')->willReturn($deferred->promise());
        $connector = new CoopExecutor($base);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $promise = $connector->query($query);
        $promise->cancel();
        $promise = null;

        $this->assertEquals(0, gc_collect_cycles());
    }
}
