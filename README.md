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
        // $response->answers[] = new \React\Dns\Model\Record($question->name, $question->type, $question->class,
        //                                                    rand(1,9999), 'DATA BASED ON TYPE GOES HERE');

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

# Other debugging tools

If you are curious about DNS data send over the wire between client and name server then the following might be helpful.

## Dumping request data

```php

    $dumper = new \React\Dns\Protocol\BinaryDumper();
    $query = new \React\Dns\Query\Query('igor.io',
                 \React\Dns\Model\Message::TYPE_A,
                 \React\Dns\Model\Message::CLASS_IN, time());

    $request = new \React\Dns\Model\Message();
    $request->header->set('id', mt_rand(0, 0xffff));
    $request->header->set('rd', 1);
    $request->questions[] = $query;
    $request->prepare();

    // or view dump when querying in TCP mode
    //$request->transport = 'tcp';

    // data send over wire
    $queryData = $dumper->toBinary($request);

    \React\Dns\Protocol\HumanParser::dumpHex($queryData);
```

The output would be:

     0 : b2 43 01 00 00 01 00 00 00 00 00 00 04 69 67 6f [.C...........igo]
    10 : 72 02 69 6f 00 00 01 00 01 [r.io.....]

## Dumping response

```php
    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);

    $dns->lookup('ipv6.google.com', 'AAAA')->then(function($response)
    {
        \React\Dns\Protocol\HumanParser::dumpHex($response->data);
    });

    $loop->run();
```

The output would be something like:

     0 : 9c be 81 80 00 01 00 02 00 00 00 00 04 69 70 76 [.............ipv]
    10 : 36 06 67 6f 6f 67 6c 65 03 63 6f 6d 00 00 1c 00 [6.google.com....]
    20 : 01 c0 0c 00 05 00 01 00 00 53 f7 00 09 04 69 70 [.........S....ip]
    30 : 76 36 01 6c c0 11 c0 2d 00 1c 00 01 00 00 00 c3 [v6.l...-........]
    40 : 00 10 26 07 f8 b0 40 00 08 04 00 00 00 00 00 00 [..&...@.........]
    50 : 10 00 [..]

## Debugging DNS Header flags

To debug Header flags of [RFC 1035 @ 4.1.1. Header section format](http://tools.ietf.org/html/rfc1035) try the following:

```php
    $request = new \React\Dns\Model\Message();
    $request->header->set('id', 0x7262);
    $request->header->set('qr', 0);
    $request->header->set('tc', 1);
    $request->header->set('rd', 1);

    $dumper = new \React\Dns\Protocol\BinaryDumper();
    $data = $dumper->toBinary($request);

    // header flags is octet 3-4
    list($fields) = array_values(unpack('n', substr($data, 2, 2)));

    echo \React\Dns\Protocol\HumanParser::explainHeaderFlagsBinary($fields);
```

The output would be:
    Flags Value: 300
    16 Bit Binary: 0000001100000000

          Flag: Binary Value         [Explanation]
            QR: 0                    [0 = Query, 1 = Response]
        Opcode: 0000                 [Decimal value 0 = standard query]
            AA: 0                    [1 = Authoritative Answer]
            TC: 1                    [1 = Message truncated]
            RD: 1                    [1 = Recursion Desired]
            RA: 0                    [1 = Recursion Available]
             Z: 000                  [Future use]
         RCODE: 0000                 [Human value = OK]


# References

* [RFC1034](http://tools.ietf.org/html/rfc1034) Domain Names - Concepts and Facilities
* [RFC1035](http://tools.ietf.org/html/rfc1035) Domain Names - Implementation and Specification
* [RFC1035](http://tools.ietf.org/html/rfc3596) DNS Extensions to Support IP Version 6
