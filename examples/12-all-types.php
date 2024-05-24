<?php

// $ php examples/12-all-types.php
// $ php examples/12-all-types.php myserverplace.de SSHFP

use React\Dns\Config\Config;
use React\Dns\Resolver\Factory;

require __DIR__ . '/../vendor/autoload.php';

$config = Config::loadSystemConfigBlocking();
if (!$config->nameservers) {
    $config->nameservers[] = '8.8.8.8';
}

$factory = new Factory();
$resolver = $factory->create($config);

$name = $argv[1] ?? 'google.com';
assert(is_string($name));

$type = constant('React\Dns\Model\Message::TYPE_' . ($type ?? 'TXT'));
assert(is_int($type));

$resolver->resolveAll($name, $type)->then(static function (array $values): void {
    var_dump($values);
}, function (Throwable $e) {
    echo $e->getMessage() . PHP_EOL;
});
