<?php

namespace React\Dns\Query;

use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

final class TimeoutExecutor implements ExecutorInterface
{
    /**
     * @var ExecutorInterface
     */
    private $executor;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var float|int
     */
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

        /**
         * @var Promise<Message>
         */
        return new Promise(function ($resolve, $reject) use ($promise, $query): void {
            /**
             * @var null|false|TimerInterface $timer
             */
            $timer = null;
            $promise = $promise->then(function (Message $v) use (&$timer, $resolve): void {
                if ($timer) {
                    $this->loop->cancelTimer($timer);
                }
                $timer = false;
                $resolve($v);
            }, function (\Throwable $v) use (&$timer, $reject): void {
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
            $timer = $this->loop->addTimer($this->timeout, static function () use (&$promise, $reject, $query): void {
                $reject(new TimeoutException(
                    'DNS query for ' . $query->describe() . ' timed out'
                ));

                // Cancel pending query to clean up any underlying resources and references.
                // Avoid garbage references in call stack by passing pending promise by reference.
                assert(\method_exists($promise, 'cancel')); /** @phpstan-ignore-line $pending will never be null when we reach this */
                $promise->cancel();
                $promise = null;
            });
        }, static function () use (&$promise): void {
            // Cancelling this promise will cancel the pending query, thus triggering the rejection logic above.
            // Avoid garbage references in call stack by passing pending promise by reference.
            assert(\method_exists($promise, 'cancel')); /** @phpstan-ignore-line $pending will never be null when we reach this */
            $promise->cancel();
            $promise = null;
        });
    }
}
