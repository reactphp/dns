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






    private function returnStandardResponse()
    {
        $that = $this;
        $callback = function ($data, $response) use ($that) {
            $that->convertMessageToStandardResponse($response);
            return $response;
        };

        return $this->returnCallback($callback);
    }

    private function returnTruncatedResponse()
    {
        $that = $this;
        $callback = function ($data, $response) use ($that) {
            $that->convertMessageToTruncatedResponse($response);
            return $response;
        };

        return $this->returnCallback($callback);
    }

    public function convertMessageToStandardResponse(Message $response)
    {
        $response->header->set('qr', 1);
        $response->questions[] = new Record('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $response->answers[] = new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131');
        $response->prepare();

        return $response;
    }

    public function convertMessageToTruncatedResponse(Message $response)
    {
        $this->convertMessageToStandardResponse($response);
        $response->header->set('tc', 1);
        $response->prepare();

        return $response;
    }

    private function returnNewConnectionMock()
    {
        $conn = $this->createConnectionMock();

        $callback = function () use ($conn) {
            return $conn;
        };

        return $this->returnCallback($callback);
    }

    private function createConnectionMock()
    {
        $conn = $this->getMock('React\Socket\ConnectionInterface');
        $conn
            ->expects($this->any())
            ->method('on')
            ->with('data', $this->isInstanceOf('Closure'))
            ->will($this->returnCallback(function ($name, $callback) {
                $callback(null);
            }));

        return $conn;
    }

    private function createExecutorMock()
    {
        return $this->getMockBuilder('React\Dns\Query\Executor')
            ->setConstructorArgs(array($this->loop, $this->parser, $this->dumper))
            ->setMethods(array('createConnection'))
            ->getMock();
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
