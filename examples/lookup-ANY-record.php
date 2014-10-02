<?php

    require __DIR__ . '/init.php';

    /**
     * This script will output something like
     *




     */

    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);

    $dns->lookup('google.com')->then(function($response)
    {
        echo $response->explain();
    });


    $loop->run();