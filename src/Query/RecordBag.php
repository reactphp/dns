<?php

namespace React\Dns\Query;

use React\Dns\Model\Record;

class RecordBag
{
    private $records = array();
    public $expires;

    public function set($currentTime, Record $record)
    {
        $this->records[] = array($currentTime + $record->ttl, $record);
        $this->expires = $currentTime + $record->ttl;
    }

    public function all()
    {
        return array_values(array_map(
            function ($value) {
                list($expiresAt, $record) = $value;
                return $record;
            },
            $this->records
        ));
    }
}
