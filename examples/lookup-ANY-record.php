<?php

    require __DIR__ . '/init.php';

    /**
     * This script will output something like
     *

        HEADER:
                opcode: QUERY, status: OK, id: 45413
                flags: qr rd ra; QUERY: 1, ANSWER: 6, AUTHORITY: 0, ADDITIONAL: 0

        QUESTION SECTION:
                mine.pk.                             IN         ANY

        ANSWER SECTION:
                mine.pk.                  3468       IN         NS          ns2.root.pk
                mine.pk.                  21468      IN         MX       20 mx2.zohomail.com
                mine.pk.                  21468      IN         MX       10 mx.zohomail.com
                mine.pk.                  3468       IN         NS          ns1.root.pk
                mine.pk.                  21468      IN         SOA         ns1.root.pk. hostmaster.root.pk 1349330445 3600 300 1814400 300
                mine.pk.                  3468       IN         A           192.3.88.195


     */

    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);

    $dns->lookup('mine.pk', 'ANY')->then(function($response)
    {
        echo $response->explain();
    });


    $loop->run();