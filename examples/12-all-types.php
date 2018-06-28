<?php

use React\Dns\Config\Config;
use React\Dns\Resolver\Factory;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$config = Config::loadSystemConfigBlocking();
$server = $config->nameservers ? reset($config->nameservers) : '8.8.8.8';

$factory = new Factory();
$resolver = $factory->create($server, $loop);

$name = isset($argv[1]) ? $argv[1] : 'google.com';
$type = constant('React\Dns\Model\Message::TYPE_' . (isset($argv[2]) ? $argv[2] : 'TXT'));

$resolver->resolveAll($name, $type)->then(function (array $values) {
    var_dump($values);
}, function (Exception $e) {
    echo $e->getMessage() . PHP_EOL;
});

$loop->run();
