<?php

namespace React\Tests\Dns;

use React\Dns\Resolver\Factory;
use React\Dns\Model\Message;
use React\EventLoop\Loop;

class FunctionalResolverTest extends TestCase
{
    private $resolver;

    /**
     * @before
     */
    public function setUpResolver()
    {
        $factory = new Factory();
        $this->resolver = $factory->create('8.8.8.8');
    }

    public function testResolveLocalhostResolves()
    {
        $promise = $this->resolver->resolve('localhost');
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        Loop::run();
    }

    public function testResolveAllLocalhostResolvesWithArray()
    {
        $promise = $this->resolver->resolveAll('localhost', Message::TYPE_A);
        $promise->then($this->expectCallableOnceWith($this->isType('array')), $this->expectCallableNever());

        Loop::run();
    }

    /**
     * @group internet
     */
    public function testResolveGoogleResolves()
    {
        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        Loop::run();
    }

    /**
     * @group internet
     */
    public function testResolveGoogleOverUdpResolves()
    {
        $factory = new Factory();
        $this->resolver = $factory->create('udp://8.8.8.8');

        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        Loop::run();
    }

    /**
     * @group internet
     */
    public function testResolveGoogleOverTcpResolves()
    {
        $factory = new Factory();
        $this->resolver = $factory->create('tcp://8.8.8.8');

        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        Loop::run();
    }

    /**
     * @group internet
     */
    public function testResolveGoogleOverTlsResolves()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $factory = new Factory();
        $this->resolver = $factory->create('tls://8.8.8.8?socket[tcp_nodelay]=true');

        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        Loop::run();
    }

    /**
     * @group internet
     */
    public function testAttemptTlsOnNonTlsPortRejects()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $factory = new Factory();
        $this->resolver = $factory->create('tls://8.8.8.8:53');

        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());

        Loop::run();
    }

    /**
     * @group internet
     */
    public function testUnsupportedLegacyPhpOverTlsRejectsWithBadMethodCall()
    {
        if (!(defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600)) {
            $this->markTestSkipped('Tests not relevant to recent PHP versions');
        }

        $factory = new Factory();
        $this->resolver = $factory->create('tls://8.8.8.8', $this->loop);

        $promise = $this->resolver->resolve('google.com');
        $exception = null;
        $promise->then($this->expectCallableNever(), function ($reason) use (&$exception) {
            $exception = $reason;
        });

        /** @var \BadMethodCallException $exception */
        $this->assertInstanceOf('BadMethodCallException', $exception);

        Loop::run();
    }

    /**
     * @group internet
     */
    public function testResolveAllGoogleMxResolvesWithCache()
    {
        $factory = new Factory();
        $this->resolver = $factory->createCached('8.8.8.8');

        $promise = $this->resolver->resolveAll('google.com', Message::TYPE_MX);
        $promise->then($this->expectCallableOnceWith($this->isType('array')), $this->expectCallableNever());

        Loop::run();
    }
    /**
     * @group internet
     */
    public function testResolveAllGoogleCaaResolvesWithCache()
    {
        $factory = new Factory();
        $this->resolver = $factory->createCached('8.8.8.8');

        $promise = $this->resolver->resolveAll('google.com', Message::TYPE_CAA);
        $promise->then($this->expectCallableOnceWith($this->isType('array')), $this->expectCallableNever());

        Loop::run();
    }

    /**
     * @group internet
     */
    public function testResolveInvalidRejects()
    {
        $promise = $this->resolver->resolve('example.invalid');

        Loop::run();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        /** @var \React\Dns\RecordNotFoundException $exception */
        $this->assertInstanceOf('React\Dns\RecordNotFoundException', $exception);
        $this->assertEquals('DNS query for example.invalid (A) returned an error response (Non-Existent Domain / NXDOMAIN)', $exception->getMessage());
        $this->assertEquals(Message::RCODE_NAME_ERROR, $exception->getCode());
    }

    public function testResolveCancelledRejectsImmediately()
    {
        // max_nesting_level was set to 100 for PHP Versions < 5.4 which resulted in failing test for legacy PHP
        ini_set('xdebug.max_nesting_level', 256);

        $promise = $this->resolver->resolve('google.com');
        $promise->cancel();

        $time = microtime(true);
        Loop::run();
        $time = microtime(true) - $time;

        $this->assertLessThan(0.1, $time);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        /** @var \React\Dns\Query\CancellationException $exception */
        $this->assertInstanceOf('React\Dns\Query\CancellationException', $exception);
        $this->assertEquals('DNS query for google.com (A) has been cancelled', $exception->getMessage());
    }

    /**
     * @group internet
     */
    public function testResolveAllInvalidTypeRejects()
    {
        $promise = $this->resolver->resolveAll('google.com', Message::TYPE_PTR);

        Loop::run();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        /** @var \React\Dns\RecordNotFoundException $exception */
        $this->assertInstanceOf('React\Dns\RecordNotFoundException', $exception);
        $this->assertEquals('DNS query for google.com (PTR) did not return a valid answer (NOERROR / NODATA)', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
    }

    public function testInvalidResolverDoesNotResolveGoogle()
    {
        $factory = new Factory();
        $this->resolver = $factory->create('255.255.255.255');

        $promise = $this->resolver->resolve('google.com');
        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testResolveShouldNotCauseGarbageReferencesWhenUsingInvalidNameserver()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $factory = new Factory();
        $this->resolver = $factory->create('255.255.255.255');

        gc_collect_cycles();
        gc_collect_cycles(); // clear twice to avoid leftovers in PHP 7.4 with ext-xdebug and code coverage turned on

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
        $this->resolver = $factory->createCached('255.255.255.255');

        gc_collect_cycles();
        gc_collect_cycles(); // clear twice to avoid leftovers in PHP 7.4 with ext-xdebug and code coverage turned on

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
        $this->resolver = $factory->create('127.0.0.1');

        gc_collect_cycles();
        gc_collect_cycles(); // clear twice to avoid leftovers in PHP 7.4 with ext-xdebug and code coverage turned on

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
        $this->resolver = $factory->createCached('127.0.0.1');

        gc_collect_cycles();
        gc_collect_cycles(); // clear twice to avoid leftovers in PHP 7.4 with ext-xdebug and code coverage turned on

        $promise = $this->resolver->resolve('google.com');
        $promise->cancel();
        $promise = null;

        $this->assertEquals(0, gc_collect_cycles());
    }
}
