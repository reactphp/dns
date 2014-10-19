<?php

namespace React\Dns\Protocol;

class IpParser
{
    public function version($ip)
    {
        $version = 'IPv6';

        if (strpos($ip, '.') !== false) {
            $version = 'IPv4';
        }

        return $version;
    }

    public function isIPv4($ip)
    {
        return $this->version($ip) == 'IPv4';
    }

    public function isIPv6($ip)
    {
        return $this->version($ip) == 'IPv6';
    }

    public function getIPv4ToARPA($ip)
    {
        return strrev($ip) . '.in-addr.arpa';
    }

    public function getIPv6ToARPA($ip)
    {
        $addr = inet_pton($ip);
        $unpack = unpack('H*hex', $addr);
        $hex = $unpack['hex'];

        return implode('.', array_reverse(str_split($hex))) . '.ip6.arpa';
    }
}