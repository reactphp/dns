<?php

namespace React\Tests\Dns;

use React\EventLoop\Factory as LoopFactory;
use React\Dns\Resolver\Factory;
use React\Dns\RecordNotFoundException;
use React\Dns\Model\Message;

class FunctionalTest extends TestCase
{
    public function setUp()
    {
        $this->loop = LoopFactory::create();

        $factory = new Factory();
        $this->resolver = $factory->create('8.8.8.8', $this->loop);
    }

    public function testResolveLocalhostResolves()
    {
        $promise = $this->resolver->resolve('localhost');
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        $this->loop->run();
    }

    public function testResolveAllLocalhostResolvesWithArray()
    {
        $promise = $this->resolver->resolveAll('localhost', Message::TYPE_A);
        $promise->then($this->expectCallableOnceWith($this->isType('array')), $this->expectCallableNever());

        $this->loop->run();
    }

    /**
     * @group internet
     */
    public function testResolveGoogleResolves()
    {
        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        $this->loop->run();
    }

    /**
     * @group internet
     */
    public function testResolveGoogleOverUdpResolves()
    {
        $factory = new Factory($this->loop);
        $this->resolver = $factory->create('udp://8.8.8.8', $this->loop);

        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        $this->loop->run();
    }

    /**
     * @group internet
     */
    public function testResolveGoogleOverTcpResolves()
    {
        $factory = new Factory($this->loop);
        $this->resolver = $factory->create('tcp://8.8.8.8', $this->loop);

        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        $this->loop->run();
    }

    /**
     * @group internet
     */
    public function testResolveAllGoogleMxResolvesWithCache()
    {
        $factory = new Factory();
        $this->resolver = $factory->createCached('8.8.8.8', $this->loop);

        $promise = $this->resolver->resolveAll('google.com', Message::TYPE_MX);
        $promise->then($this->expectCallableOnceWith($this->isType('array')), $this->expectCallableNever());

        $this->loop->run();
    }
    /**
     * @group internet
     */
    public function testResolveAllGoogleCaaResolvesWithCache()
    {
        $factory = new Factory();
        $this->resolver = $factory->createCached('8.8.8.8', $this->loop);

        $promise = $this->resolver->resolveAll('google.com', Message::TYPE_CAA);
        $promise->then($this->expectCallableOnceWith($this->isType('array')), $this->expectCallableNever());

        $this->loop->run();
    }

    /**
     * @group internet
     */
    public function testResolveInvalidRejects()
    {
        $ex = $this->callback(function ($param) {
            return ($param instanceof RecordNotFoundException && $param->getCode() === Message::RCODE_NAME_ERROR);
        });

        $promise = $this->resolver->resolve('example.invalid');
        $promise->then($this->expectCallableNever(), $this->expectCallableOnceWith($ex));

        $this->loop->run();
    }

    public function testResolveCancelledRejectsImmediately()
    {
        $ex = $this->callback(function ($param) {
            return ($param instanceof \RuntimeException && $param->getMessage() === 'DNS query for google.com has been cancelled');
        });

        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableNever(), $this->expectCallableOnceWith($ex));
        $promise->cancel();

        $time = microtime(true);
        $this->loop->run();
        $time = microtime(true) - $time;

        $this->assertLessThan(0.1, $time);
    }

    public function testInvalidResolverDoesNotResolveGoogle()
    {
        $factory = new Factory();
        $this->resolver = $factory->create('255.255.255.255', $this->loop);

        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testResolveShouldNotCauseGarbageReferencesWhenUsingInvalidNameserver()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $factory = new Factory();
        $this->resolver = $factory->create('255.255.255.255', $this->loop);

        gc_collect_cycles();

        $promise = $this->resolver->resolve('google.com');
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testResolveCachedShouldNotCauseGarbageReferencesWhenUsingInvalidNameserver()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $factory = new Factory();
        $this->resolver = $factory->createCached('255.255.255.255', $this->loop);

        gc_collect_cycles();

        $promise = $this->resolver->resolve('google.com');
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testCancelResolveShouldNotCauseGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $factory = new Factory();
        $this->resolver = $factory->create('127.0.0.1', $this->loop);

        gc_collect_cycles();

        $promise = $this->resolver->resolve('google.com');
        $promise->cancel();
        $promise = null;

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testCancelResolveCachedShouldNotCauseGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $factory = new Factory();
        $this->resolver = $factory->createCached('127.0.0.1', $this->loop);

        gc_collect_cycles();

        $promise = $this->resolver->resolve('google.com');
        $promise->cancel();
        $promise = null;

        $this->assertEquals(0, gc_collect_cycles());
    }
}
