<?php

use React\Dns\Config\Config;
use React\Dns\Resolver\Factory;
use React\Dns\Model\Message;

require __DIR__ . '/../vendor/autoload.php';

$config = Config::loadSystemConfigBlocking();
if (!$config->nameservers) {
    $config->nameservers[] = '8.8.8.8';
}

$factory = new Factory();
$resolver = $factory->create($config);

$ip = $argv[1] ?? '8.8.8.8';
assert(is_string($ip));
$ip = @inet_pton($ip);

if ($ip === false) {
    exit('Error: Given argument is not a valid IP' . PHP_EOL);
}

if (strpos($ip, ':') === false) {
    $name = inet_ntop(strrev($ip)) . '.in-addr.arpa';
} else {
    $name = wordwrap(strrev(bin2hex($ip)), 1, '.', true) . '.ip6.arpa';
}
assert(is_string($name));

$resolver->resolveAll($name, Message::TYPE_PTR)->then(function (array $names) {
    var_dump($names);
}, static function (Throwable $error) {
    echo $error;
});
