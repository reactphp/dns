<?php

namespace React\Dns\Config;

use RuntimeException;

final class Options
{
    /**
     * @var int<0, 15>
     */
    public $ndots = 1;
    /**
     * @var int<1, 5>
     */
    public $attempts = 2;
    /**
     * @var int<1, 30>
     */
    public $timeout = 5;
}
