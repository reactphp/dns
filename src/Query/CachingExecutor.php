<?php

namespace React\Dns\Query;

use React\Cache\CacheInterface;
use React\Dns\Model\Message;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

final class CachingExecutor implements ExecutorInterface
{
    /**
     * Default TTL for negative responses (NXDOMAIN etc.).
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

    public function query(Query $query): PromiseInterface
    {
        $id = $query->name . ':' . $query->type . ':' . $query->class;

        $pending = $this->cache->get($id);
        return new Promise(function ($resolve, $reject) use ($query, $id, &$pending) {
            $pending->then(
                function ($message) use ($query, $id, &$pending) {
                    // return cached response message on cache hit
                    if ($message !== null) {
                        return $message;
                    }

                    // perform DNS lookup if not already cached
                    return $pending = $this->executor->query($query)->then(
                        function (Message $message) use ($id) {
                            // DNS response message received => store in cache when not truncated and return
                            if (!$message->tc) {
                                $this->cache->set($id, $message, $this->ttl($message));
                            }

                            return $message;
                        }
                    );
                }
            )->then($resolve, function ($e) use ($reject, &$pending) {
                $reject($e);
                $pending = null;
            });
        }, function ($_, $reject) use (&$pending, $query) {
            $reject(new \RuntimeException('DNS query for ' . $query->describe() . ' has been cancelled'));
            $pending->cancel();
            $pending = null;
        });
    }

    /**
     * @param Message $message
     * @return int
     * @internal
     */
    public function ttl(Message $message)
    {
        // select TTL from answers (should all be the same), use smallest value if available
        // @link https://tools.ietf.org/html/rfc2181#section-5.2
        $ttl = null;
        foreach ($message->answers as $answer) {
            if ($ttl === null || $answer->ttl < $ttl) {
                $ttl = $answer->ttl;
            }
        }

        if ($ttl === null) {
            $ttl = self::TTL;
        }

        return $ttl;
    }
}
