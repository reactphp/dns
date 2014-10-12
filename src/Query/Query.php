<?php

namespace React\Dns\Query;

use React\Dns\Protocol\HumanParser;

class Query
{
    public $name;
    public $type;
    public $class;
    public $currentTime;
    public $attempts = 0;            // number of tries UDP + TCP
    public $transport = 'udp';       // this is switched to TCP when UDP size exceeds
    public $nameserver = '';         // server from which message was resolved

    public function __construct($name, $type, $class, $currentTime)
    {
        $this->name = $name;
        $this->type = HumanParser::human2Type($type);
        $this->class = $class;
        $this->currentTime = $currentTime;
    }

    public function explain()
    {
        return HumanParser::explainQuery($this);
    }
}