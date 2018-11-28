<?php

namespace React\Dns\Query;

use React\Cache\CacheInterface;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Promise;
use React\Promise\PromiseInterface;

/**
 * Wraps an underlying cache interface and exposes only cached DNS data
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
                // cache 0.5+ resolves with null on cache miss, return explicit cache miss here
                if ($value === null) {
                    return Promise\reject();
                }

                $recordBag = unserialize($value);

                // reject this cache hit if the query was started before the time we expired the cache?
                // todo: this is a legacy left over, this value is never actually set, so this never applies.
                // todo: this should probably validate the cache time instead.
                if (null !== $expiredAt && $expiredAt <= $query->currentTime) {
                    return Promise\reject();
                }

                return $recordBag;
            });
    }

    /**
     * Stores an array containing all the records that were necessary to resolve the $query.
     *
     * @param Query $query
     * @param array $records
     * @param $currentTime
     */
    public function storeQueryResolutionRecords(Query $query, array $records, $currentTime)
    {
        $bags = $this->prepareRecordBags($records, $currentTime);

        $this->cache->set($this->serializeQueryToIdentity($query), serialize($bags));
    }

    /**
     * Aggregates records of the same identity on its corresponding RecordBag.
     *
     * @uses RecordCache::serializeRecordToIdentity() to check the identity of a record.
     *
     * @param Record[] $records
     * @param $currentTime
     * @return array Array in which the key is the identity of the bag and value is the RecordBag
     */
    private function prepareRecordBags(array $records, $currentTime): array
    {
        $recordBags = [];
        foreach ($records as $record) {
            $identity = $this->serializeRecordToIdentity($record);

            if (isset($recordBags[$identity])) {
                $recordBags[$identity]->set($currentTime, $record);
            } else {
                $recordBags[$identity] = new RecordBag();
                $recordBags[$identity]->set($currentTime, $record);
            }
        }

        return $recordBags;
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
        $record = $this->prepareRecordBags($message->answers, $currentTime);

        foreach ($record as $bagIdentity => $recordBag) {
            $this->cache->set($bagIdentity, serialize($recordBag), $recordBag->expires);
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
                    if ($value === null) {
                        // cache 0.5+ cache miss resolves with null, return empty bag here
                        return new RecordBag();
                    }

                    // reuse existing bag on cache hit to append new record to it
                    return unserialize($value);
                },
                function ($e) {
                    // legacy cache < 0.5 cache miss rejects promise, return empty bag here
                    return new RecordBag();
                }
            )
            ->then(function (RecordBag $recordBag) use ($id, $currentTime, $record, $cache) {
                // add a record to the existing (possibly empty) record bag and save to cache
                $recordBag->add($currentTime, $record);
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
