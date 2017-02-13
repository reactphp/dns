# Dns Component

[![Build Status](https://secure.travis-ci.org/reactphp/dns.png?branch=master)](http://travis-ci.org/reactphp/dns) [![Code Climate](https://codeclimate.com/github/reactphp/dns/badges/gpa.svg)](https://codeclimate.com/github/reactphp/dns)

Async DNS resolver.

The main point of the DNS component is to provide async DNS resolution.
However, it is really a toolkit for working with DNS messages, and could
easily be used to create a DNS server.

## Basic usage

The most basic usage is to just create a resolver through the resolver
factory. All you need to give it is a nameserver, then you can start resolving
names, baby!

```php
$loop = React\EventLoop\Factory::create();
$factory = new React\Dns\Resolver\Factory();
$dns = $factory->create('8.8.8.8', $loop);

$dns->resolve('igor.io')->then(function ($ip) {
    echo "Host: $ip\n";
});

$loop->run();
```

See also the [first example](examples).

Pending DNS queries can be cancelled by cancelling its pending promise like so:

```php
$promise = $resolver->resolve('reactphp.org');

$promise->cancel();
```

But there's more.

## Caching

You can cache results by configuring the resolver to use a `CachedExecutor`:

```php
$loop = React\EventLoop\Factory::create();
$factory = new React\Dns\Resolver\Factory();
$dns = $factory->createCached('8.8.8.8', $loop);

$dns->resolve('igor.io')->then(function ($ip) {
    echo "Host: $ip\n";
});

...

$dns->resolve('igor.io')->then(function ($ip) {
    echo "Host: $ip\n";
});

$loop->run();
```

If the first call returns before the second, only one query will be executed.
The second result will be served from an in memory cache.
This is particularly useful for long running scripts where the same hostnames
have to be looked up multiple times.

See also the [third example](examples).

### Custom cache adapter

By default, the above will use an in memory cache.

You can also specify a custom cache implementing [`CacheInterface`](https://github.com/reactphp/cache) to handle the record cache instead:

```php
$cache = new React\Cache\ArrayCache();
$loop = React\EventLoop\Factory::create();
$factory = new React\Dns\Resolver\Factory();
$dns = $factory->createCached('8.8.8.8', $loop, $cache);
```

See also the wiki for possible [cache implementations](https://github.com/reactphp/react/wiki/Users#cache-implementations).

## Install

The recommended way to install this library is [through Composer](http://getcomposer.org).
[New to Composer?](http://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require react/dns:^0.4.4
```

More details about version upgrades can be found in the [CHANGELOG](CHANGELOG.md).

## License

MIT, see [LICENSE file](LICENSE).

## References

* [RFC 1034](http://tools.ietf.org/html/rfc1034) Domain Names - Concepts and Facilities
* [RFC 1035](http://tools.ietf.org/html/rfc1035) Domain Names - Implementation and Specification
