<?php

require __DIR__.'/../vendor/autoload.php';

/*
    google.com.               194        IN         A          173.194.115.1
    google.com.               194        IN         A          173.194.115.3
    google.com.               194        IN         A          173.194.115.8
    google.com.               194        IN         A          173.194.115.0
    google.com.               194        IN         A          173.194.115.7
    google.com.               194        IN         A          173.194.115.6
    google.com.               194        IN         A          173.194.115.5
    google.com.               194        IN         A          173.194.115.4
    google.com.               194        IN         A          173.194.115.9
    google.com.               194        IN         A          173.194.115.2
    google.com.               194        IN         A          173.194.115.14
*/

$loop = React\EventLoop\Factory::create();
$factory = new React\Dns\Resolver\Factory();
$dns = $factory->create('8.8.8.8', $loop);

$dns->lookup('google.com', 'A')->then(function ($response) {
    foreach ($response->answers as $record)
    {
        echo $record->explain();
    }
});

$loop->run();
