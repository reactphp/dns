<?php

namespace React\Dns\Model;

class MessageMeta
{
    private $startMTime = 0;                // micro time at the time of query
    public $execTime = 0;                   // execution time in milliseconds

    public function __construct()
    {
        $this->startMTime = microtime();
    }

    /**
     * Sets exectime
     */
    public function markEndTime()
    {
        if (!$this->execTime) {
            list($a_dec, $a_sec) = explode(" ", $this->startMTime);
            list($b_dec, $b_sec) = explode(" ", microtime());
            $this->execTime = round(($b_sec - $a_sec + $b_dec - $a_dec) * 1000, 0);
        }
    }
}
