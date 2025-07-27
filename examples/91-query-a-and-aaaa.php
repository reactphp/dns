<?php

use React\Dns\Model\Message;
use React\Dns\Query\Query;
use React\Dns\Query\UdpTransportExecutor;
use React\EventLoop\Factory;

require __DIR__ . '/../vendor/autoload.php';

$executor = new UdpTransportExecutor('8.8.8.8:53');

$name = $argv[1] ?? 'www.google.com';
assert(is_string($name));

$ipv4Query = new Query($name, Message::TYPE_A, Message::CLASS_IN);
$ipv6Query = new Query($name, Message::TYPE_AAAA, Message::CLASS_IN);

$executor->query($ipv4Query)->then(static function (Message $message): void {
    foreach ($message->answers as $answer) {
        assert(\is_string($answer->data));
        echo 'IPv4: ' . $answer->data . PHP_EOL;
    }
}, static function (Throwable $error) {
    echo $error;
});
$executor->query($ipv6Query)->then(static function (Message $message): void {
    foreach ($message->answers as $answer) {
        assert(\is_string($answer->data));
        echo 'IPv6: ' . $answer->data . PHP_EOL;
    }
}, static function (Throwable $error) {
    echo $error;
});
