<?php

namespace React\Dns\Resolver;

use React\Cache\CacheInterface;
use React\Dns\Config\Config;
use React\Dns\Query\ExecutorFactory;
use React\EventLoop\LoopInterface;

final class Factory
{

    private $executorFactory;

    /**
     * @param ExecutorFactory $executorFactory
     */
    public function __construct(ExecutorFactory $executorFactory = null)
    {
        $this->executorFactory = $executorFactory ?: new ExecutorFactory();
    }

    /**
     * Creates a DNS resolver instance for the given DNS config
     *
     * As of v1.7.0 it's recommended to pass a `Config` object instead of a
     * single nameserver address. If the given config contains more than one DNS
     * nameserver, all DNS nameservers will be used in order. The primary DNS
     * server will always be used first before falling back to the secondary or
     * tertiary DNS server.
     *
     * @param Config|string $config DNS Config object (recommended) or single nameserver address
     * @param ?LoopInterface $loop
     * @return \React\Dns\Resolver\ResolverInterface
     * @throws \InvalidArgumentException for invalid DNS server address
     * @throws \UnderflowException when given DNS Config object has an empty list of nameservers
     */
    public function create($config, LoopInterface $loop = null)
    {
        return new Resolver($this->executorFactory->create($config, $loop));
    }

    /**
     * Creates a cached DNS resolver instance for the given DNS config and cache
     *
     * As of v1.7.0 it's recommended to pass a `Config` object instead of a
     * single nameserver address. If the given config contains more than one DNS
     * nameserver, all DNS nameservers will be used in order. The primary DNS
     * server will always be used first before falling back to the secondary or
     * tertiary DNS server.
     *
     * @param Config|string $config DNS Config object (recommended) or single nameserver address
     * @param ?LoopInterface $loop
     * @param ?CacheInterface $cache
     * @return \React\Dns\Resolver\ResolverInterface
     * @throws \InvalidArgumentException for invalid DNS server address
     * @throws \UnderflowException when given DNS Config object has an empty list of nameservers
     */
    public function createCached($config, LoopInterface $loop = null, CacheInterface $cache = null)
    {
        return new Resolver($this->executorFactory->createCached($config, $loop, $cache));
    }


}
