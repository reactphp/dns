# Dns Component

[![Build Status](https://secure.travis-ci.org/reactphp/dns.png?branch=master)](http://travis-ci.org/reactphp/dns)

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
```

## Advanced Usage

To get more detailed response (in a `dig` like fashion) about DNS

```php
    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);

    $dns->lookup('igor.io')->then(function ($response) {
        echo $response->explain();

    });
```

## Reverse IP Lookup

To resolve an IP to a hostname

```php
    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);

    $dns->reverse('8.8.8.8')->then(function ($response) {
        if (count($response->answers))
        {
            $hostname =  $response->answers[0]->data;
            echo $response->explain();
        }

    });
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
```

If the first call returns before the second, only one query will be executed.
The second result will be served from cache.

## Todo

* Implement message body parsing for types other than A, CNAME, NS, SOA, PTR, MX, ANY and TXT: AAAA
* Implement `authority` and `additional` message parts
* Respect /etc/hosts

# References

* [RFC1034](http://tools.ietf.org/html/rfc1034) Domain Names - Concepts and Facilities
* [RFC1035](http://tools.ietf.org/html/rfc1035) Domain Names - Implementation and Specification
