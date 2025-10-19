<?php

namespace React\Tests\Dns\Resolver;

use React\Cache\ArrayCache;
use React\Cache\CacheInterface;
use React\Dns\Config\Config;
use React\Dns\Query\CachingExecutor;
use React\Dns\Query\CoopExecutor;
use React\Dns\Query\HostsFileExecutor;
use React\Dns\Query\FallbackExecutor;
use React\Dns\Query\RetryExecutor;
use React\Dns\Query\SelectiveTransportExecutor;
use React\Dns\Query\TcpTransportExecutor;
use React\Dns\Query\TimeoutExecutor;
use React\Dns\Query\UdpTransportExecutor;
use React\Dns\Resolver\Factory;
use React\Dns\Resolver\Resolver;
use React\EventLoop\LoopInterface;
use React\Tests\Dns\TestCase;

class FactoryTest extends TestCase
{
    /** @test */
    public function createShouldCreateResolver()
    {
        $factory = new Factory();
        $resolver = $factory->create('8.8.8.8:53');

        $this->assertInstanceOf(Resolver::class, $resolver);
    }

    /** @test */
    public function createWithoutSchemeShouldCreateResolverWithSelectiveUdpAndTcpExecutorStack()
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();
        $resolver = $factory->create('8.8.8.8:53', $loop);

        $this->assertInstanceOf(Resolver::class, $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf(CoopExecutor::class, $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $selectiveExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(SelectiveTransportExecutor::class, $selectiveExecutor);

        // udp below:

        $ref = new \ReflectionProperty($selectiveExecutor, 'datagramExecutor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($selectiveExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $udpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(UdpTransportExecutor::class, $udpExecutor);

        // tcp below:

        $ref = new \ReflectionProperty($selectiveExecutor, 'streamExecutor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($selectiveExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);
    }

    /** @test */
    public function createWithUdpSchemeShouldCreateResolverWithUdpExecutorStack()
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();
        $resolver = $factory->create('udp://8.8.8.8:53', $loop);

        $this->assertInstanceOf(Resolver::class, $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf(CoopExecutor::class, $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $udpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(UdpTransportExecutor::class, $udpExecutor);
    }

    /** @test */
    public function createWithTcpSchemeShouldCreateResolverWithTcpExecutorStack()
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();
        $resolver = $factory->create('tcp://8.8.8.8:53', $loop);

        $this->assertInstanceOf(Resolver::class, $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf(CoopExecutor::class, $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);
    }

    /** @test */
    public function createWithConfigWithTcpNameserverSchemeShouldCreateResolverWithTcpExecutorStack()
    {
        $loop = $this->createMock(LoopInterface::class);

        $config = new Config();
        $config->nameservers[] = 'tcp://8.8.8.8:53';

        $factory = new Factory();
        $resolver = $factory->create($config, $loop);

        $this->assertInstanceOf(Resolver::class, $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf(CoopExecutor::class, $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);
    }

    /** @test */
    public function createWithConfigWithTwoNameserversWithTcpSchemeShouldCreateResolverWithFallbackExecutorStack()
    {
        $loop = $this->createMock(LoopInterface::class);

        $config = new Config();
        $config->nameservers[] = 'tcp://8.8.8.8:53';
        $config->nameservers[] = 'tcp://1.1.1.1:53';

        $factory = new Factory();
        $resolver = $factory->create($config, $loop);

        $this->assertInstanceOf(Resolver::class, $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf(CoopExecutor::class, $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $fallbackExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(FallbackExecutor::class, $fallbackExecutor);

        $ref = new \ReflectionProperty($fallbackExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);

        $ref = new \ReflectionProperty($tcpExecutor, 'nameserver');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $nameserver = $ref->getValue($tcpExecutor);

        $this->assertEquals('tcp://8.8.8.8:53', $nameserver);

        $ref = new \ReflectionProperty($fallbackExecutor, 'fallback');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);

        $ref = new \ReflectionProperty($tcpExecutor, 'nameserver');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $nameserver = $ref->getValue($tcpExecutor);

        $this->assertEquals('tcp://1.1.1.1:53', $nameserver);
    }

    /** @test */
    public function createWithConfigWithThreeNameserversWithTcpSchemeShouldCreateResolverWithNestedFallbackExecutorStack()
    {
        $loop = $this->createMock(LoopInterface::class);

        $config = new Config();
        $config->nameservers[] = 'tcp://8.8.8.8:53';
        $config->nameservers[] = 'tcp://1.1.1.1:53';
        $config->nameservers[] = 'tcp://9.9.9.9:53';

        $factory = new Factory();
        $resolver = $factory->create($config, $loop);

        $this->assertInstanceOf(Resolver::class, $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf(CoopExecutor::class, $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $fallbackExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(FallbackExecutor::class, $fallbackExecutor);

        $ref = new \ReflectionProperty($fallbackExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);

        $ref = new \ReflectionProperty($tcpExecutor, 'nameserver');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $nameserver = $ref->getValue($tcpExecutor);

        $this->assertEquals('tcp://8.8.8.8:53', $nameserver);

        $ref = new \ReflectionProperty($fallbackExecutor, 'fallback');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $fallbackExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(FallbackExecutor::class, $fallbackExecutor);

        $ref = new \ReflectionProperty($fallbackExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);

        $ref = new \ReflectionProperty($tcpExecutor, 'nameserver');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $nameserver = $ref->getValue($tcpExecutor);

        $this->assertEquals('tcp://1.1.1.1:53', $nameserver);

        $ref = new \ReflectionProperty($fallbackExecutor, 'fallback');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);

        $ref = new \ReflectionProperty($tcpExecutor, 'nameserver');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $nameserver = $ref->getValue($tcpExecutor);

        $this->assertEquals('tcp://9.9.9.9:53', $nameserver);
    }

    /** @test */
    public function createShouldThrowWhenNameserverIsInvalid()
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();

        $this->expectException(\InvalidArgumentException::class);
        $factory->create('///', $loop);
    }

    /** @test */
    public function createShouldThrowWhenConfigHasNoNameservers()
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();

        $this->expectException(\UnderflowException::class);
        $factory->create(new Config(), $loop);
    }

    /** @test */
    public function createShouldThrowWhenConfigHasInvalidNameserver()
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();

        $config = new Config();
        $config->nameservers[] = '///';

        $this->expectException(\InvalidArgumentException::class);
        $factory->create($config, $loop);
    }

    /** @test */
    public function createCachedShouldCreateResolverWithCachingExecutor()
    {
        $factory = new Factory();
        $resolver = $factory->createCached('8.8.8.8:53');

        $this->assertInstanceOf(Resolver::class, $resolver);
        $executor = $this->getResolverPrivateExecutor($resolver);
        $this->assertInstanceOf(CachingExecutor::class, $executor);
        $cache = $this->getCachingExecutorPrivateMemberValue($executor, 'cache');
        $this->assertInstanceOf(ArrayCache::class, $cache);
    }

    /** @test */
    public function createCachedShouldCreateResolverWithCachingExecutorWithCustomCache()
    {
        $cache = $this->createMock(CacheInterface::class);
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();
        $resolver = $factory->createCached('8.8.8.8:53', $loop, $cache);

        $this->assertInstanceOf(Resolver::class, $resolver);
        $executor = $this->getResolverPrivateExecutor($resolver);
        $this->assertInstanceOf(CachingExecutor::class, $executor);
        $cacheProperty = $this->getCachingExecutorPrivateMemberValue($executor, 'cache');
        $this->assertSame($cache, $cacheProperty);
    }

    private function getResolverPrivateExecutor($resolver)
    {
        $executor = $this->getResolverPrivateMemberValue($resolver, 'executor');

        // extract underlying executor that may be wrapped in multiple layers of hosts file executors
        while ($executor instanceof HostsFileExecutor) {
            $reflector = new \ReflectionProperty(HostsFileExecutor::class, 'fallback');
            if (\PHP_VERSION_ID < 80100) {
                $reflector->setAccessible(true);
            }

            $executor = $reflector->getValue($executor);
        }

        return $executor;
    }

    private function getResolverPrivateMemberValue($resolver, $field)
    {
        $reflector = new \ReflectionProperty(Resolver::class, $field);
        if (\PHP_VERSION_ID < 80100) {
            $reflector->setAccessible(true);
        }
        return $reflector->getValue($resolver);
    }

    private function getCachingExecutorPrivateMemberValue($resolver, $field)
    {
        $reflector = new \ReflectionProperty(CachingExecutor::class, $field);
        if (\PHP_VERSION_ID < 80100) {
            $reflector->setAccessible(true);
        }
        return $reflector->getValue($resolver);
    }
}
