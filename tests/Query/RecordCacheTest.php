<?php

namespace React\Tests\Dns\Query;

use PHPUnit\Framework\TestCase;
use React\Cache\ArrayCache;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\RecordCache;
use React\Dns\Query\Query;
use React\Promise\PromiseInterface;
use React\Promise\Promise;

class RecordCacheTest extends TestCase
{
    /**
     * @covers React\Dns\Query\RecordCache
     * @test
     */
    public function lookupOnCacheMissShouldReturnNull()
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        $base = $this->getMockBuilder('React\Cache\CacheInterface')->getMock();
        $base->expects($this->once())->method('get')->willReturn(\React\Promise\resolve(null));

        $cache = new RecordCache($base);
        $promise = $cache->lookup($query);

        $this->assertInstanceOf('React\Promise\RejectedPromise', $promise);
    }

    /**
     * @covers React\Dns\Query\RecordCache
     * @test
     */
    public function storeRecordPendingCacheDoesNotSetCache()
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $pending = new Promise(function () { });

        $base = $this->getMockBuilder('React\Cache\CacheInterface')->getMock();
        $base->expects($this->once())->method('get')->willReturn($pending);
        $base->expects($this->never())->method('set');

        $cache = new RecordCache($base);
        $cache->storeRecord($query->currentTime, new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'));
    }

    /**
     * @covers React\Dns\Query\RecordCache
     * @test
     */
    public function storeRecordOnCacheMissSetsCache()
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        $base = $this->getMockBuilder('React\Cache\CacheInterface')->getMock();
        $base->expects($this->once())->method('get')->willReturn(\React\Promise\resolve(null));
        $base->expects($this->once())->method('set')->with($this->isType('string'), $this->isType('string'));

        $cache = new RecordCache($base);
        $cache->storeRecord($query->currentTime, new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'));
    }

    /**
    * @covers React\Dns\Query\RecordCache
    * @test
    */
    public function storeRecordShouldMakeLookupSucceed()
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        $cache = new RecordCache(new ArrayCache());
        $cache->storeRecord($query->currentTime, new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'));
        $promise = $cache->lookup($query);

        $this->assertInstanceOf('React\Promise\FulfilledPromise', $promise);
        $cachedRecords = $this->getPromiseValue($promise);

        $this->assertCount(1, $cachedRecords);
        $this->assertSame('178.79.169.131', $cachedRecords[0]->data);
    }

    /**
    * @covers React\Dns\Query\RecordCache
    * @test
    */
    public function storeTwoRecordsShouldReturnBoth()
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        $cache = new RecordCache(new ArrayCache());
        $cache->storeRecord($query->currentTime, new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'));
        $cache->storeRecord($query->currentTime, new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.132'));
        $promise = $cache->lookup($query);

        $this->assertInstanceOf('React\Promise\FulfilledPromise', $promise);
        $cachedRecords = $this->getPromiseValue($promise);

        $this->assertCount(2, $cachedRecords);
        $this->assertSame('178.79.169.131', $cachedRecords[0]->data);
        $this->assertSame('178.79.169.132', $cachedRecords[1]->data);
    }

    /**
    * @covers React\Dns\Query\RecordCache
    * @test
    */
    public function storeResponseMessageShouldStoreAllAnswerValues()
    {
        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);

        $response = new Message();
        $response->answers[] = new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131');
        $response->answers[] = new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.132');
        $response->prepare();

        $cache = new RecordCache(new ArrayCache());
        $cache->storeResponseMessage($query->currentTime, $response);
        $promise = $cache->lookup($query);

        $this->assertInstanceOf('React\Promise\FulfilledPromise', $promise);
        $cachedRecords = $this->getPromiseValue($promise);

        $this->assertCount(2, $cachedRecords);
        $this->assertSame('178.79.169.131', $cachedRecords[0]->data);
        $this->assertSame('178.79.169.132', $cachedRecords[1]->data);
    }

    /**
    * @covers React\Dns\Query\RecordCache
    * @test
    */
    public function expireShouldExpireDeadRecords()
    {
        $cachedTime = 1345656451;
        $currentTime = $cachedTime + 3605;

        $cache = new RecordCache(new ArrayCache());
        $cache->storeRecord($cachedTime, new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'));
        $cache->expire($currentTime);

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, $currentTime);
        $promise = $cache->lookup($query);

        $this->assertInstanceOf('React\Promise\RejectedPromise', $promise);
    }

    private function getPromiseValue(PromiseInterface $promise)
    {
        $capturedValue = null;

        $promise->then(function ($value) use (&$capturedValue) {
            $capturedValue = $value;
        });

        return $capturedValue;
    }
}
