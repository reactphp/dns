<?php

use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\Query;
use React\Dns\Query\UdpTransportExecutor;
use React\EventLoop\Factory;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();
$executor = new UdpTransportExecutor($loop);

$name = isset($argv[1]) ? $argv[1] : 'google.com';

$any = new Query($name, Message::TYPE_ANY, Message::CLASS_IN);

$executor->query('8.8.8.8:53', $any)->then(function (Message $message) {
    foreach ($message->answers as $answer) {
        /* @var $answer Record */

        $data = $answer->data;

        switch ($answer->type) {
            case Message::TYPE_A:
                $type = 'A';
                break;
            case Message::TYPE_AAAA:
                $type = 'AAAA';
                break;
            case Message::TYPE_NS:
                $type = 'NS';
                break;
            case Message::TYPE_PTR:
                $type = 'PTR';
                break;
            case Message::TYPE_CNAME:
                $type = 'CNAME';
                break;
            case Message::TYPE_TXT:
                // TXT records can contain a list of (binary) strings for each record.
                // here, we assume this is printable ASCII and simply concatenate output
                $type = 'TXT';
                $data = implode('', $data);
                break;
            case Message::TYPE_MX:
                // MX records contain "priority" and "target", only dump its values here
                $type = 'MX';
                $data = implode(' ', $data);
                break;
            case Message::TYPE_SRV:
                // SRV records contains priority, weight, port and target, dump structure here
                $type = 'SRV';
                $data = json_encode($data);
                break;
            case Message::TYPE_SOA:
                // SOA records contain structured data, dump structure here
                $type = 'SOA';
                $data = json_encode($data);
                break;
            default:
                // unknown type uses HEX format
                $type = 'Type ' . $answer->type;
                $data = wordwrap(strtoupper(bin2hex($data)), 2, ' ', true);
        }

        echo $type . ': ' . $data . PHP_EOL;
    }
}, 'printf');

$loop->run();
