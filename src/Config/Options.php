<?php

namespace React\Dns\Config;

final class Options
{
    /**
     * @var int<1, 5>
     */
    public $attempts = 2;
    /**
     * @var int<1, 30>
     */
    public $timeout = 5;
}
