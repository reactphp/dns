<?php

    require __DIR__ . '/init.php';

    /**
     * This script will output something like
     *

        HEADER:
                opcode: QUERY, status: OK, id: 30649
                flags: qr rd ra; QUERY: 1, ANSWER: 2, AUTHORITY: 0, ADDITIONAL: 0

        QUESTION SECTION:
                ipv6.google.com.                     IN         AAAA

        ANSWER SECTION:
                ipv6.google.com.          21550      IN         CNAME       ipv6.l.google.com
                ipv6.l.google.com.        250        IN         AAAA        2607:f8b0:4000:804::1000

        Query time: 24 ms
        Name Server: 8.8.8.8:53
        Transport: udp
        Message Size: 82
     */

    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);

    $dns->lookup('ipv6.google.com', 'AAAA')->then(function($response)
    {
        echo $response->explain();
    });


    $loop->run();