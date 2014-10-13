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

    $server->on('query', function($request, $clientIP, $deferred)
    {
        $response = new React\Dns\Model\Message();
        $response->transport = $request->transport;
        $response->header->set('id', $request->header->attributes['id']);
        $response->header->set('qr', 1);                                         // 0 = Query, 1 = Response
        $response->header->set('aa', 1);                                         // 1 = Authoritative response
        $response->header->set('rd', $request->header->attributes['rd']);        // Recursion desired, copied from request
        $response->header->set('ra', 0);                                         // 0 = Server is non-recursive
        $response->header->set('opcode', $request->header->attributes['opcode']);
        $response->header->set('rcode', React\Dns\Model\Message::RCODE_OK);

        // throw in random TCP truncations
        if ($request->transport == 'udp' && rand(1,5) == 2)
            $response->header->set('tc', 1);

        /** @var  $question  \React\Dns\Query\Query */
        $question = $request->questions[0];
        $response->questions[] = $question;
        $response->answers[] = new \React\Dns\Model\Record($question->name, $question->type, $question->class, rand(1,9999), '');
        $deferred->resolve($response);
    });

    $loop->addPeriodicTimer(60, function() use($server)
    {
        echo "DNS Server stats:\n";
        print_r($server->stats);
    });

    $loop->run();