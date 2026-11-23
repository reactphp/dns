<?php

namespace React\Dns\Config;

use RuntimeException;

final class Config
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
     * entries from its output and will ignore all other output without
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
        // Use WMIC output on Windows
        if (DIRECTORY_SEPARATOR === '\\') {
            return self::loadWmicBlocking();
        }

        // otherwise (try to) load from resolv.conf
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

        $matches = [];
        preg_match_all('/^nameserver\s+(\S+)\s*$/m', $contents, $matches);

        $config = new self();
        foreach ($matches[1] as $ip) {
            // remove IPv6 zone ID (`fe80::1%lo0` => `fe80:1`)
            if (strpos($ip, ':') !== false && ($pos = strpos($ip, '%')) !== false) {
                $ip = substr($ip, 0, $pos);
            }

            if (@inet_pton($ip) !== false) {
                $config->nameservers[] = $ip;
            }
        }

        $matches = [];
        preg_match_all('/^search.*\s*$/m', $contents, $matches);
        if (count($matches) > 0 && count($matches[0]) > 0 && isset($matches[0][count($matches[0]) - 1])) {
            $searches = preg_split('/\s+/', trim($matches[0][count($matches[0]) - 1]));
            unset($searches[0]);
            $config->search = array_values($searches);
        }

        $matches = [];
        preg_match_all('/^options.*\s*$/m', $contents, $matches);
        if (isset($matches[0][0])) {
            $options = preg_split('/\s+/', trim($matches[0][0]));
            array_shift($options);

            foreach ($options as $option) {
                $value = null;
                if (strpos($option, ':') !== false) {
                    [$option, $value] = explode(':', $option, 2);
                }

                switch ($option) {
                    case 'ndots':
                        $config->options->ndots = ((int) $value) > 15 ? 15 : (int) $value;
                        break;
                    case 'attempts':
                        $config->options->attempts = ((int) $value) > 5 ? 5 : (int) $value;
                        break;
                    case 'timeout':
                        $config->options->timeout = ((int) $value) > 30 ? 30 : (int) $value;
                        break;
                }
            }
        }

        return $config;
    }

    /**
     * Loads the DNS configurations from Windows's WMIC (from the given command or default command)
     *
     * Note that this method blocks while loading the given command and should
     * thus be used with care! While this should be relatively fast for normal
     * WMIC commands, it remains unknown if this may block under certain
     * circumstances. In particular, this method should only be executed before
     * the loop starts, not while it is running.
     *
     * Note that this method will only try to execute the given command try to
     * parse its output, irrespective of whether this command exists. In
     * particular, this command is only available on Windows. Currently, this
     * will only parse valid nameserver entries from the command output and will
     * ignore all other output without complaining.
     *
     * Note that the previous section implies that this may return an empty
     * `Config` object if no valid nameserver entries can be found.
     *
     * @param ?string $command (advanced) should not be given (NULL) unless you know what you're doing
     * @return self
     * @link https://ss64.com/nt/wmic.html
     */
    public static function loadWmicBlocking($command = null)
    {
        $contents = shell_exec($command === null ? 'wmic NICCONFIG get "DNSServerSearchOrder" /format:CSV' : $command);
        preg_match_all('/(?<=[{;,"])([\da-f.:]{4,})(?=[};,"])/i', $contents, $matches);

        $config = new self();
        $config->nameservers = $matches[1];

        return $config;
    }

    public $nameservers = [];
    /**
     * @var array<string>
     */
    public $search = [];
    /**
     * @var Options
     */
    public $options;

    public function __construct() {
        $this->options = new Options();
    }
}
