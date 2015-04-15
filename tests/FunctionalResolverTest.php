<?php

namespace React\Tests\Dns;

use React\EventLoop\Factory as LoopFactory;
use React\Dns\Resolver\Resolver;
use React\Dns\Resolver\Factory;

class FunctionalTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $this->loop = LoopFactory::create();

        $factory = new Factory();
        $this->resolver = $factory->create('8.8.8.8', $this->loop);
    }

    public function testResolveGoogleResolves()
    {
        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        $this->loop->run();
    }

    public function testResolveInvalidRejects()
    {
        $promise = $this->resolver->resolve('example.invalid');
        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());

        $this->loop->run();
    }

    protected function expectCallableOnce()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke');

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
}
