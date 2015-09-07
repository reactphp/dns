<?php

namespace React\Dns\Server;

interface ServerInterface
{
    public function listen($port, $host = '127.0.0.1');
    public function ready();
}
