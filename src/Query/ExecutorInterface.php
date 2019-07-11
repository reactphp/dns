<?php

namespace React\Dns\Query;

interface ExecutorInterface
{
    public function query(Query $query);
}
