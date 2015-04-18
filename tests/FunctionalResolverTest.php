<?php

namespace React\Tests\Dns;

use React\Tests\Dns\TestCase;
use React\EventLoop\Factory as LoopFactory;
use React\Dns\Resolver\Resolver;
use React\Dns\Resolver\Factory;

class FunctionalTest extends TestCase
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
}
