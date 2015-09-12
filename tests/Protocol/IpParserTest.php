<?php

namespace React\Tests\Dns\Protocol;

use React\Dns\Protocol\IpParser;

class IpParserTest extends \PHPUnit_Framework_TestCase
{
    public function testIPv4()
    {
        $parser = new IpParser();

        $arrIps = array(
            '9.9.9.9' => true,
            '187.87.17.19' => true,
            '127.0.0.1' => true,
            '2406:2000:108:4::1006' => false
        );

        foreach ($arrIps as $ip => $expected) {
            $this->assertSame($parser->isIPv4($ip), $expected);
        }
    }

    public function testIPv6()
    {
        $parser = new IpParser();

        $arrIps = array(
            '9.9.9.9' => false,
            '187.87.17.19' => false,
            '127.0.0.1' => false,
            '2406:2000:108:4::1006' => true,
            '4321:0:1:2:3:4:567:89ab' => true
        );

        foreach ($arrIps as $ip => $expected) {
            $this->assertSame($parser->isIPv6($ip), $expected);
        }
    }

    public function testIPv4ToARPA()
    {
        $parser = new IpParser();

        $arrIps = array(
            '9.9.9.9' => '9.9.9.9.in-addr.arpa',
            '187.87.17.19' => '91.71.78.781.in-addr.arpa',
            '127.0.0.1' => '1.0.0.721.in-addr.arpa',
        );

        foreach ($arrIps as $ip => $expected) {
            $this->assertSame($parser->getIPv4ToARPA($ip), $expected);
        }
    }

    public function testIPv6ToARPA()
    {
        $parser = new IpParser();

        $arrIps = array(
            '4321:0:1:2:3:4:567:89ab' => 'b.a.9.8.7.6.5.0.4.0.0.0.3.0.0.0.2.0.0.0.1.0.0.0.0.0.0.0.1.2.3.4.ip6.arpa',
        );

        foreach ($arrIps as $ip => $expected) {
            $this->assertSame($parser->getIPv6ToARPA($ip), $expected);
        }
    }
}