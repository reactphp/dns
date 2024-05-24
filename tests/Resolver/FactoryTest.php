<?php

namespace React\Tests\Dns\Resolver;

use React\Cache\ArrayCache;
use React\Cache\CacheInterface;
use React\Dns\Config\Config;
use React\Dns\Query\CachingExecutor;
use React\Dns\Query\CoopExecutor;
use React\Dns\Query\ExecutorInterface;
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
    public function createShouldCreateResolver(): void
    {
        $factory = new Factory();
        $resolver = $factory->create('8.8.8.8:53');

        $this->assertInstanceOf(Resolver::class, $resolver);
    }

    /** @test */
    public function createWithoutSchemeShouldCreateResolverWithSelectiveUdpAndTcpExecutorStack(): void
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();
        $resolver = $factory->create('8.8.8.8:53', $loop);

        $this->assertInstanceOf(Resolver::class, $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf(CoopExecutor::class, $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        $ref->setAccessible(true);
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        $ref->setAccessible(true);
        $selectiveExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(SelectiveTransportExecutor::class, $selectiveExecutor);

        // udp below:

        $ref = new \ReflectionProperty($selectiveExecutor, 'datagramExecutor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($selectiveExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $udpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(UdpTransportExecutor::class, $udpExecutor);

        // tcp below:

        $ref = new \ReflectionProperty($selectiveExecutor, 'streamExecutor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($selectiveExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);
    }

    /** @test */
    public function createWithUdpSchemeShouldCreateResolverWithUdpExecutorStack(): void
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();
        $resolver = $factory->create('udp://8.8.8.8:53', $loop);

        $this->assertInstanceOf(Resolver::class, $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf(CoopExecutor::class, $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        $ref->setAccessible(true);
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $udpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(UdpTransportExecutor::class, $udpExecutor);
    }

    /** @test */
    public function createWithTcpSchemeShouldCreateResolverWithTcpExecutorStack(): void
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();
        $resolver = $factory->create('tcp://8.8.8.8:53', $loop);

        $this->assertInstanceOf(Resolver::class, $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf(CoopExecutor::class, $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        $ref->setAccessible(true);
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);
    }

    /** @test */
    public function createWithConfigWithTcpNameserverSchemeShouldCreateResolverWithTcpExecutorStack(): void
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
        $ref->setAccessible(true);
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);
    }

    /** @test */
    public function createWithConfigWithTwoNameserversWithTcpSchemeShouldCreateResolverWithFallbackExecutorStack(): void
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
        $ref->setAccessible(true);
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        $ref->setAccessible(true);
        $fallbackExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(FallbackExecutor::class, $fallbackExecutor);

        $ref = new \ReflectionProperty($fallbackExecutor, 'executor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);

        $ref = new \ReflectionProperty($tcpExecutor, 'nameserver');
        $ref->setAccessible(true);
        $nameserver = $ref->getValue($tcpExecutor);

        $this->assertEquals('tcp://8.8.8.8:53', $nameserver);

        $ref = new \ReflectionProperty($fallbackExecutor, 'fallback');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);

        $ref = new \ReflectionProperty($tcpExecutor, 'nameserver');
        $ref->setAccessible(true);
        $nameserver = $ref->getValue($tcpExecutor);

        $this->assertEquals('tcp://1.1.1.1:53', $nameserver);
    }

    /** @test */
    public function createWithConfigWithThreeNameserversWithTcpSchemeShouldCreateResolverWithNestedFallbackExecutorStack(): void
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
        $ref->setAccessible(true);
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf(RetryExecutor::class, $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        $ref->setAccessible(true);
        $fallbackExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf(FallbackExecutor::class, $fallbackExecutor);

        $ref = new \ReflectionProperty($fallbackExecutor, 'executor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);

        $ref = new \ReflectionProperty($tcpExecutor, 'nameserver');
        $ref->setAccessible(true);
        $nameserver = $ref->getValue($tcpExecutor);

        $this->assertEquals('tcp://8.8.8.8:53', $nameserver);

        $ref = new \ReflectionProperty($fallbackExecutor, 'fallback');
        $ref->setAccessible(true);
        $fallbackExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(FallbackExecutor::class, $fallbackExecutor);

        $ref = new \ReflectionProperty($fallbackExecutor, 'executor');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);

        $ref = new \ReflectionProperty($tcpExecutor, 'nameserver');
        $ref->setAccessible(true);
        $nameserver = $ref->getValue($tcpExecutor);

        $this->assertEquals('tcp://1.1.1.1:53', $nameserver);

        $ref = new \ReflectionProperty($fallbackExecutor, 'fallback');
        $ref->setAccessible(true);
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf(TimeoutExecutor::class, $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        $ref->setAccessible(true);
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf(TcpTransportExecutor::class, $tcpExecutor);

        $ref = new \ReflectionProperty($tcpExecutor, 'nameserver');
        $ref->setAccessible(true);
        $nameserver = $ref->getValue($tcpExecutor);

        $this->assertEquals('tcp://9.9.9.9:53', $nameserver);
    }

    /** @test */
    public function createShouldThrowWhenNameserverIsInvalid(): void
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();

        $this->expectException(\InvalidArgumentException::class);
        $factory->create('///', $loop);
    }

    /** @test */
    public function createShouldThrowWhenConfigHasNoNameservers(): void
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();

        $this->expectException(\UnderflowException::class);
        $factory->create(new Config(), $loop);
    }

    /** @test */
    public function createShouldThrowWhenConfigHasInvalidNameserver(): void
    {
        $loop = $this->createMock(LoopInterface::class);

        $factory = new Factory();

        $config = new Config();
        $config->nameservers[] = '///';

        $this->expectException(\InvalidArgumentException::class);
        $factory->create($config, $loop);
    }

    /** @test */
    public function createCachedShouldCreateResolverWithCachingExecutor(): void
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
    public function createCachedShouldCreateResolverWithCachingExecutorWithCustomCache(): void
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

    private function getResolverPrivateExecutor(Resolver $resolver): ExecutorInterface
    {
        $executor = $this->getResolverPrivateMemberValue($resolver, 'executor');

        // extract underlying executor that may be wrapped in multiple layers of hosts file executors
        while ($executor instanceof HostsFileExecutor) {
            $reflector = new \ReflectionProperty(HostsFileExecutor::class, 'fallback');
            $reflector->setAccessible(true);

            /** @var ExecutorInterface $executor */
            $executor = $reflector->getValue($executor);
        }

        return $executor;
    }

    private function getResolverPrivateMemberValue(Resolver $resolver, string $field): ExecutorInterface
    {
        $reflector = new \ReflectionProperty(Resolver::class, $field);
        $reflector->setAccessible(true);
        /** @var ExecutorInterface */
        return $reflector->getValue($resolver);
    }

    /**
     * @return ExecutorInterface|CacheInterface
     */
    private function getCachingExecutorPrivateMemberValue(CachingExecutor $resolver, string $field)
    {
        $reflector = new \ReflectionProperty(CachingExecutor::class, $field);
        $reflector->setAccessible(true);
        /** @var ExecutorInterface|CacheInterface */
        return $reflector->getValue($resolver);
    }
}
