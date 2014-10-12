<?php

    require __DIR__ . '/init.php';

    /**
     * This script will output something like
     *

        Hostname is ns6.yahoo.com


        HEADER:
                opcode: QUERY, status: OK, id: 45041
                flags: qr rd ra; QUERY: 1, ANSWER: 1, AUTHORITY: 0, ADDITIONAL: 0

        QUESTION SECTION:
                6.0.0.1.0.0.0.0.0.0.0.0.0.0.0.0.4.0.0.0.8.0.1.0.0.0.0.2.6.0.4.2.ip6.arpa.            IN         PTR

        ANSWER SECTION:
                6.0.0.1.0.0.0.0.0.0.0.0.0.0.0.0.4.0.0.0.8.0.1.0.0.0.0.2.6.0.4.2.ip6.arpa. 1744       IN         PTR         ns6.yahoo.com

     */

    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);

    $dns->reverse('2406:2000:108:4::1006')->then(function($response)
    {
        if (count($response->answers))
        {
            echo 'Hostname is ' . $response->answers[0]->data . "\n\n\n";
            echo $response->explain();
        }
    });


    $loop->run();