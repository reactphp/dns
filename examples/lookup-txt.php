<?php

require __DIR__.'/../vendor/autoload.php';

/*
    HEADER:
            opcode: QUERY, status: OK, id: 1521
            flags: qr rd ra; QUERY: 1, ANSWER: 1, AUTHORITY: 0, ADDITIONAL: 0

    QUESTION SECTION:
            google.com.                          IN         TXT

    ANSWER SECTION:
            google.com.               3052       IN         TXT       "v=spf1 include:_spf.google.com ip4:216.73.93.70/31 ip4:216.73.93.72/31 ~all"
*/

$loop = React\EventLoop\Factory::create();
$factory = new React\Dns\Resolver\Factory();
$dns = $factory->create('8.8.8.8', $loop);

$dns->lookup('google.com', 'TXT')->then(function ($response) {
    echo $response->explain();
});

$loop->run();
