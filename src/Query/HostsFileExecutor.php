<?php

namespace React\Dns\Query;

use React\Dns\Config\HostsFile;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Promise;

/**
 * Resolves hosts from the givne HostsFile or falls back to another executor
 *
 * If the host is found in the hosts file, it will not be passed to the actual
 * DNS executor. If the host is not found in the hosts file, it will be passed
 * to the DNS executor as a fallback.
 */
class HostsFileExecutor implements ExecutorInterface
{
    private $hosts;
    private $fallback;

    public function __construct(HostsFile $hosts, ExecutorInterface $fallback)
    {
        $this->hosts = $hosts;
        $this->fallback = $fallback;
    }

    public function query($nameserver, Query $query)
    {
        if ($query->class === Message::CLASS_IN && ($query->type === Message::TYPE_A || $query->type === Message::TYPE_AAAA)) {
            $records = array();
            $expectsColon = $query->type === Message::TYPE_AAAA;
            foreach ($this->hosts->getIpsForHost($query->name) as $ip) {
                // ensure this is an IPv4/IPV6 address according to query type
                if ((strpos($ip, ':') !== false) === $expectsColon) {
                    $records[] = new Record($query->name, $query->type, $query->class, 0, $ip);
                }
            }

            if ($records) {
                return Promise\resolve(
                    Message::createResponseWithAnswersForQuery($query, $records)
                );
            }
        }

        return $this->fallback->query($nameserver, $query);
    }
}
