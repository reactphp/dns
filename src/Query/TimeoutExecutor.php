<?php

namespace React\Dns\Query;

use React\EventLoop\Loop;
use React\Promise\Promise;

final class TimeoutExecutor implements ExecutorInterface
{
    private $executor;
    private $timeout;

    public function __construct(ExecutorInterface $executor, $timeout)
    {
        $this->executor = $executor;
        $this->timeout = $timeout;
    }

    public function query(Query $query)
    {
        $promise = $this->executor->query($query);

        $time = $this->timeout;
        return new Promise(function ($resolve, $reject) use ($time, $promise, $query) {
            $timer = null;
            $promise = $promise->then(function ($v) use (&$timer, $resolve) {
                if ($timer) {
                    Loop::get()->cancelTimer($timer);
                }
                $timer = false;
                $resolve($v);
            }, function ($v) use (&$timer, $reject) {
                if ($timer) {
                    Loop::get()->cancelTimer($timer);
                }
                $timer = false;
                $reject($v);
            });

            // promise already resolved => no need to start timer
            if ($timer === false) {
                return;
            }

            // start timeout timer which will cancel the pending promise
            $timer = Loop::get()->addTimer($time, function () use ($time, &$promise, $reject, $query) {
                $reject(new TimeoutException(
                    'DNS query for ' . $query->describe() . ' timed out'
                ));

                // Cancel pending query to clean up any underlying resources and references.
                // Avoid garbage references in call stack by passing pending promise by reference.
                assert(\method_exists($promise, 'cancel'));
                $promise->cancel();
                $promise = null;
            });
        }, function () use (&$promise) {
            // Cancelling this promise will cancel the pending query, thus triggering the rejection logic above.
            // Avoid garbage references in call stack by passing pending promise by reference.
            assert(\method_exists($promise, 'cancel'));
            $promise->cancel();
            $promise = null;
        });
    }
}
