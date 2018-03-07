<?php

use React\Dns\Resolver\Factory;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$factory = new Factory();
$resolver = $factory->create('8.8.8.8', $loop);

$name = isset($argv[1]) ? $argv[1] : 'blog.wyrihaximus.net';

$resolver->resolveAll($name)->then(function ($ips) use ($name) {
    foreach ($ips as $ip) {
        echo 'IP for ' . $name . ': ' . $ip . PHP_EOL;
    }
}, 'printf');

$loop->run();
