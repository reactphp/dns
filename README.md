# Dns Component

[![Build Status](https://secure.travis-ci.org/reactphp/dns.png?branch=master)](http://travis-ci.org/reactphp/dns)

Async DNS resolver and name server.

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

To resolve an IPv4/IPv4 to a hostname

```php
    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);
    $ipAddress = ''; // IPv4/IPv6
    $dns->reverse($ipAddress)->then(function ($response) {
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

## Supported RRs

* A
* AAAA
* ANY
* CNAME
* MX
* NS
* PTR
* TXT
* SOA

## Todo


* Respect /etc/hosts

# DNS Name Server

Want to write your own DNS server? No problem.

```php

    $loop = React\EventLoop\Factory::create();
    $server = new React\Dns\Server\Server($loop);
    $server->listen(53, '0.0.0.0');
    $server->ready();

    $server->on('query', function($question, $clientIP, $response, $deferred)
    {
        /**
            @var $question  React\Dns\Query\Query
            @var $request   React\Dns\Model\Message
            @var $deferred  React\Promise\Deferred
        */

        // Add records to answer
        //$response->answers[] = new \React\Dns\Model\Record($question->name, $question->type, $question->class, rand(1,9999),
        //                                                   'DATA BASED ON TYPE GOES HERE');

        // or add records to authority and additional
        // $response->authority[] = new  \React\Dns\Model\Record(....)
        // $response->additional[] = new  \React\Dns\Model\Record(....)

        $deferred->resolve($response);

        // or reject this
        // $deferred->reject($response);
    });


    // print some stats as well
    $loop->addPeriodicTimer(60, function() use($server)
    {
        echo "DNS Server stats:\n";
        $server->stats();
    });

    $loop->run();
```

# References

* [RFC1034](http://tools.ietf.org/html/rfc1034) Domain Names - Concepts and Facilities
* [RFC1035](http://tools.ietf.org/html/rfc1035) Domain Names - Implementation and Specification
* [RFC1035](http://tools.ietf.org/html/rfc3596) DNS Extensions to Support IP Version 6
