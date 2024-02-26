<?php

namespace React\Tests\Dns\Config;

use React\Tests\Dns\TestCase;
use React\Dns\Config\Config;

class ConfigTest extends TestCase
{
    public function testLoadsSystemDefault()
    {
        $config = Config::loadSystemConfigBlocking();

        $this->assertInstanceOf('React\Dns\Config\Config', $config);
    }

    public function testLoadsDefaultPath()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Not supported on Windows');
        }

        $config = Config::loadResolvConfBlocking();

        $this->assertInstanceOf('React\Dns\Config\Config', $config);
    }

    public function testLoadsFromExplicitPath()
    {
        $config = Config::loadResolvConfBlocking(__DIR__ . '/../Fixtures/etc/resolv.conf');

        $this->assertEquals(['8.8.8.8'], $config->nameservers);
    }

    public function testLoadThrowsWhenPathIsInvalid()
    {
        $this->setExpectedException('RuntimeException');
        Config::loadResolvConfBlocking(__DIR__ . '/invalid.conf');
    }

    public function testParsesSingleEntryFile()
    {
        $contents = 'nameserver 8.8.8.8';
        $expected = ['8.8.8.8'];

        $config = Config::loadResolvConfBlocking('data://text/plain;base64,' . base64_encode($contents));
        $this->assertEquals($expected, $config->nameservers);
    }

    public function testParsesNameserverWithoutIpv6ScopeId()
    {
        $contents = 'nameserver ::1%lo';
        $expected = ['::1'];

        $config = Config::loadResolvConfBlocking('data://text/plain;base64,' . base64_encode($contents));
        $this->assertEquals($expected, $config->nameservers);
    }

    public function testParsesNameserverEntriesFromAverageFileCorrectly()
    {
        $contents = '#
# Mac OS X Notice
#
# This file is not used by the host name and address resolution
# or the DNS query routing mechanisms used by most processes on
# this Mac OS X system.
#
# This file is automatically generated.
#
domain v.cablecom.net
nameserver 127.0.0.1
nameserver ::1
';
        $expected = ['127.0.0.1', '::1'];

        $config = Config::loadResolvConfBlocking('data://text/plain;base64,' . base64_encode($contents));
        $this->assertEquals($expected, $config->nameservers);
    }

    public function testParsesEmptyFileWithoutNameserverEntries()
    {
        $expected = [];

        $config = Config::loadResolvConfBlocking('data://text/plain;base64,');
        $this->assertEquals($expected, $config->nameservers);
    }

    public function testParsesFileAndIgnoresCommentsAndInvalidNameserverEntries()
    {
        $contents = '
# nameserver 1.2.3.4
; nameserver 2.3.4.5

nameserver 3.4.5.6 # nope
nameserver 4.5.6.7 5.6.7.8
  nameserver 6.7.8.9
NameServer 7.8.9.10
nameserver localhost
';
        $expected = [];

        $config = Config::loadResolvConfBlocking('data://text/plain;base64,' . base64_encode($contents));
        $this->assertEquals($expected, $config->nameservers);
    }

    public function testLoadsFromWmicOnWindows()
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            // WMIC is Windows-only tool and not supported on other platforms
            // Unix is our main platform, so we don't want to report a skipped test here (yellow)
            // $this->markTestSkipped('Only on Windows');
            $this->expectOutputString('');
            return;
        }

        $config = Config::loadWmicBlocking();

        $this->assertInstanceOf('React\Dns\Config\Config', $config);
    }

    public function testLoadsSingleEntryFromWmicOutput()
    {
        $contents = '
Node,DNSServerSearchOrder
ACE,
ACE,{192.168.2.1}
ACE,
';
        $expected = ['192.168.2.1'];

        $config = Config::loadWmicBlocking($this->echoCommand($contents));

        $this->assertEquals($expected, $config->nameservers);
    }

    public function testLoadsEmptyListFromWmicOutput()
    {
        $contents = '
Node,DNSServerSearchOrder
ACE,
';
        $expected = [];

        $config = Config::loadWmicBlocking($this->echoCommand($contents));

        $this->assertEquals($expected, $config->nameservers);
    }

    public function testLoadsSingleEntryForMultipleNicsFromWmicOutput()
    {
        $contents = '
Node,DNSServerSearchOrder
ACE,
ACE,{192.168.2.1}
ACE,
ACE,{192.168.2.2}
ACE,
';
        $expected = ['192.168.2.1', '192.168.2.2'];

        $config = Config::loadWmicBlocking($this->echoCommand($contents));

        $this->assertEquals($expected, $config->nameservers);
    }

    public function testLoadsMultipleEntriesForSingleNicWithSemicolonFromWmicOutput()
    {
        $contents = '
Node,DNSServerSearchOrder
ACE,
ACE,{192.168.2.1;192.168.2.2}
ACE,
';
        $expected = ['192.168.2.1', '192.168.2.2'];

        $config = Config::loadWmicBlocking($this->echoCommand($contents));

        $this->assertEquals($expected, $config->nameservers);
    }

    public function testLoadsMultipleEntriesForSingleNicWithQuotesFromWmicOutput()
    {
        $contents = '
Node,DNSServerSearchOrder
ACE,
ACE,{"192.168.2.1","192.168.2.2"}
ACE,
';
        $expected = ['192.168.2.1', '192.168.2.2'];

        $config = Config::loadWmicBlocking($this->echoCommand($contents));

        $this->assertEquals($expected, $config->nameservers);
    }

    private function echoCommand($output)
    {
        return 'echo ' . escapeshellarg($output);
    }
}
