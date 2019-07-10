<?php

namespace React\Tests\Dns\Resolver;

use React\Dns\Resolver\Factory;
use React\Tests\Dns\TestCase;
use React\Dns\Query\HostsFileExecutor;

class FactoryTest extends TestCase
{
    /** @test */
    public function createShouldCreateResolver()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $factory = new Factory();
        $resolver = $factory->create('8.8.8.8:53', $loop);

        $this->assertInstanceOf('React\Dns\Resolver\Resolver', $resolver);
    }

    /**
     * @test
     * @expectedException InvalidArgumentException
     */
    public function createShouldThrowWhenNameserverIsInvalid()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $factory = new Factory();
        $factory->create('///', $loop);
    }

    /** @test */
    public function createCachedShouldCreateResolverWithCachingExecutor()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $factory = new Factory();
        $resolver = $factory->createCached('8.8.8.8:53', $loop);

        $this->assertInstanceOf('React\Dns\Resolver\Resolver', $resolver);
        $executor = $this->getResolverPrivateExecutor($resolver);
        $this->assertInstanceOf('React\Dns\Query\CachingExecutor', $executor);
        $cache = $this->getCachingExecutorPrivateMemberValue($executor, 'cache');
        $this->assertInstanceOf('React\Cache\ArrayCache', $cache);
    }

    /** @test */
    public function createCachedShouldCreateResolverWithCachingExecutorWithCustomCache()
    {
        $cache = $this->getMockBuilder('React\Cache\CacheInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $factory = new Factory();
        $resolver = $factory->createCached('8.8.8.8:53', $loop, $cache);

        $this->assertInstanceOf('React\Dns\Resolver\Resolver', $resolver);
        $executor = $this->getResolverPrivateExecutor($resolver);
        $this->assertInstanceOf('React\Dns\Query\CachingExecutor', $executor);
        $cacheProperty = $this->getCachingExecutorPrivateMemberValue($executor, 'cache');
        $this->assertSame($cache, $cacheProperty);
    }

    private function getResolverPrivateExecutor($resolver)
    {
        $executor = $this->getResolverPrivateMemberValue($resolver, 'executor');

        // extract underlying executor that may be wrapped in multiple layers of hosts file executors
        while ($executor instanceof HostsFileExecutor) {
            $reflector = new \ReflectionProperty('React\Dns\Query\HostsFileExecutor', 'fallback');
            $reflector->setAccessible(true);

            $executor = $reflector->getValue($executor);
        }

        return $executor;
    }

    private function getResolverPrivateMemberValue($resolver, $field)
    {
        $reflector = new \ReflectionProperty('React\Dns\Resolver\Resolver', $field);
        $reflector->setAccessible(true);
        return $reflector->getValue($resolver);
    }

    private function getCachingExecutorPrivateMemberValue($resolver, $field)
    {
        $reflector = new \ReflectionProperty('React\Dns\Query\CachingExecutor', $field);
        $reflector->setAccessible(true);
        return $reflector->getValue($resolver);
    }
}
