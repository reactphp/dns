<?php

namespace React\Tests\Dns\Query;

use React\Dns\Query\Executor;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Protocol\BinaryDumper;
use React\Tests\Dns\TestCase;

class ExecutorTest extends TestCase
{
    private $loop;
    private $parser;
    private $dumper;
    private $executor;

    public function setUp()
    {
        $this->loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $this->parser = $this->getMockBuilder('React\Dns\Protocol\Parser')->getMock();
        $this->dumper = new BinaryDumper();

        $this->executor = new Executor($this->loop, $this->parser, $this->dumper);
    }

    /** @test */
    public function queryShouldCreateUdpRequest()
    {
        $timer = $this->getMockBuilder('React\EventLoop\Timer\TimerInterface')->getMock();
        $this->loop
            ->expects($this->any())
            ->method('addTimer')
            ->will($this->returnValue($timer));

        $this->executor = $this->createExecutorMock();
        $this->executor
            ->expects($this->once())
            ->method('createConnection')
            ->with('8.8.8.8:53', 'udp')
            ->will($this->returnNewConnectionMock(false));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $this->executor->query('8.8.8.8:53', $query, function () {}, function () {});
    }

    /** @test */
    public function resolveShouldCreateTcpRequestIfRequestIsLargerThan512Bytes()
    {
        $timer = $this->getMockBuilder('React\EventLoop\Timer\TimerInterface')->getMock();
        $this->loop
            ->expects($this->any())
            ->method('addTimer')
            ->will($this->returnValue($timer));

        $this->executor = $this->createExecutorMock();
        $this->executor
            ->expects($this->once())
            ->method('createConnection')
            ->with('8.8.8.8:53', 'tcp')
            ->will($this->returnNewConnectionMock(false));

        $query = new Query(str_repeat('a', 512).'.igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $this->executor->query('8.8.8.8:53', $query, function () {}, function () {});
    }

    /** @test */
    public function resolveShouldCloseConnectionWhenCancelled()
    {
        $conn = $this->createConnectionMock(false);
        $conn->expects($this->once())->method('close');

        $timer = $this->getMockBuilder('React\EventLoop\Timer\TimerInterface')->getMock();
        $this->loop
            ->expects($this->any())
            ->method('addTimer')
            ->will($this->returnValue($timer));

        $this->executor = $this->createExecutorMock();
        $this->executor
            ->expects($this->once())
            ->method('createConnection')
            ->with('8.8.8.8:53', 'udp')
            ->will($this->returnValue($conn));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $promise = $this->executor->query('8.8.8.8:53', $query);

        $promise->cancel();

        $errorback = $this->createCallableMock();
        $errorback
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->logicalAnd(
                $this->isInstanceOf('React\Dns\Query\CancellationException'),
                $this->attribute($this->equalTo('DNS query for igor.io has been cancelled'), 'message')
            )
        );

        $promise->then($this->expectCallableNever(), $errorback);
    }

    /** @test */
    public function resolveShouldNotStartOrCancelTimerWhenCancelledWithTimeoutIsNull()
    {
        $this->loop
            ->expects($this->never())
            ->method('addTimer');

        $this->executor = new Executor($this->loop, $this->parser, $this->dumper, null);

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $promise = $this->executor->query('8.8.8.8:53', $query);

        $promise->cancel();

        $errorback = $this->createCallableMock();
        $errorback
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->logicalAnd(
                $this->isInstanceOf('React\Dns\Query\CancellationException'),
                $this->attribute($this->equalTo('DNS query for igor.io has been cancelled'), 'message')
            ));

        $promise->then($this->expectCallableNever(), $errorback);
    }

    /** @test */
    public function resolveShouldRetryWithTcpIfResponseIsTruncated()
    {
        $timer = $this->getMockBuilder('React\EventLoop\Timer\TimerInterface')->getMock();

        $this->loop
            ->expects($this->any())
            ->method('addTimer')
            ->will($this->returnValue($timer));

        $this->parser
            ->expects($this->at(0))
            ->method('parseMessage')
            ->will($this->returnTruncatedResponse());
        $this->parser
            ->expects($this->at(1))
            ->method('parseMessage')
            ->will($this->returnStandardResponse());

        $this->executor = $this->createExecutorMock();
        $this->executor
            ->expects($this->at(0))
            ->method('createConnection')
            ->with('8.8.8.8:53', 'udp')
            ->will($this->returnNewConnectionMock());
        $this->executor
            ->expects($this->at(1))
            ->method('createConnection')
            ->with('8.8.8.8:53', 'tcp')
            ->will($this->returnNewConnectionMock());

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $this->executor->query('8.8.8.8:53', $query, function () {}, function () {});
    }

    /** @test */
    public function resolveShouldRetryWithTcpIfUdpThrows()
    {
        $timer = $this->getMockBuilder('React\EventLoop\Timer\TimerInterface')->getMock();

        $this->loop
            ->expects($this->once())
            ->method('addTimer')
            ->will($this->returnValue($timer));

        $this->parser
            ->expects($this->once())
            ->method('parseMessage')
            ->will($this->returnStandardResponse());

        $this->executor = $this->createExecutorMock();
        $this->executor
            ->expects($this->at(0))
            ->method('createConnection')
            ->with('8.8.8.8:53', 'udp')
            ->will($this->throwException(new \Exception()));
        $this->executor
            ->expects($this->at(1))
            ->method('createConnection')
            ->with('8.8.8.8:53', 'tcp')
            ->will($this->returnNewConnectionMock());

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $this->executor->query('8.8.8.8:53', $query, function () {}, function () {});
    }

    /** @test */
    public function resolveShouldFailIfBothUdpAndTcpThrow()
    {
        $timer = $this->getMockBuilder('React\EventLoop\Timer\TimerInterface')->getMock();

        $this->loop
            ->expects($this->once())
            ->method('addTimer')
            ->will($this->returnValue($timer));

        $this->parser
            ->expects($this->never())
            ->method('parseMessage');

        $this->executor = $this->createExecutorMock();
        $this->executor
            ->expects($this->at(0))
            ->method('createConnection')
            ->with('8.8.8.8:53', 'udp')
            ->will($this->throwException(new \Exception()));
        $this->executor
            ->expects($this->at(1))
            ->method('createConnection')
            ->with('8.8.8.8:53', 'tcp')
            ->will($this->throwException(new \Exception()));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $promise = $this->executor->query('8.8.8.8:53', $query, function () {}, function () {});

        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->callback(function($e) {
                return $e instanceof \RuntimeException &&
                    strpos($e->getMessage(), 'Unable to connect to DNS server') === 0;
            }));

        $promise->then($this->expectCallableNever(), $mock);
    }

    /** @test */
    public function resolveShouldFailIfResponseIsTruncatedAfterTcpRequest()
    {
        $timer = $this->getMockBuilder('React\EventLoop\Timer\TimerInterface')->getMock();

        $this->loop
            ->expects($this->any())
            ->method('addTimer')
            ->will($this->returnValue($timer));

        $this->parser
            ->expects($this->once())
            ->method('parseMessage')
            ->will($this->returnTruncatedResponse());

        $this->executor = $this->createExecutorMock();
        $this->executor
            ->expects($this->once())
            ->method('createConnection')
            ->with('8.8.8.8:53', 'tcp')
            ->will($this->returnNewConnectionMock());

        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->callback(function($e) {
                return $e instanceof \React\Dns\BadServerException &&
                       'The server set the truncated bit although we issued a TCP request' === $e->getMessage();
            }));

        $query = new Query(str_repeat('a', 512).'.igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $this->executor->query('8.8.8.8:53', $query)
            ->then($this->expectCallableNever(), $mock);
    }

    /** @test */
    public function resolveShouldCancelTimerWhenFullResponseIsReceived()
    {
        $conn = $this->createConnectionMock();

        $this->parser
            ->expects($this->once())
            ->method('parseMessage')
            ->will($this->returnStandardResponse());

        $this->executor = $this->createExecutorMock();
        $this->executor
            ->expects($this->at(0))
            ->method('createConnection')
            ->with('8.8.8.8:53', 'udp')
            ->will($this->returnNewConnectionMock());


        $timer = $this->getMockBuilder('React\EventLoop\Timer\TimerInterface')->getMock();
        $timer
            ->expects($this->once())
            ->method('cancel');

        $this->loop
            ->expects($this->once())
            ->method('addTimer')
            ->with(5, $this->isInstanceOf('Closure'))
            ->will($this->returnValue($timer));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $this->executor->query('8.8.8.8:53', $query, function () {}, function () {});
    }

    /** @test */
    public function resolveShouldCloseConnectionOnTimeout()
    {
        $this->executor = $this->createExecutorMock();
        $this->executor
            ->expects($this->at(0))
            ->method('createConnection')
            ->with('8.8.8.8:53', 'udp')
            ->will($this->returnNewConnectionMock(false));

        $timer = $this->getMockBuilder('React\EventLoop\Timer\TimerInterface')->getMock();
        $timer
            ->expects($this->never())
            ->method('cancel');

        $this->loop
            ->expects($this->once())
            ->method('addTimer')
            ->with(5, $this->isInstanceOf('Closure'))
            ->will($this->returnCallback(function ($time, $callback) use (&$timerCallback, $timer) {
                $timerCallback = $callback;
                return $timer;
            }));

        $callback = $this->expectCallableNever();

        $errorback = $this->createCallableMock();
        $errorback
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->logicalAnd(
                $this->isInstanceOf('React\Dns\Query\TimeoutException'),
                $this->attribute($this->equalTo('DNS query for igor.io timed out'), 'message')
            ));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $this->executor->query('8.8.8.8:53', $query)->then($callback, $errorback);

        $this->assertNotNull($timerCallback);
        $timerCallback();
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
        $callback = function ($data) use ($that) {
            $response = new Message();
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

    private function returnNewConnectionMock($emitData = true)
    {
        $conn = $this->createConnectionMock($emitData);

        $callback = function () use ($conn) {
            return $conn;
        };

        return $this->returnCallback($callback);
    }

    private function createConnectionMock($emitData = true)
    {
        $conn = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $conn
            ->expects($this->any())
            ->method('on')
            ->with('data', $this->isInstanceOf('Closure'))
            ->will($this->returnCallback(function ($name, $callback) use ($emitData) {
                $emitData && $callback(null);
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
}
