<?php

namespace React\Dns\Model;

class MessageMeta
{
    private $startMTime = 0;                // micro time at the time of query
    public $execTime = 0;                   // execution time in milliseconds

    public function __construct()
    {
        $this->startMTime = microtime(true);
    }

    /**
     * Sets exectime
     */
    public function markEndTime()
    {
        if (!$this->execTime) {
            $this->execTime = round((microtime(true) - $this->startMTime) * 1000, 0);
        }
    }
}
