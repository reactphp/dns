<?php

namespace React\Dns\Model;

use React\Dns\Protocol\HumanParser;

class Message
{
    const TYPE_A = 1;
    const TYPE_NS = 2;
    const TYPE_CNAME = 5;
    const TYPE_SOA = 6;
    const TYPE_PTR = 12;
    const TYPE_MX = 15;
    const TYPE_TXT = 16;
    const TYPE_AAAA = 28;
    const TYPE_ANY = 255;

    const CLASS_IN = 1;

    const OPCODE_QUERY = 0;
    const OPCODE_IQUERY = 1; // inverse query
    const OPCODE_STATUS = 2;

    const RCODE_OK = 0;
    const RCODE_FORMAT_ERROR = 1;
    const RCODE_SERVER_FAILURE = 2;
    const RCODE_NAME_ERROR = 3;
    const RCODE_NOT_IMPLEMENTED = 4;
    const RCODE_REFUSED = 5;

    public $data = '';

    public $header;
    public $questions = [];
    public $answers = [];
    public $authority = [];
    public $additional = [];

    public $consumed = 0;
    public $transport = 'udp';
    public $nameserver = '';                // server from which message was resolved
    private $startMTime = 0;                // microtime at the time of query
    public $execTime = 0;                   // execution time in milliseconds

    public function __construct()
    {
        $this->header = new HeaderBag();
        $this->startMTime = microtime();
    }

    public function prepare()
    {
        $this->header->populateCounts($this);
    }

    public function explain()
    {
        return HumanParser::explainMessage($this);
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
