<?php

namespace React\Dns\Server;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Socket\Server as TCPServer;
use React\Datagram\Factory as DatagramFactory;
use React\Datagram\Socket as DatagramSocket;
use React\Dns\Protocol\IPParser;
use React\Dns\Model\Message;
use React\Dns\Protocol\Parser;
use React\Dns\Protocol\BinaryDumper;

/** @event connection */
class Server extends EventEmitter implements ServerInterface
{
    /**
     * @var \React\EventLoop\LoopInterface
     */
    protected $loop;

    /**
     * @var DatagramFactory
     */
    protected $datagramFactory;

    /**
     * @var \React\Datagram\Socket
     */
    protected $serverUDP;

    /**
     * @var TCPServer
     */
    protected $serverTCP;

    /**
     * @var Parser
     */
    protected $parser;

    /**
     * @var BinaryDumper
     */
    protected $dumper;

    /**
     * @var IPParser
     */
    protected $ipParser;

    /**
     * @var string bind address
     */
    protected $bind = '127.0.0.1:53';

    /**
     * PDNS like stats http://doc.powerdns.com/html/recursor-stats.html
     * @var array stats
     */
    public $stats = [
        'uptime' => 0,                   // epoch time the server was started
        'questions' => 0,                // counts all End-user initiated queries with the RD bit set
        'questions-tcp' => 0,            // counts all incoming TCP queries
        'answers-ok' => 0,               // counts the number of times it answered Message::RCODE_OK
        'answers-name_error' => 0,       // counts the number of times it answered Message::NAME_ERROR
        'answers-server_failure' => 0,   // counts the number of times it answered Message::SERVER_FAILURE
        'answers0-1' => 0,               // counts the number of queries answered within 1 millisecond
        'answers100-1000' => 0,          // counts the number of queries answered within 1 second
        'answers10-100' => 0,            // counts the number of queries answered within 100 milliseconds
        'answers1-10' => 0,              // counts the number of queries answered within 10 milliseconds
        'answers-slow' => 0,             // counts the number of queries answered after 1 second
    ];

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
        $this->datagramFactory = new DatagramFactory($this->loop);
        $this->parser = new Parser();
        $this->dumper = new BinaryDumper();
        $this->ipParser = new IPParser();
        $this->stats['uptime'] = time();
    }

    /**
     * Start the server
     *
     * @param int $port
     * @param string $host
     */
    public function listen($port, $host = '127.0.0.1')
    {
        $this->bind = $host .':'. $port;
    }

    public function ready()
    {
        $this->startUDPServer();
        $this->startTCPServer();
    }

    /**
     * Starts UDP server
     */
    private function startUDPServer()
    {
        $this->datagramFactory->createServer($this->bind)->then(function (DatagramSocket $server) {
            $this->serverUDP = $server;

            $this->serverUDP->on('message', function ($data, $address, $server) {

                $message = new Message();
                $request = $this->parser->parseChunk($data, $message);

                if ($request) {

                    list($clientIP, $clientPort) = explode(':', $address);

                    $deferred = new Deferred();
                    $promise = $deferred->promise();
                    $promise->then(function($response) use($server, $address)
                    {
                        $this->handleReply($response, $server, $address);
                    }, function($response) use($server, $address)
                    {
                        // @todo track stats?
                    });

                    $this->handleQuery($request, $clientIP, $deferred);
                }
            });
        });
    }

    private function startTCPServer()
    {
        $this->serverTCP = new TCPServer($this->loop);

        list($ip, $port) = explode(':', $this->bind);
        $this->serverTCP->listen($port, $ip);

        $this->serverTCP->on('connection', function($client) {

            $client->on('data', function ($data) use ($client) {

                $message = new Message();
                $message->transport = 'tcp';
                $request = $this->parser->parseChunk($data, $message);

                if ($request) {

                    $deferred = new Deferred();
                    $promise = $deferred->promise();
                    $promise->then(function($response) use($client) {
                        $this->handleReply($response, $client);
                    }, function($response) use($client) {
                        // @todo track stats?
                        $client->end();
                    });

                    $this->handleQuery($request, $client->getRemoteAddress(), $deferred);
                }
            });
        });
    }

    /**
     * Emits "query"
     * @param Message $request
     * @param         $clientIP
     * @param         $successCallback
     */
    protected function handleQuery(Message $request, $clientIP, $successCallback)
    {
        $this->stats['questions']++;

        if ($request->transport == 'tcp')
            $this->stats['questions-tcp']++;

        $response = new Message();
        $response->transport = $request->transport;
        $response->header->set('id', $request->header->attributes['id']);
        $response->header->set('qr', 1);                                         // 0 = Query, 1 = Response
        $response->header->set('aa', 1);                                         // 1 = Authoritative response
        $response->header->set('rd', $request->header->attributes['rd']);        // Recursion desired, copied from request
        $response->header->set('ra', 0);                                         // 0 = Server is non-recursive
        $response->header->set('opcode', $request->header->attributes['opcode']);
        $response->header->set('rcode',Message::RCODE_OK);

        $question = $request->questions[0];
        $response->questions[] = $question;

        $this->emit('query', [$question, $clientIP, $request, $successCallback]);
    }

    /**
     * @param Message $response
     * @param DatagramSocket|Connection $transporter
     * @param string $transporterAddress used for UDP only
     */
    protected function handleReply(Message $response, $transporter, $transporterAddress = '')
    {
        $response->prepare();
        $reply = $this->dumper->toBinary($response);

        if ($response->transport == 'udp') {
            $transporter->send($reply, $transporterAddress);
        }
        else {
            $transporter->write($reply);
            $transporter->end();
        }

        $this->statsAnswerTime($response);
    }

    /**
     * Keeps track of answers time
     *
     * @param Message $response
     */
    private function statsAnswerTime(Message $response)
    {
        if (!$response->execTime)
            $response->markEndTime();

        $execTime = $response->execTime;

        if ($execTime <= 1)
            $this->stats['answers0-1']++;
        else if ($execTime > 1 && $execTime < 10)
            $this->stats['answers1-10']++;
        else if ($execTime >= 10 && $execTime < 100)
            $this->stats['answers10-100']++;
        else if ($execTime >= 100 && $execTime < 1000)
            $this->stats['answers100-1000']++;
        else
            $this->stats['answers-slow']++;
    }

    /**
     * get pretty stats
     */
    public function stats()
    {
        // @todo
        print_r($this->stats);
    }
}
