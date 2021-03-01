<?php

use React\Dns\Config\Config;
use React\Dns\Resolver\Factory;
use React\Dns\Model\Message;

require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$factory = new Factory();
$resolver = $factory->createFromConfig(Config::loadSystemConfigBlocking(), $loop, '8.8.8.8');

$ip = isset($argv[1]) ? $argv[1] : '8.8.8.8';

if (@inet_pton($ip) === false) {
    exit('Error: Given argument is not a valid IP' . PHP_EOL);
}

if (strpos($ip, ':') === false) {
    $name = inet_ntop(strrev(inet_pton($ip))) . '.in-addr.arpa';
} else {
    $name = wordwrap(strrev(bin2hex(inet_pton($ip))), 1, '.', true) . '.ip6.arpa';
}

$resolver->resolveAll($name, Message::TYPE_PTR)->then(function (array $names) {
    var_dump($names);
}, function (Exception $e) {
    echo $e->getMessage() . PHP_EOL;
});

$loop->run();
