<?php

namespace React\Tests\Dns\Query;

use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\HostsExecutor;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Dns\Model\Record;

class HostsExecutorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ExecutorInterface
     */
    public $executor;

    public function setUp()
    {
        $this->loop = $this->getMock('React\EventLoop\LoopInterface');
        $this->executor = new HostsExecutor(__DIR__.'/../Fixtures/etc/hosts');

    }

    public function testRegisteredName()
    {
        $query = new Query('host-pc', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        /** @var \React\Promise\Promise$response */
        $response = $this->executor->query(null, $query);

        $success = $this->createCallableMock();
        $success
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf('React\Dns\Model\Message'))
            ;
        $response->then($success, $this->expectCallableNever());

    }

    public function testUnRegisteredName()
    {
        $query = new Query('some-host', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        /** @var \React\Promise\Promise$response */
        $response = $this->executor->query(null, $query);

        $err = $this->createCallableMock();
        $err
            ->expects($this->once())
            ->method('__invoke')
            ;
        $response->then($this->expectCallableNever(), $err);

    }

    protected function expectCallableNever()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->never())
            ->method('__invoke');

        return $mock;
    }

    protected function createCallableMock()
    {
        return $this->getMock('React\Tests\Dns\CallableStub');
    }
}
