<?php

namespace React\Dns\Query;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

final class TimeoutExecutor implements ExecutorInterface
{
    private $executor;
    private $loop;
    private $timeout;

    public function __construct(ExecutorInterface $executor, float $timeout, ?LoopInterface $loop = null)
    {
        $this->executor = $executor;
        $this->loop = $loop ?: Loop::get();
        $this->timeout = $timeout;
    }

    public function query(Query $query): PromiseInterface
    {
        $promise = $this->executor->query($query);

        return new Promise(function ($resolve, $reject) use ($promise, $query) {
            $timer = null;
            $promise = $promise->then(function ($v) use (&$timer, $resolve) {
                if ($timer) {
                    $this->loop->cancelTimer($timer);
                }
                $timer = false;
                $resolve($v);
            }, function ($v) use (&$timer, $reject) {
                if ($timer) {
                    $this->loop->cancelTimer($timer);
                }
                $timer = false;
                $reject($v);
            });

            // promise already resolved => no need to start timer
            if ($timer === false) {
                return;
            }

            // start timeout timer which will cancel the pending promise
            $timer = $this->loop->addTimer($this->timeout, function () use (&$promise, $reject, $query) {
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
