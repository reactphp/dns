<?php

namespace React\Dns\Config;

use RuntimeException;

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
     * try to parse its output. Currently, this will only parse valid nameserver
     * entries from `/etc/resolv.conf` and will ignore all other output without
     * complaining.
     *
     * Note that the previous section implies that this may return an empty
     * `Config` object if no valid nameserver entries can be found.
     *
     * @return self
     * @codeCoverageIgnore
     */
    public static function loadSystemConfigBlocking()
    {
        try {
            return self::loadResolvConfBlocking();
        } catch (RuntimeException $ignored) {
            // return empty config if parsing fails (file not found)
            return new self();
        }
    }

    /**
     * Loads a resolv.conf file (from the given path or default location)
     *
     * Note that this method blocks while loading the given path and should
     * thus be used with care! While this should be relatively fast for normal
     * resolv.conf files, this may be an issue if this file is located on a slow
     * device or contains an excessive number of entries. In particular, this
     * method should only be executed before the loop starts, not while it is
     * running.
     *
     * Note that this method will throw if the given file can not be loaded,
     * such as if it is not readable or does not exist. In particular, this file
     * is not available on Windows.
     *
     * Currently, this will only parse valid "nameserver X" lines from the
     * given file contents. Lines can be commented out with "#" and ";" and
     * invalid lines will be ignored without complaining. See also
     * `man resolv.conf` for more details.
     *
     * Note that the previous section implies that this may return an empty
     * `Config` object if no valid "nameserver X" lines can be found. See also
     * `man resolv.conf` which suggests that the DNS server on the localhost
     * should be used in this case. This is left up to higher level consumers
     * of this API.
     *
     * @param ?string $path (optional) path to resolv.conf file or null=load default location
     * @return self
     * @throws RuntimeException if the path can not be loaded (does not exist)
     */
    public static function loadResolvConfBlocking($path = null)
    {
        if ($path === null) {
            $path = '/etc/resolv.conf';
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to load resolv.conf file "' . $path . '"');
        }

        preg_match_all('/^nameserver\s+(\S+)\s*$/m', $contents, $matches);

        $config = new self();
        $config->nameservers = $matches[1];

        return $config;
    }

    public $nameservers = array();
}
