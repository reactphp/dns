<?php

namespace React\Dns\Query;

use React\Dns\Model\Record;

/**
 * @deprecated unused, exists for BC only
 * @see CachingExecutor
 */
class RecordBag
{
    private $records = array();

    public function set($currentTime, Record $record)
    {
        $this->records[] = array($currentTime + $record->ttl, $record);
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
