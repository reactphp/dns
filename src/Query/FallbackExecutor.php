<?php

namespace React\Dns\Query;

final class FallbackExecutor implements ExecutorInterface
{
    private $executor;
    private $fallback;

    public function __construct(ExecutorInterface $executor, ExecutorInterface $fallback)
    {
        $this->executor = $executor;
        $this->fallback = $fallback;
    }

    public function query(Query $query)
    {
        $fallback = $this->fallback;
        return $this->executor->query($query)->then(null, function (\Exception $e1) use ($query, $fallback) {
            return $fallback->query($query)->then(null, function (\Exception $e2) use ($e1) {
                $append = $e2->getMessage();
                if (($pos = strpos($append, ':')) !== false) {
                    $append = substr($append, $pos + 2);
                }

                throw new \RuntimeException($e1->getMessage() . '. ' . $append);
            });
        });
    }
}
