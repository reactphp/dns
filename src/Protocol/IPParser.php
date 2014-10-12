<?php

namespace React\Dns\Protocol;

/**
 * IP conversion
 */
class IPParser
{
    /**
     * Returns IP version (IPv6 or IPv4)
     * @param $ip
     */
    public function version($ip)
    {
        $version = 'IPv6';
        if (strpos($ip, '.') !== false)
            $version = 'IPv4';

        return $version;
    }

    /**
     * Checks if Ip is v4
     */
    public function isIPv4($ip)
    {
        return $this->version($ip) == 'IPv4';
    }

    /**
     * Checks if Ip is v6
     */
    public function isIPv6($ip)
    {
        return $this->version($ip) == 'IPv6';
    }

    /**
     * Converts Ipv4 to ARPA
     *
     * @param $ip
     * @return string arpa
     */
    public function getIPv4ToARPA($ip)
    {
        return strrev($ip) . '.in-addr.arpa';
    }

    /**
     * Converts Ipv6 to ARPA
     * @url http://stackoverflow.com/a/6621473/394870 <Alnitak>
     * @param $ip
     * @return string arpa
     */
    public function getIPv6ToARPA($ip)
    {
        $addr = inet_pton($ip);
        $unpack = unpack('H*hex', $addr);
        $hex = $unpack['hex'];

        return implode('.', array_reverse(str_split($hex))) . '.ip6.arpa';
    }
}