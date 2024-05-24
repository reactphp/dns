<?php

namespace React\Tests\Dns\Query;

use React\Dns\Model\Message;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\FallbackExecutor;
use React\Dns\Query\Query;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Tests\Dns\TestCase;
use function React\Promise\reject;
use function React\Promise\resolve;

class FallbackExecutorTest extends TestCase
{
    public function testQueryWillReturnPendingPromiseWhenPrimaryExecutorIsStillPending(): void
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $primary = $this->createMock(ExecutorInterface::class);
        $primary->expects($this->once())->method('query')->with($query)->willReturn(new Promise(function () { }));

        $secondary = $this->createMock(ExecutorInterface::class);

        $executor = new FallbackExecutor($primary, $secondary);

        $promise = $executor->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryWillResolveWithMessageWhenPrimaryExecutorResolvesWithMessage(): void
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $primary = $this->createMock(ExecutorInterface::class);
        $primary->expects($this->once())->method('query')->with($query)->willReturn(resolve(new Message()));

        $secondary = $this->createMock(ExecutorInterface::class);

        $executor = new FallbackExecutor($primary, $secondary);

        $promise = $executor->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableOnceWith($this->isInstanceOf(Message::class)), $this->expectCallableNever());
    }

    public function testQueryWillReturnPendingPromiseWhenPrimaryExecutorRejectsPromiseAndSecondaryExecutorIsStillPending(): void
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $primary = $this->createMock(ExecutorInterface::class);
        $primary->expects($this->once())->method('query')->with($query)->willReturn(reject(new \RuntimeException()));

        $secondary = $this->createMock(ExecutorInterface::class);
        $secondary->expects($this->once())->method('query')->with($query)->willReturn(new Promise(function () { }));

        $executor = new FallbackExecutor($primary, $secondary);

        $promise = $executor->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryWillResolveWithMessageWhenPrimaryExecutorRejectsPromiseAndSecondaryExecutorResolvesWithMessage(): void
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $primary = $this->createMock(ExecutorInterface::class);
        $primary->expects($this->once())->method('query')->with($query)->willReturn(reject(new \RuntimeException()));

        $secondary = $this->createMock(ExecutorInterface::class);
        $secondary->expects($this->once())->method('query')->with($query)->willReturn(resolve(new Message()));

        $executor = new FallbackExecutor($primary, $secondary);

        $promise = $executor->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableOnceWith($this->isInstanceOf(Message::class)), $this->expectCallableNever());
    }

    public function testQueryWillRejectWithExceptionMessagesConcatenatedAfterColonWhenPrimaryExecutorRejectsPromiseAndSecondaryExecutorRejectsPromiseWithMessageWithColon(): void
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $primary = $this->createMock(ExecutorInterface::class);
        $primary->expects($this->once())->method('query')->with($query)->willReturn(reject(new \RuntimeException('DNS query for reactphp.org (A) failed: Unable to connect to DNS server A')));

        $secondary = $this->createMock(ExecutorInterface::class);
        $secondary->expects($this->once())->method('query')->with($query)->willReturn(reject(new \RuntimeException('DNS query for reactphp.org (A) failed: Unable to connect to DNS server B')));

        $executor = new FallbackExecutor($primary, $secondary);

        $promise = $executor->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for reactphp.org (A) failed: Unable to connect to DNS server A. Unable to connect to DNS server B', $exception->getMessage());
    }

    public function testQueryWillRejectWithExceptionMessagesConcatenatedInFullWhenPrimaryExecutorRejectsPromiseAndSecondaryExecutorRejectsPromiseWithMessageWithNoColon(): void
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $primary = $this->createMock(ExecutorInterface::class);
        $primary->expects($this->once())->method('query')->with($query)->willReturn(reject(new \RuntimeException('Reason A')));

        $secondary = $this->createMock(ExecutorInterface::class);
        $secondary->expects($this->once())->method('query')->with($query)->willReturn(reject(new \RuntimeException('Reason B')));

        $executor = new FallbackExecutor($primary, $secondary);

        $promise = $executor->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Reason A. Reason B', $exception->getMessage());
    }

    public function testCancelQueryWillReturnRejectedPromiseWithoutCallingSecondaryExecutorWhenPrimaryExecutorIsStillPending(): void
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $primary = $this->createMock(ExecutorInterface::class);
        $primary->expects($this->once())->method('query')->with($query)->willReturn(new Promise(function () { }, function () { throw new \RuntimeException(); }));

        $secondary = $this->createMock(ExecutorInterface::class);
        $secondary->expects($this->never())->method('query');

        $executor = new FallbackExecutor($primary, $secondary);

        $promise = $executor->query($query);
        $promise->cancel();

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testCancelQueryWillReturnRejectedPromiseWhenPrimaryExecutorRejectsAndSecondaryExecutorIsStillPending(): void
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $primary = $this->createMock(ExecutorInterface::class);
        $primary->expects($this->once())->method('query')->with($query)->willReturn(reject(new \RuntimeException()));

        $secondary = $this->createMock(ExecutorInterface::class);
        $secondary->expects($this->once())->method('query')->with($query)->willReturn(new Promise(function () { }, function () { throw new \RuntimeException(); }));

        $executor = new FallbackExecutor($primary, $secondary);

        $promise = $executor->query($query);
        $promise->cancel();

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testCancelQueryShouldNotCauseGarbageReferencesWhenCancellingPrimaryExecutor(): void
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $primary = $this->createMock(ExecutorInterface::class);
        $primary->expects($this->once())->method('query')->willReturn(new Promise(function () { }, function () { throw new \RuntimeException(); }));

        $secondary = $this->createMock(ExecutorInterface::class);
        $secondary->expects($this->never())->method('query');

        $executor = new FallbackExecutor($primary, $secondary);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $promise->cancel();
        $promise = null;

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testCancelQueryShouldNotCauseGarbageReferencesWhenCancellingSecondaryExecutor(): void
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $primary = $this->createMock(ExecutorInterface::class);
        $primary->expects($this->once())->method('query')->willReturn(reject(new \RuntimeException()));

        $secondary = $this->createMock(ExecutorInterface::class);
        $secondary->expects($this->once())->method('query')->willReturn(new Promise(function () { }, function () { throw new \RuntimeException(); }));

        $executor = new FallbackExecutor($primary, $secondary);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $promise->cancel();
        $promise = null;

        $this->assertEquals(0, gc_collect_cycles());
    }
}
