<?php

    require __DIR__ . '/init.php';

    /**
     * This is a replace for PDNS mysql backend as an example
     * You would need react-mysql package for this to run
     */

    $loop = React\EventLoop\Factory::create();

    /**
     * Fill in database credentials for pdns database
     */
    $db = new React\MySQL\Connection($loop, [
        'dbname' => 'pdns',
        'user'   => '',
        'passwd' => '',
        //'host' => '127.0.0.1'
    ]);

    try {
        $db->connect(function ($err, $conn) {
            if ($err instanceof \Exception) {
                $error = $err->getMessage();
            }
        });
    } catch(\Exception $e)
    {
        // also thowrs exception
    }

    $server = new React\Dns\Server\Server($loop);
    $server->listen(53, '0.0.0.0');
    $server->ready();

    $server->on('query', function($question, $clientIP, $response, $deferred) use($db)
    {
        /**
            @var $question  React\Dns\Query\Query
            @var $request   React\Dns\Model\Message
            @var $deferred  React\Promise\Deferred
        */

        $db->query('SELECT * FROM records WHERE name = ? AND type = ?', $question->name, $question->code,
                    function ($command, $conn) use($deferred, $response)
                    {
                        $deferred->resolve($response);
                    });
    });


    $loop->run();