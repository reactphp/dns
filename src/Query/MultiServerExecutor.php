<?php

namespace React\Dns\Query;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\CancellablePromiseInterface;
use React\Promise\Promise;
use RuntimeException;

/**
 * Resolves hosts in a Happy Eye Balls fashion by spreading queries out over multiple executors.
 *
 * Wraps existing `ExecutorInterface`'s and delegates sending queries to them in order. In order
 * to prevent overloading the first server(s) in the list each query the starting point in the list
 * moves one step with each query and wraps when it reaches the end. Once one of of the servers
 * responds with a successful query all pending queries will be canceled and sending out new queries
 * will be stopped. Any unsuccessful queries will be ignored until the last one, that error will be
 * passed up the chain as reason for look up failure. Between each query there is an delay of about
 * 50ms giving the first contacted server time to respond.
 *
 * This executor accepts an array of an arbitrary number of executors as long as it's more then zero:
 *
 * ```php
 * $executor = new MultiServerExecutor(
 *     array(
 *         new UdpTransportExecutor('1.1.1.1, $loop),
 *         new UdpTransportExecutor('8.8.8.8, $loop),
 *     )
 *     $loop
 * );
 * ```
 *
 * @link https://tools.ietf.org/html/rfc8305#section-3.1
 */
final class MultiServerExecutor implements ExecutorInterface
{
    /**
     * @var ExecutorInterface[]
     */
    public $executors = array();

    private $loop;

    public $executorsCount = 0;
    private $executorsPosition = 0;

    /**
     * @param ExecutorInterface[] $executors
     * @param LoopInterface $loop
     */
    public function __construct($executors, LoopInterface $loop)
    {
        $this->executors = array_filter($executors, function ($executor) {
            return $executor instanceof ExecutorInterface;
        });
        $this->executorsCount = \count($this->executors);
        if ($this->executorsCount <= 0) {
            throw new RuntimeException('No executors provided');
        }
        $this->loop = $loop;
    }

    public function query(Query $query)
    {
        $executorsLeft = $this->executorsCount;
        $executorsPosition = $this->executorsPosition++;

        if ($this->executorsPosition >= $this->executorsCount) {
            $this->executorsPosition = 0;
        }

        $that = $this;
        $loop = $this->loop;
        $promises = array();
        $timer = null;
        $success = false;
        return new Promise(function ($resolve, $reject) use (&$promises, &$timer, &$executorsLeft, &$executorsPosition, &$success, $that, $loop, $query) {
            $resolveWrap = function ($index) use (&$promises, &$timer, &$success, $resolve, $loop) {
                return function ($result) use ($index, &$promises, &$timer, &$success, $resolve, $loop) {
                    $success = true;
                    unset($promises[$index]);

                    if ($timer instanceof TimerInterface) {
                        $loop->cancelTimer($timer);
                        $timer = null;
                    }

                    foreach ($promises as $promise) {
                        if ($promise instanceof CancellablePromiseInterface) {
                            $promise->cancel();
                        }
                    }

                    $resolve($result);
                };
            };
            $rejectWrap = function ($index) use (&$promises, &$timer, &$executorsLeft, &$success, $reject, $loop) {
                return function ($error) use ($index, &$promises, &$timer, &$executorsLeft, &$success, $reject, $loop) {
                    unset($promises[$index]);

                    if (\count($promises) > 0 || $executorsLeft > 0 || $success === true) {
                        return;
                    }

                    $reject($error);
                };
            };

            $promise = $that->executors[$executorsPosition]->query($query);
            $promise->then($resolveWrap($executorsPosition), $rejectWrap($executorsPosition));
            $promises[$executorsPosition] = $promise;

            $executorsPosition++;
            $executorsLeft--;

            if ($executorsPosition >= $that->executorsCount) {
                $executorsPosition = 0;
            }

            if ($executorsLeft <= 0) {
                return;
            }

            $timer = $loop->addPeriodicTimer(0.05, function () use (&$promises, &$timer, &$executorsLeft, &$executorsPosition, $that, $loop, $query, $resolveWrap, $rejectWrap) {
                $promise = $that->executors[$executorsPosition]->query($query);
                $promise->then($resolveWrap($executorsPosition), $rejectWrap($executorsPosition));
                $promises[$executorsPosition] = $promise;

                $executorsPosition++;
                $executorsLeft--;

                if ($executorsPosition >= $that->executorsCount) {
                    $executorsPosition = 0;
                }

                if ($executorsLeft <= 0) {
                    $loop->cancelTimer($timer);
                    $timer = null;
                }
            });
        }, function ($_, $reject) use (&$promises, &$timer, $loop) {
            if ($timer instanceof TimerInterface) {
                $loop->cancelTimer($timer);
            }

            foreach ($promises as $promise) {
                if ($promise instanceof CancellablePromiseInterface) {
                    $promise->cancel();
                }
            }

            $reject(new RuntimeException('Lookup query has been canceled'));
        });
    }
}
