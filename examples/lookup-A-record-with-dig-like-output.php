<?php

    require __DIR__ . '/init.php';

    /**
     * This script will output something like
     *

        HEADER:
                opcode: QUERY, status: OK, id: 64689
                flags: qr rd ra; QUERY: 1, ANSWER: 11, AUTHORITY: 0, ADDITIONAL: 0

        QUESTION SECTION:
                google.com.                          IN         A

        ANSWER SECTION:
                google.com.               136        IN         A          74.125.227.194
                google.com.               136        IN         A          74.125.227.199
                google.com.               136        IN         A          74.125.227.196
                google.com.               136        IN         A          74.125.227.197
                google.com.               136        IN         A          74.125.227.193
                google.com.               136        IN         A          74.125.227.195
                google.com.               136        IN         A          74.125.227.200
                google.com.               136        IN         A          74.125.227.198
                google.com.               136        IN         A          74.125.227.201
                google.com.               136        IN         A          74.125.227.192
                google.com.               136        IN         A          74.125.227.206

     */

    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);

    $dns->lookup('google.com', 'A')->then(function($response)
    {
        echo $response->explain();
    });


    $loop->run();