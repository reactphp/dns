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


    /** @test */
    public function createWithoutSchemeShouldCreateResolverWithSelectiveUdpAndTcpExecutorStack()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $factory = new Factory();
        $resolver = $factory->create('8.8.8.8:53', $loop);

        $this->assertInstanceOf('React\Dns\Resolver\Resolver', $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf('React\Dns\Query\CoopExecutor', $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        $ref->setAccessible(true);
        $selectiveExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf('React\Dns\Query\SelectiveTransportExecutor', $selectiveExecutor);

        // udp below:

        $ref = new \ReflectionProperty($selectiveExecutor, 'datagramExecutor');
        $ref->setAccessible(true);
        $retryExecutor = $ref->getValue($selectiveExecutor);

        $this->assertInstanceOf('React\Dns\Query\RetryExecutor', $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $udpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\UdpTransportExecutor', $udpExecutor);

        // tcp below:

        $ref = new \ReflectionProperty($selectiveExecutor, 'streamExecutor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($selectiveExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\TcpTransportExecutor', $tcpExecutor);
    }

    /** @test */
    public function createWithUdpSchemeShouldCreateResolverWithUdpExecutorStack()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $factory = new Factory();
        $resolver = $factory->create('udp://8.8.8.8:53', $loop);

        $this->assertInstanceOf('React\Dns\Resolver\Resolver', $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf('React\Dns\Query\CoopExecutor', $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        $ref->setAccessible(true);
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf('React\Dns\Query\RetryExecutor', $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $udpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\UdpTransportExecutor', $udpExecutor);
    }

    /** @test */
    public function createWithTcpSchemeShouldCreateResolverWithTcpExecutorStack()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $factory = new Factory();
        $resolver = $factory->create('tcp://8.8.8.8:53', $loop);

        $this->assertInstanceOf('React\Dns\Resolver\Resolver', $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf('React\Dns\Query\CoopExecutor', $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\TcpTransportExecutor', $tcpExecutor);
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
