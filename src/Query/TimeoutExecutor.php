<?php

namespace React\Dns\Query;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\CancellablePromiseInterface;

class TimeoutExecutor implements ExecutorInterface
{
    private $executor;
    private $loop;
    private $timeout;

    public function __construct(ExecutorInterface $executor, $timeout, LoopInterface $loop)
    {
        $this->executor = $executor;
        $this->loop = $loop;
        $this->timeout = $timeout;
    }

    public function query($nameserver, Query $query)
    {
        $name = $query->name;

        $deferred = new Deferred(function ($resolve, $reject) use (&$promise) {
            $reject(new CancellationException('Cancelled'));
            if ($promise instanceof CancellablePromiseInterface) {
                $promise->cancel();
            }
        });

        $timer = $this->loop->addTimer($this->timeout, function () use ($name, $deferred, &$promise) {
            $deferred->reject(new TimeoutException(sprintf("DNS query for %s timed out", $name)));
            if ($promise instanceof CancellablePromiseInterface) {
                $promise->cancel();
            }
        });

        $promise = $this->executor->query($nameserver, $query);

        $promise->then(
            function ($result) use ($timer, $deferred) {
                $timer->cancel();
                $deferred->resolve($result);
            },
            function ($e) use ($timer, $deferred) {
                $timer->cancel();
                $deferred->reject($e);
            }
        );

        return $deferred->promise();
    }
}
