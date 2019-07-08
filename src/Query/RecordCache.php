<?php

namespace React\Dns\Query;

use React\Cache\CacheInterface;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Promise;
use React\Promise\PromiseInterface;

/**
 * Wraps an underlying cache interface and exposes only cached DNS data
 *
 * @deprecated unused, exists for BC only
 * @see CachingExecutor
 */
class RecordCache
{
    private $cache;
    private $expiredAt;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Looks up the cache if there's a cached answer for the given query
     *
     * @param Query $query
     * @return PromiseInterface Promise<Record[],mixed> resolves with array of Record objects on sucess
     *     or rejects with mixed values when query is not cached already.
     */
    public function lookup(Query $query)
    {
        $id = $this->serializeQueryToIdentity($query);

        $expiredAt = $this->expiredAt;

        return $this->cache
            ->get($id)
            ->then(function ($value) use ($query, $expiredAt) {
                // reject on cache miss
                if ($value === null) {
                    return Promise\reject();
                }

                /* @var $recordBag RecordBag */
                $recordBag = unserialize($value);

                // reject this cache hit if the query was started before the time we expired the cache?
                // todo: this is a legacy left over, this value is never actually set, so this never applies.
                // todo: this should probably validate the cache time instead.
                if (null !== $expiredAt && $expiredAt <= $query->currentTime) {
                    return Promise\reject();
                }

                return $recordBag->all();
            });
    }

    /**
     * Stores all records from this response message in the cache
     *
     * @param int     $currentTime
     * @param Message $message
     * @uses self::storeRecord()
     */
    public function storeResponseMessage($currentTime, Message $message)
    {
        foreach ($message->answers as $record) {
            $this->storeRecord($currentTime, $record);
        }
    }

    /**
     * Stores a single record from a response message in the cache
     *
     * @param int    $currentTime
     * @param Record $record
     */
    public function storeRecord($currentTime, Record $record)
    {
        $id = $this->serializeRecordToIdentity($record);

        $cache = $this->cache;

        $this->cache
            ->get($id)
            ->then(
                function ($value) {
                    // return empty bag on cache miss
                    if ($value === null) {
                        return new RecordBag();
                    }

                    // reuse existing bag on cache hit to append new record to it
                    return unserialize($value);
                }
            )
            ->then(function (RecordBag $recordBag) use ($id, $currentTime, $record, $cache) {
                // add a record to the existing (possibly empty) record bag and save to cache
                $recordBag->set($currentTime, $record);
                $cache->set($id, serialize($recordBag));
            });
    }

    public function expire($currentTime)
    {
        $this->expiredAt = $currentTime;
    }

    public function serializeQueryToIdentity(Query $query)
    {
        return sprintf('%s:%s:%s', $query->name, $query->type, $query->class);
    }

    public function serializeRecordToIdentity(Record $record)
    {
        return sprintf('%s:%s:%s', $record->name, $record->type, $record->class);
    }
}
