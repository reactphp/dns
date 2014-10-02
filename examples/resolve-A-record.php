<?php

    require __DIR__ . '/init.php';

    /**
     * This script will output something like
     *

        IP: 74.125.227.168

     */

    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);

    $dns->resolve('google.com')->then(function($ip)
    {
        echo "IP: $ip\n";
    });


    $loop->run();