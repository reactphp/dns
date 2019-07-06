<?php

namespace React\Dns\Query;

class Query
{
    public $name;
    public $type;
    public $class;

    /**
     * @param string $name  query name, i.e. hostname to look up
     * @param int    $type  query type, see Message::TYPE_* constants
     * @param int    $class query class, see Message::CLASS_IN constant
     */
    public function __construct($name, $type, $class)
    {
        $this->name = $name;
        $this->type = $type;
        $this->class = $class;
    }
}
