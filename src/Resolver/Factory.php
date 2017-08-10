<?php

namespace React\Dns\Resolver;

use React\Cache\ArrayCache;
use React\Cache\CacheInterface;
use React\Dns\Query\Executor;
use React\Dns\Query\CachedExecutor;
use React\Dns\Query\RecordCache;
use React\Dns\Protocol\Parser;
use React\Dns\Protocol\BinaryDumper;
use React\EventLoop\LoopInterface;
use React\Dns\Query\RetryExecutor;
use React\Dns\Query\TimeoutExecutor;

class Factory
{
    const UNIX_RESOLVE_CONF = '/etc/resolv.conf';

    public function create($nameserver, LoopInterface $loop)
    {
        $nameserver = $this->addPortToServerIfMissing($nameserver);
        $executor = $this->createRetryExecutor($loop);

        return new Resolver($nameserver, $executor);
    }

    public function createCached($nameserver, LoopInterface $loop, CacheInterface $cache = null)
    {
        if (!($cache instanceof CacheInterface)) {
            $cache = new ArrayCache();
        }

        $nameserver = $this->addPortToServerIfMissing($nameserver);
        $executor = $this->createCachedExecutor($loop, $cache);

        return new Resolver($nameserver, $executor);
    }

    public function createSystemDefault(LoopInterface $loop)
    {
        return $this->create($this->determineSystemDefaultServer(), $loop);
    }

    public function createSystemDefaultCached(LoopInterface $loop, CacheInterface $cache = null)
    {
        return $this->createCached($this->determineSystemDefaultServer(), $loop, $cache);
    }

    protected function createExecutor(LoopInterface $loop)
    {
        return new TimeoutExecutor(
            new Executor($loop, new Parser(), new BinaryDumper(), null),
            5.0,
            $loop
        );
    }

    protected function createRetryExecutor(LoopInterface $loop)
    {
        return new RetryExecutor($this->createExecutor($loop));
    }

    protected function createCachedExecutor(LoopInterface $loop, CacheInterface $cache)
    {
        return new CachedExecutor($this->createRetryExecutor($loop), new RecordCache($cache));
    }

    protected function addPortToServerIfMissing($nameserver)
    {
        if (strpos($nameserver, '[') === false && substr_count($nameserver, ':') >= 2) {
            // several colons, but not enclosed in square brackets => enclose IPv6 address in square brackets
            $nameserver = '[' . $nameserver . ']';
        }
        // assume a dummy scheme when checking for the port, otherwise parse_url() fails
        if (parse_url('dummy://' . $nameserver, PHP_URL_PORT) === null) {
            $nameserver .= ':53';
        }

        return $nameserver;
    }

    protected function determineSystemDefaultServer()
    {
        $nameservers = array();

        if (stripos(PHP_OS, 'win') === false) {
            $lines = file(self::UNIX_RESOLVE_CONF);
            foreach ($lines as $line) {
                $nameserverPosition = stripos($line, 'nameserver');
                if ($nameserverPosition !== false) {
                    $nameserverLine = trim(substr($line, $nameserverPosition + 11));
                    list ($nameservers[]) = explode(' ', $nameserverLine);
                }
            }
        }

        if (count($nameservers) > 0) {
            shuffle($nameservers);
            return array_pop($nameservers);
        }

        throw new \Exception('Nameserver configuration missing');
    }
}
