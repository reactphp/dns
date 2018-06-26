<?php

use React\Dns\Model\Message;
use React\Dns\Query\Query;
use React\Dns\Query\UdpTransportExecutor;
use React\EventLoop\Factory;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();
$executor = new UdpTransportExecutor($loop);

$name = isset($argv[1]) ? $argv[1] : 'www.google.com';

$ipv4Query = new Query($name, Message::TYPE_A, Message::CLASS_IN);
$ipv6Query = new Query($name, Message::TYPE_AAAA, Message::CLASS_IN);

$executor->query('8.8.8.8:53', $ipv4Query)->then(function (Message $message) {
    foreach ($message->answers as $answer) {
        echo 'IPv4: ' . $answer->data . PHP_EOL;
    }
}, 'printf');
$executor->query('8.8.8.8:53', $ipv6Query)->then(function (Message $message) {
    foreach ($message->answers as $answer) {
        echo 'IPv6: ' . $answer->data . PHP_EOL;
    }
}, 'printf');

$loop->run();
