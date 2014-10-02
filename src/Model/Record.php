<?php

namespace React\Dns\Model;

use React\Dns\Protocol\HumanParser;

class Record
{
    public $name;
    public $type;
    public $class;
    public $ttl;
    public $data;
    public $priority;

    public function __construct($name, $type, $class, $ttl = 0, $data = null, $priority = null)
    {
        $this->name     = $name;
        $this->type     = $type;
        $this->class    = $class;
        $this->ttl      = $ttl;
        $this->data     = $data;
        $this->priority = $priority;
    }

    public function explain()
    {
        return HumanParser::explainRecord($this);
    }
}
