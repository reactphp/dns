<?php

require __DIR__.'/../vendor/autoload.php';

/*
    Hostname is google-public-dns-a.google.com

    HEADER:
            opcode: QUERY, status: OK, id: 20089
            flags: qr rd ra; QUERY: 1, ANSWER: 1, AUTHORITY: 0, ADDITIONAL: 0

    QUESTION SECTION:
            8.8.8.8.in-addr.arpa.                IN         PTR

    ANSWER SECTION:
            8.8.8.8.in-addr.arpa.     21152      IN         PTR        google-public-dns-a.google.com
*/

$loop = React\EventLoop\Factory::create();
$factory = new React\Dns\Resolver\Factory();
$dns = $factory->create('8.8.8.8', $loop);

$dns->reverse('8.8.8.8')->then(function ($response) {
    if (count($response->answers)) {
        echo 'Hostname is ' . $response->answers[0]->data . "\n\n\n";
        echo $response->explain();
    }
});

$loop->run();
