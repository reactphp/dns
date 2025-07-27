<?php

namespace React\Tests\Dns\Query;

use React\Cache\CacheInterface;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\CachingExecutor;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Promise\Promise;
use React\Promise\Deferred;
use React\Tests\Dns\TestCase;
use function React\Promise\reject;
use function React\Promise\resolve;

class CachingExecutorTest extends TestCase
{
    public function testQueryWillReturnPendingPromiseWhenCacheIsPendingWithoutSendingQueryToFallbackExecutor(): void
    {
        $fallback = $this->createMock(ExecutorInterface::class);
        $fallback->expects($this->never())->method('query');

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->with('reactphp.org:1:1')->willReturn(new Promise(function () { }));

        $executor = new CachingExecutor($fallback, $cache);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryWillReturnPendingPromiseWhenCacheReturnsMissAndWillSendSameQueryToFallbackExecutor(): void
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $fallback = $this->createMock(ExecutorInterface::class);
        $fallback->expects($this->once())->method('query')->with($query)->willReturn(new Promise(function () { }));

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->willReturn(resolve(null));

        $executor = new CachingExecutor($fallback, $cache);

        $promise = $executor->query($query);

        $promise->then($this->expectCallableNever(), $this->expectCallableNever());
    }

    public function testQueryWillReturnResolvedPromiseWhenCacheReturnsHitWithoutSendingQueryToFallbackExecutor(): void
    {
        $fallback = $this->createMock(ExecutorInterface::class);
        $fallback->expects($this->never())->method('query');

        $message = new Message();
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->with('reactphp.org:1:1')->willReturn(resolve($message));

        $executor = new CachingExecutor($fallback, $cache);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        $promise->then($this->expectCallableOnceWith($message), $this->expectCallableNever());
    }

    public function testQueryWillReturnResolvedPromiseWhenCacheReturnsMissAndFallbackExecutorResolvesAndSaveMessageToCacheWithMinimumTtlFromRecord(): void
    {
        $message = new Message();
        $message->answers[] = new Record('reactphp.org', Message::TYPE_A, Message::CLASS_IN, 3700, '127.0.0.1');
        $message->answers[] = new Record('reactphp.org', Message::TYPE_A, Message::CLASS_IN, 3600, '127.0.0.1');
        $fallback = $this->createMock(ExecutorInterface::class);
        $fallback->expects($this->once())->method('query')->willReturn(resolve($message));

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->with('reactphp.org:1:1')->willReturn(resolve(null));
        $cache->expects($this->once())->method('set')->with('reactphp.org:1:1', $message, 3600);

        $executor = new CachingExecutor($fallback, $cache);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        $promise->then($this->expectCallableOnceWith($message), $this->expectCallableNever());
    }

    public function testQueryWillReturnResolvedPromiseWhenCacheReturnsMissAndFallbackExecutorResolvesAndSaveMessageToCacheWithDefaultTtl(): void
    {
        $message = new Message();
        $fallback = $this->createMock(ExecutorInterface::class);
        $fallback->expects($this->once())->method('query')->willReturn(resolve($message));

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->with('reactphp.org:1:1')->willReturn(resolve(null));
        $cache->expects($this->once())->method('set')->with('reactphp.org:1:1', $message, 60);

        $executor = new CachingExecutor($fallback, $cache);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        $promise->then($this->expectCallableOnceWith($message), $this->expectCallableNever());
    }

    public function testQueryWillReturnResolvedPromiseWhenCacheReturnsMissAndFallbackExecutorResolvesWithTruncatedResponseButShouldNotSaveTruncatedMessageToCache(): void
    {
        $message = new Message();
        $message->tc = true;
        $fallback = $this->createMock(ExecutorInterface::class);
        $fallback->expects($this->once())->method('query')->willReturn(resolve($message));

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->with('reactphp.org:1:1')->willReturn(resolve(null));
        $cache->expects($this->never())->method('set');

        $executor = new CachingExecutor($fallback, $cache);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);

        $promise->then($this->expectCallableOnceWith($message), $this->expectCallableNever());
    }

    public function testQueryWillReturnRejectedPromiseWhenCacheReturnsMissAndFallbackExecutorRejects(): void
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $fallback = $this->createMock(ExecutorInterface::class);
        $fallback->expects($this->once())->method('query')->willReturn(reject($exception = new \RuntimeException()));

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->willReturn(resolve(null));

        $executor = new CachingExecutor($fallback, $cache);

        $promise = $executor->query($query);

        $promise->then($this->expectCallableNever(), $this->expectCallableOnceWith($exception));
    }

    public function testCancelQueryWillReturnRejectedPromiseAndCancelPendingPromiseFromCache(): void
    {
        $fallback = $this->createMock(ExecutorInterface::class);
        $fallback->expects($this->never())->method('query');

        $pending = new Promise(function () { }, $this->expectCallableOnce());
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->with('reactphp.org:1:1')->willReturn($pending);

        $executor = new CachingExecutor($fallback, $cache);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for reactphp.org (A) has been cancelled', $exception->getMessage());
    }

    public function testCancelQueryWillReturnRejectedPromiseAndCancelPendingPromiseFromFallbackExecutorWhenCacheReturnsMiss(): void
    {
        $pending = new Promise(function () { }, $this->expectCallableOnce());
        $fallback = $this->createMock(ExecutorInterface::class);
        $fallback->expects($this->once())->method('query')->willReturn($pending);

        $deferred = new Deferred();
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->with('reactphp.org:1:1')->willReturn($deferred->promise());

        $executor = new CachingExecutor($fallback, $cache);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $promise = $executor->query($query);
        $deferred->resolve(null);
        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('DNS query for reactphp.org (A) has been cancelled', $exception->getMessage());
    }
}
