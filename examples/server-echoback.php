<?php

    require __DIR__ . '/init.php';

    /**
     * This script demonstrate a simple echo back dns server
     *
     */

    $loop = React\EventLoop\Factory::create();

    $server = new React\Dns\Server\Server($loop);
    $server->listen(53, '0.0.0.0');
    $server->ready();

    $server->on('query', function($question, $clientIP, $response, $deferred)
    {
        /**
            @var $question  React\Dns\Query\Query
            @var $request   React\Dns\Model\Message
            @var $deferred  React\Promise\Deferred
        */

        // throw in random TCP truncations
        if ($response->transport == 'udp' && rand(1,5) == 2)
            $response->header->set('tc', 1);

        //$response->answers[] = new \React\Dns\Model\Record($question->name, $question->type, $question->class, rand(1,9999), '');

        $deferred->resolve($response);
    });

    $loop->addPeriodicTimer(60, function() use($server)
    {
        echo "DNS Server stats:\n";
        print_r($server->stats);
    });

    $loop->run();