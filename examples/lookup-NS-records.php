<?php

    require __DIR__ . '/init.php';

    /**
     * This script will output something like
     *

        NS Server: ns1.google.com
        NS Server: ns4.google.com
        NS Server: ns2.google.com
        NS Server: ns3.google.com

     */

    $loop = React\EventLoop\Factory::create();
    $factory = new React\Dns\Resolver\Factory();
    $dns = $factory->create('8.8.8.8', $loop);

    $dns->lookup('google.com', 'NS')->then(function($response)
    {
        foreach($response->answers as $record)
        {
            echo "NS Server: ". $record->data . "\n";
        }
    });


    $loop->run();