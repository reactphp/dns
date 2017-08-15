<?php

namespace React\Tests\Dns\Query;

use React\Tests\Dns\TestCase;
use React\Dns\Query\HostsFileExecutor;
use React\Dns\Query\Query;
use React\Dns\Model\Message;

class HostsFileExecutorTest extends TestCase
{
    private $hosts;
    private $fallback;
    private $executor;

    public function setUp()
    {
        $this->hosts = $this->getMockBuilder('React\Dns\Config\HostsFile')->disableOriginalConstructor()->getMock();
        $this->fallback = $this->getMockBuilder('React\Dns\Query\ExecutorInterface')->getMock();
        $this->executor = new HostsFileExecutor($this->hosts, $this->fallback);
    }

    public function testDoesNotTryToGetIpsForMxQuery()
    {
        $this->hosts->expects($this->never())->method('getIpsForHost');
        $this->fallback->expects($this->once())->method('query');

        $this->executor->query('8.8.8.8', new Query('google.com', Message::TYPE_MX, Message::CLASS_IN, 0));
    }

    public function testFallsBackIfNoIpsWereFound()
    {
        $this->hosts->expects($this->once())->method('getIpsForHost')->willReturn(array());
        $this->fallback->expects($this->once())->method('query');

        $this->executor->query('8.8.8.8', new Query('google.com', Message::TYPE_A, Message::CLASS_IN, 0));
    }

    public function testReturnsResponseMessageIfIpsWereFound()
    {
        $this->hosts->expects($this->once())->method('getIpsForHost')->willReturn(array('127.0.0.1'));
        $this->fallback->expects($this->never())->method('query');

        $ret = $this->executor->query('8.8.8.8', new Query('google.com', Message::TYPE_A, Message::CLASS_IN, 0));
    }

    public function testFallsBackIfNoIpv4Matches()
    {
        $this->hosts->expects($this->once())->method('getIpsForHost')->willReturn(array('::1'));
        $this->fallback->expects($this->once())->method('query');

        $ret = $this->executor->query('8.8.8.8', new Query('google.com', Message::TYPE_A, Message::CLASS_IN, 0));
    }

    public function testReturnsResponseMessageIfIpv6AddressesWereFound()
    {
        $this->hosts->expects($this->once())->method('getIpsForHost')->willReturn(array('::1'));
        $this->fallback->expects($this->never())->method('query');

        $ret = $this->executor->query('8.8.8.8', new Query('google.com', Message::TYPE_AAAA, Message::CLASS_IN, 0));
    }

    public function testFallsBackIfNoIpv6Matches()
    {
        $this->hosts->expects($this->once())->method('getIpsForHost')->willReturn(array('127.0.0.1'));
        $this->fallback->expects($this->once())->method('query');

        $ret = $this->executor->query('8.8.8.8', new Query('google.com', Message::TYPE_AAAA, Message::CLASS_IN, 0));
    }
}
