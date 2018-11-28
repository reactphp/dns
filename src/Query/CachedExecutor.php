<?php

namespace React\Dns\Query;

use React\Dns\Model\Message;
use React\Promise;

class CachedExecutor implements ExecutorInterface
{
    private $executor;
    private $cache;

    public function __construct(ExecutorInterface $executor, RecordCache $cache)
    {
        $this->executor = $executor;
        $this->cache = $cache;
    }

    public function query($nameserver, Query $query)
    {
        $executor = $this->executor;
        $cache = $this->cache;

        return $this->cache
            ->lookup($query)
            ->then(
                function ($cachedRecords) use ($query, $executor, $nameserver, $cache) {
                    if ($cachedRecords instanceof RecordBag) {
                        return Message::createResponseWithAnswersForQuery($query, $cachedRecords->all());
                    }

                    $expiredRecordBagsQueries = [];
                    $validRecordBags = $cachedRecords;

                    foreach ($cachedRecords as $key => $recordBag) {
                        if (time() > $recordBag->expires) {
                            $record = current($recordBag->all());
                            $expiredRecordBagsQueries[] = $executor->query(
                                $nameserver,
                                new Query($record->name, $record->type, $record->class)
                            );
                            unset($validRecordBags[$key]);
                        }
                    }

                    $records = [];
                    foreach ($validRecordBags as $recordBags) {
                        $records = array_merge($records, $recordBags->all());
                    }

                    if (!empty($expiredRecordBagsQueries)) {
                        return Promise\all($expiredRecordBagsQueries)->then(
                            function ($responses) use ($cache, $query, $validRecordBags, $records) {
                                foreach($responses as $response) {
                                    $cache->storeResponseMessage($query->currentTime, $response);
                                    $records = array_merge($records, $response->answers);
                                }

                                $cache->storeQueryResolutionRecords($query, $records, $query->currentTime);
                                return Message::createResponseWithAnswersForQuery($query, $records);
                            }
                        );
                    }

                    return Message::createResponseWithAnswersForQuery($query, $records);
                },
                function () use ($executor, $cache, $nameserver, $query) {
                    return $executor
                        ->query($nameserver, $query)
                        ->then(function ($response) use ($cache, $query) {
                            if (!$this->isQueryTypeAndClassAmongTheAnswers($query, $response->answers)) {
                                $cache->storeQueryResolutionRecords($query, $response->answers, $query->currentTime);
                            }

                            $cache->storeResponseMessage($query->currentTime, $response);
                            return $response;
                        });
                }
            );
    }

    private function isQueryTypeAndClassAmongTheAnswers($query, $answers) {
        foreach ($answers as $answer) {
            if ($answer->name === $query->name &&
                $answer->type === $query->type &&
                $answer->class === $query->class
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @deprecated unused, exists for BC only
     */
    public function buildResponse(Query $query, array $cachedRecords)
    {
        return Message::createResponseWithAnswersForQuery($query, $cachedRecords);
    }

    /**
     * @deprecated unused, exists for BC only
     */
    protected function generateId()
    {
        return mt_rand(0, 0xffff);
    }
}
