<?php

    require __DIR__ . '/init.php';

    /**
     * This script will output something like
     *

        HEADER:
                opcode: QUERY, status: OK, id: 58632
                flags: qr rd ra; QUERY: 1, ANSWER: 1, AUTHORITY: 0, ADDITIONAL: 0

        QUESTION SECTION:
                google.com.                          IN         SOA

        ANSWER SECTION:
                google.com.               21381      IN         SOA       ns1.google.com. dns-admin.google.com 2014021800 7200 1800 1209600 300


     */

    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);

    $dns->lookup('google.com', 'SOA')->then(function($response)
    {
        echo $response->explain();
    });


    $loop->run();