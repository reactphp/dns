<?php

require __DIR__.'/../vendor/autoload.php';

/*
    HEADER:
            opcode: QUERY, status: OK, id: 24202
            flags: qr rd ra; QUERY: 1, ANSWER: 1, AUTHORITY: 0, ADDITIONAL: 0

    QUESTION SECTION:
            www.mine.pk.                         IN         CNAME

    ANSWER SECTION:
            www.mine.pk.              3599       IN         CNAME     mine.pk
*/

$loop = React\EventLoop\Factory::create();
$factory = new React\Dns\Resolver\Factory();
$dns = $factory->create('8.8.8.8', $loop);

$dns->lookup('www.mine.pk', 'CNAME')->then(function ($response) {
    echo $response->explain();
});

$loop->run();
