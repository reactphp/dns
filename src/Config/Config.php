<?php

namespace React\Dns\Config;

class Config
{
    /**
     * Loads the system DNS configuration
     *
     * Note that this method may block while loading its internal files and/or
     * commands and should thus be used with care! While this should be
     * relatively fast for most systems, it remains unknown if this may block
     * under certain circumstances. In particular, this method should only be
     * executed before the loop starts, not while it is running.
     *
     * Note that this method will try to access its files and/or commands and
     * try to parse its output. Currently, this is only a placeholder that does
     * not actually parse any nameserver entries.
     *
     * Note that the previous section implies that this may return an empty
     * `Config` object if no valid nameserver entries can be found.
     *
     * @return self
     */
    public static function loadSystemConfigBlocking()
    {
        return new self();
    }

    public $nameservers = array();
}
