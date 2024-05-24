<?php

namespace React\Tests\Dns\Config;

use React\Dns\Config\HostsFile;
use React\Tests\Dns\TestCase;

class HostsFileTest extends TestCase
{
    public function testLoadsFromDefaultPath(): void
    {
        $hosts = HostsFile::loadFromPathBlocking();

        $this->assertInstanceOf(HostsFile::class, $hosts);
    }

    public function testDefaultShouldHaveLocalhostMapped(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Not supported on Windows');
        }

        $hosts = HostsFile::loadFromPathBlocking();

        $this->assertContains('127.0.0.1', $hosts->getIpsForHost('localhost'));
    }

    public function testLoadThrowsForInvalidPath(): void
    {
        $this->expectException(\RuntimeException::class);
        HostsFile::loadFromPathBlocking('does/not/exist');
    }

    public function testContainsSingleLocalhostEntry(): void
    {
        $hosts = new HostsFile('127.0.0.1 localhost');

        $this->assertEquals(['127.0.0.1'], $hosts->getIpsForHost('localhost'));
        $this->assertEquals([], $hosts->getIpsForHost('example.com'));
    }

    public function testNonIpReturnsNothingForInvalidHosts(): void
    {
        $hosts = new HostsFile('a b');

        $this->assertEquals([], $hosts->getIpsForHost('a'));
        $this->assertEquals([], $hosts->getIpsForHost('b'));
    }

    public function testIgnoresIpv6ZoneId(): void
    {
        $hosts = new HostsFile('fe80::1%lo0 localhost');

        $this->assertEquals(['fe80::1'], $hosts->getIpsForHost('localhost'));
    }

    public function testSkipsComments(): void
    {
        $hosts = new HostsFile('# start' . PHP_EOL .'#127.0.0.1 localhost' . PHP_EOL . '127.0.0.2 localhost # example.com');

        $this->assertEquals(['127.0.0.2'], $hosts->getIpsForHost('localhost'));
        $this->assertEquals([], $hosts->getIpsForHost('example.com'));
    }

    public function testContainsSingleLocalhostEntryWithCaseIgnored(): void
    {
        $hosts = new HostsFile('127.0.0.1 LocalHost');

        $this->assertEquals(['127.0.0.1'], $hosts->getIpsForHost('LOCALHOST'));
    }

    public function testEmptyFileContainsNothing(): void
    {
        $hosts = new HostsFile('');

        $this->assertEquals([], $hosts->getIpsForHost('example.com'));
    }

    public function testSingleEntryWithMultipleNames(): void
    {
        $hosts = new HostsFile('127.0.0.1 localhost example.com');

        $this->assertEquals(['127.0.0.1'], $hosts->getIpsForHost('example.com'));
        $this->assertEquals(['127.0.0.1'], $hosts->getIpsForHost('localhost'));
    }

    public function testMergesEntriesOverMultipleLines(): void
    {
        $hosts = new HostsFile("127.0.0.1 localhost\n127.0.0.2 localhost\n127.0.0.3 a localhost b\n127.0.0.4 a localhost");

        $this->assertEquals(['127.0.0.1', '127.0.0.2', '127.0.0.3', '127.0.0.4'], $hosts->getIpsForHost('localhost'));
    }

    public function testMergesIpv4AndIpv6EntriesOverMultipleLines(): void
    {
        $hosts = new HostsFile("127.0.0.1 localhost\n::1 localhost");

        $this->assertEquals(['127.0.0.1', '::1'], $hosts->getIpsForHost('localhost'));
    }

    public function testReverseLookup(): void
    {
        $hosts = new HostsFile('127.0.0.1 localhost');

        $this->assertEquals(['localhost'], $hosts->getHostsForIp('127.0.0.1'));
        $this->assertEquals([], $hosts->getHostsForIp('192.168.1.1'));
    }

    public function testReverseSkipsComments(): void
    {
        $hosts = new HostsFile("# start\n#127.0.0.1 localhosted\n127.0.0.2\tlocalhost\t# example.com\n\t127.0.0.3\t\texample.org\t\t");

        $this->assertEquals([], $hosts->getHostsForIp('127.0.0.1'));
        $this->assertEquals(['localhost'], $hosts->getHostsForIp('127.0.0.2'));
        $this->assertEquals(['example.org'], $hosts->getHostsForIp('127.0.0.3'));
    }

    public function testReverseNonIpReturnsNothing(): void
    {
        $hosts = new HostsFile('127.0.0.1 localhost');

        $this->assertEquals([], $hosts->getHostsForIp('localhost'));
        $this->assertEquals([], $hosts->getHostsForIp('127.0.0.1.1'));
    }

    public function testReverseNonIpReturnsNothingForInvalidHosts(): void
    {
        $hosts = new HostsFile('a b');

        $this->assertEquals([], $hosts->getHostsForIp('a'));
        $this->assertEquals([], $hosts->getHostsForIp('b'));
    }

    public function testReverseLookupReturnsLowerCaseHost(): void
    {
        $hosts = new HostsFile('127.0.0.1 LocalHost');

        $this->assertEquals(['localhost'], $hosts->getHostsForIp('127.0.0.1'));
    }

    public function testReverseLookupChecksNormalizedIpv6(): void
    {
        $hosts = new HostsFile('FE80::00a1 localhost');

        $this->assertEquals(['localhost'], $hosts->getHostsForIp('fe80::A1'));
    }

    public function testReverseLookupIgnoresIpv6ZoneId(): void
    {
        $hosts = new HostsFile('fe80::1%lo0 localhost');

        $this->assertEquals(['localhost'], $hosts->getHostsForIp('fe80::1'));
    }

    public function testReverseLookupReturnsMultipleHostsOverSingleLine(): void
    {
        $hosts = new HostsFile("::1 ip6-localhost ip6-loopback");

        $this->assertEquals(['ip6-localhost', 'ip6-loopback'], $hosts->getHostsForIp('::1'));
    }

    public function testReverseLookupReturnsMultipleHostsOverMultipleLines(): void
    {
        $hosts = new HostsFile("::1 ip6-localhost\n::1 ip6-loopback");

        $this->assertEquals(['ip6-localhost', 'ip6-loopback'], $hosts->getHostsForIp('::1'));
    }
}
