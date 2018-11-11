<?php

namespace React\Dns\Query;

use React\Promise\CancellablePromiseInterface;
use React\Promise\Deferred;

class RetryExecutor implements ExecutorInterface
{
    private $executor;
    private $retries;

    public function __construct(ExecutorInterface $executor, $retries = 2)
    {
        $this->executor = $executor;
        $this->retries = $retries;
    }

    public function query($nameserver, Query $query)
    {
        return $this->tryQuery($nameserver, $query, $this->retries);
    }

    public function tryQuery($nameserver, Query $query, $retries)
    {
        $deferred = new Deferred(function () use (&$promise) {
            if ($promise instanceof CancellablePromiseInterface) {
                $promise->cancel();
            }
        });

        $success = function ($value) use ($deferred, &$errorback) {
            $errorback = null;
            $deferred->resolve($value);
        };

        $executor = $this->executor;
        $errorback = function ($e) use ($deferred, &$promise, $nameserver, $query, $success, &$errorback, &$retries, $executor) {
            if (!$e instanceof TimeoutException) {
                $errorback = null;
                $deferred->reject($e);
            } elseif ($retries <= 0) {
                $errorback = null;
                $deferred->reject($e = new \RuntimeException(
                    'DNS query for ' . $query->name . ' failed: too many retries',
                    0,
                    $e
                ));

                // avoid garbage references by replacing all closures in call stack.
                // what a lovely piece of code!
                $r = new \ReflectionProperty('Exception', 'trace');
                $r->setAccessible(true);
                $trace = $r->getValue($e);
                foreach ($trace as &$one) {
                    foreach ($one['args'] as &$arg) {
                        if ($arg instanceof \Closure) {
                            $arg = 'Object(' . \get_class($arg) . ')';
                        }
                    }
                }
                $r->setValue($e, $trace);
            } else {
                --$retries;
                $promise = $executor->query($nameserver, $query)->then(
                    $success,
                    $errorback
                );
            }
        };

        $promise = $this->executor->query($nameserver, $query)->then(
            $success,
            $errorback
        );

        return $deferred->promise();
    }
}
