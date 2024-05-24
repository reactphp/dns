<?php

namespace React\Dns\Query;

use React\Dns\Model\Message;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

final class FallbackExecutor implements ExecutorInterface
{
    /**
     * @var ExecutorInterface
     */
    private $executor;

    /**
     * @var ExecutorInterface
     */
    private $fallback;

    public function __construct(ExecutorInterface $executor, ExecutorInterface $fallback)
    {
        $this->executor = $executor;
        $this->fallback = $fallback;
    }

    public function query(Query $query): PromiseInterface
    {
        /** @var bool $cancelled */
        $cancelled = false;
        $promise = $this->executor->query($query);

        /** @var Promise<Message> */
        return new Promise(function ($resolve, $reject) use (&$promise, $query, &$cancelled) {
            $promise->then($resolve, function (\Throwable $e1) use ($query, $resolve, $reject, &$cancelled, &$promise) {
                // reject if primary resolution rejected due to cancellation
                if ($cancelled) {
                    $reject($e1);
                    return;
                }

                // start fallback query if primary query rejected
                $promise = $this->fallback->query($query)->then($resolve, function (\Throwable $e2) use ($e1, $reject) {
                    $append = $e2->getMessage();
                    if (($pos = strpos($append, ':')) !== false) {
                        $append = substr($append, $pos + 2);
                    }

                    // reject with combined error message if both queries fail
                    $reject(new \RuntimeException($e1->getMessage() . '. ' . $append));
                });
            });
        }, function () use (&$promise, &$cancelled) {
            // cancel pending query (primary or fallback)
            $cancelled = true;
            $promise->cancel();
        });
    }
}
