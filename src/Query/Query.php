<?php

namespace React\Dns\Query;

use React\Dns\Protocol\HumanParser;

class Query
{
    public $name;
    public $type;
    public $class;
    public $currentTime;

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