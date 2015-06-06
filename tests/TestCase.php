<?php

namespace React\Tests\Dns;

use React\Promise\Deferred;

abstract class TestCase extends \PHPUnit_Framework_TestCase
{
    protected function expectCallableOnce()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnceWith($value)
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->equalTo($value));

        return $mock;
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

    protected function createPromiseResolved($value = null)
    {
        $deferred = new Deferred();
        $deferred->resolve($value);
        return $deferred->promise();
    }

    protected function createPromiseRejected($value = null)
    {
        $deferred = new Deferred();
        $deferred->reject($value);
        return $deferred->promise();
    }
}
