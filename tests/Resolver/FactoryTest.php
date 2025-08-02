<?php

namespace React\Tests\Dns\Resolver;

use React\Dns\Config\Config;
use React\Dns\Query\HostsFileExecutor;
use React\Dns\Resolver\Factory;
use React\Tests\Dns\TestCase;

class FactoryTest extends TestCase
{
    /** @test */
    public function createShouldCreateResolver()
    {
        $factory = new Factory();
        $resolver = $factory->create('8.8.8.8:53');

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
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf('React\Dns\Query\RetryExecutor', $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $selectiveExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf('React\Dns\Query\SelectiveTransportExecutor', $selectiveExecutor);

        // udp below:

        $ref = new \ReflectionProperty($selectiveExecutor, 'datagramExecutor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($selectiveExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $udpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\UdpTransportExecutor', $udpExecutor);

        // tcp below:

        $ref = new \ReflectionProperty($selectiveExecutor, 'streamExecutor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($selectiveExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
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
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf('React\Dns\Query\RetryExecutor', $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
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
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf('React\Dns\Query\RetryExecutor', $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\TcpTransportExecutor', $tcpExecutor);
    }

    /** @test */
    public function createWithConfigWithTcpNameserverSchemeShouldCreateResolverWithTcpExecutorStack()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $config = new Config();
        $config->nameservers[] = 'tcp://8.8.8.8:53';

        $factory = new Factory();
        $resolver = $factory->create($config, $loop);

        $this->assertInstanceOf('React\Dns\Resolver\Resolver', $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf('React\Dns\Query\CoopExecutor', $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf('React\Dns\Query\RetryExecutor', $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\TcpTransportExecutor', $tcpExecutor);
    }

    /** @test */
    public function createWithConfigWithTwoNameserversWithTcpSchemeShouldCreateResolverWithFallbackExecutorStack()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $config = new Config();
        $config->nameservers[] = 'tcp://8.8.8.8:53';
        $config->nameservers[] = 'tcp://1.1.1.1:53';

        $factory = new Factory();
        $resolver = $factory->create($config, $loop);

        $this->assertInstanceOf('React\Dns\Resolver\Resolver', $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf('React\Dns\Query\CoopExecutor', $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf('React\Dns\Query\RetryExecutor', $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $fallbackExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf('React\Dns\Query\FallbackExecutor', $fallbackExecutor);

        $ref = new \ReflectionProperty($fallbackExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\TcpTransportExecutor', $tcpExecutor);

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

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\TcpTransportExecutor', $tcpExecutor);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $config = new Config();
        $config->nameservers[] = 'tcp://8.8.8.8:53';
        $config->nameservers[] = 'tcp://1.1.1.1:53';
        $config->nameservers[] = 'tcp://9.9.9.9:53';

        $factory = new Factory();
        $resolver = $factory->create($config, $loop);

        $this->assertInstanceOf('React\Dns\Resolver\Resolver', $resolver);

        $coopExecutor = $this->getResolverPrivateExecutor($resolver);

        $this->assertInstanceOf('React\Dns\Query\CoopExecutor', $coopExecutor);

        $ref = new \ReflectionProperty($coopExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $retryExecutor = $ref->getValue($coopExecutor);

        $this->assertInstanceOf('React\Dns\Query\RetryExecutor', $retryExecutor);

        $ref = new \ReflectionProperty($retryExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $fallbackExecutor = $ref->getValue($retryExecutor);

        $this->assertInstanceOf('React\Dns\Query\FallbackExecutor', $fallbackExecutor);

        $ref = new \ReflectionProperty($fallbackExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\TcpTransportExecutor', $tcpExecutor);

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

        $this->assertInstanceOf('React\Dns\Query\FallbackExecutor', $fallbackExecutor);

        $ref = new \ReflectionProperty($fallbackExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $timeoutExecutor = $ref->getValue($fallbackExecutor);

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\TcpTransportExecutor', $tcpExecutor);

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

        $this->assertInstanceOf('React\Dns\Query\TimeoutExecutor', $timeoutExecutor);

        $ref = new \ReflectionProperty($timeoutExecutor, 'executor');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcpExecutor = $ref->getValue($timeoutExecutor);

        $this->assertInstanceOf('React\Dns\Query\TcpTransportExecutor', $tcpExecutor);

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
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $factory = new Factory();

        $this->setExpectedException('InvalidArgumentException');
        $factory->create('///', $loop);
    }

    /** @test */
    public function createShouldThrowWhenConfigHasNoNameservers()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $factory = new Factory();

        $this->setExpectedException('UnderflowException');
        $factory->create(new Config(), $loop);
    }

    /** @test */
    public function createShouldThrowWhenConfigHasInvalidNameserver()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $factory = new Factory();

        $config = new Config();
        $config->nameservers[] = '///';

        $this->setExpectedException('InvalidArgumentException');
        $factory->create($config, $loop);
    }

    /** @test */
    public function createCachedShouldCreateResolverWithCachingExecutor()
    {
        $factory = new Factory();
        $resolver = $factory->createCached('8.8.8.8:53');

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

    /** @test */
    public function createThrowsExceptionForInvalidLoop()
    {
        $this->setExpectedException('InvalidArgumentException', 'Argument #2 ($loop) expected null|React\EventLoop\LoopInterface');
        $factory = new Factory();
        $factory->create('config', 'loop');
    }

    /** @test */
    public function createCachedThrowsExceptionForInvalidLoop()
    {
        $this->setExpectedException('InvalidArgumentException', 'Argument #2 ($loop) expected null|React\EventLoop\LoopInterface');
        $factory = new Factory();
        $factory->createCached('config', 'loop');
    }

    /** @test */
    public function createCachedThrowsExceptionForInvalidCache()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $this->setExpectedException('InvalidArgumentException', 'Argument #3 ($cache) expected null|React\Cache\CacheInterface');
        $factory = new Factory();
        $factory->createCached('config', $loop, 'cache');
    }

    private function getResolverPrivateExecutor($resolver)
    {
        $executor = $this->getResolverPrivateMemberValue($resolver, 'executor');

        // extract underlying executor that may be wrapped in multiple layers of hosts file executors
        while ($executor instanceof HostsFileExecutor) {
            $reflector = new \ReflectionProperty('React\Dns\Query\HostsFileExecutor', 'fallback');
            if (\PHP_VERSION_ID < 80100) {
                $reflector->setAccessible(true);
            }

            $executor = $reflector->getValue($executor);
        }

        return $executor;
    }

    private function getResolverPrivateMemberValue($resolver, $field)
    {
        $reflector = new \ReflectionProperty('React\Dns\Resolver\Resolver', $field);
        if (\PHP_VERSION_ID < 80100) {
            $reflector->setAccessible(true);
        }
        return $reflector->getValue($resolver);
    }

    private function getCachingExecutorPrivateMemberValue($resolver, $field)
    {
        $reflector = new \ReflectionProperty('React\Dns\Query\CachingExecutor', $field);
        if (\PHP_VERSION_ID < 80100) {
            $reflector->setAccessible(true);
        }
        return $reflector->getValue($resolver);
    }
}
