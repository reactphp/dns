<?php

namespace React\Dns\Query;

use React\Dns\Model\Message;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Timer;

final class TimeoutExecutor implements ExecutorInterface
{
    private $executor;
    private $loop;
    private $timeout;
    private $config;

    public function __construct(ExecutorInterface $executor, $timeout, LoopInterface $loop = null)
    {
        $this->executor = $executor;
        $this->loop = $loop ?: Loop::get();
        $this->timeout = $timeout;
        $this->config = \React\Dns\Config\Config::loadSystemConfigBlocking();
    }

    public function query(Query $query)
    {
        return $this->tryQuery($query, $query->name);
    }
    public function tryQuery(Query $query,$queryName="", $index=0){
        $obj = $this;
        return Timer\timeout($this->executor->query($query), $this->timeout, $this->loop)->then(null, function ($e) use ($query, $queryName, $index, $obj) {
            if ($e instanceof Timer\TimeoutException) {
                $e = new TimeoutException(sprintf("DNS query for %s timed out", $query->describe()), 0, $e);
            }
            //if Non-Existent Domain / NXDOMAIN, append domain option and retry
            if ($e->getCode() == Message::RCODE_NAME_ERROR&&isset($obj->config->searches[$index])) {
                $query->name = $queryName.".".$obj->config->searches[$index];
                echo $query->name;
                $index++;
                return $obj->tryQuery($query, $queryName, $index);
            }
            throw $e;
        });
    }
}
