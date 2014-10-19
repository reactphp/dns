<?php

require __DIR__.'/../vendor/autoload.php';

/*
    HEADER:
            opcode: QUERY, status: OK, id: 33410
            flags: qr rd ra; QUERY: 1, ANSWER: 5, AUTHORITY: 0, ADDITIONAL: 0

    QUESTION SECTION:
            google.com.                          IN         MX

    ANSWER SECTION:
            google.com.               374        IN         MX       50 alt4.aspmx.l.google.com
            google.com.               374        IN         MX       30 alt2.aspmx.l.google.com
            google.com.               374        IN         MX       10 aspmx.l.google.com
            google.com.               374        IN         MX       20 alt1.aspmx.l.google.com
            google.com.               374        IN         MX       40 alt3.aspmx.l.google.com
*/

$loop = React\EventLoop\Factory::create();
$factory = new React\Dns\Resolver\Factory();
$dns = $factory->create('8.8.8.8', $loop);

$dns->lookup('google.com', 'MX')->then(function ($response) {
    echo $response->explain();
});

$loop->run();
