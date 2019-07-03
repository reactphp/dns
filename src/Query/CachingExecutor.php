<?php

namespace React\Dns\Query;

use React\Cache\CacheInterface;
use React\Dns\Model\Message;
use React\Promise\Promise;

class CachingExecutor implements ExecutorInterface
{
    /**
     * Initial implementation uses a fixed TTL for postive DNS responses as well
     * as negative responses (NXDOMAIN etc.).
     *
     * @internal
     */
    const TTL = 60;

    private $executor;
    private $cache;

    public function __construct(ExecutorInterface $executor, CacheInterface $cache)
    {
        $this->executor = $executor;
        $this->cache = $cache;
    }

    public function query($nameserver, Query $query)
    {
        $id = $query->name . ':' . $query->type . ':' . $query->class;
        $cache = $this->cache;
        $executor = $this->executor;

        $pending = $cache->get($id);
        return new Promise(function ($resolve, $reject) use ($nameserver, $query, $id, $cache, $executor, &$pending) {
            $pending->then(
                function ($message) use ($nameserver, $query, $id, $cache, $executor, &$pending) {
                    // return cached response message on cache hit
                    if ($message !== null) {
                        return $message;
                    }

                    // perform DNS lookup if not already cached
                    return $pending = $executor->query($nameserver, $query)->then(
                        function (Message $message) use ($cache, $id) {
                            // DNS response message received => store in cache and return
                            $cache->set($id, $message, CachingExecutor::TTL);

                            return $message;
                        }
                    );
                }
            )->then($resolve, $reject);
        }, function ($_, $reject) use (&$pending, $query) {
            $reject(new \RuntimeException('DNS query for ' . $query->name . ' has been cancelled'));
            $pending->cancel();
        });
    }
}
