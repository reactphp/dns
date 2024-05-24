<?php

// $ php examples/92-query-any.php mailbox.org
// $ php examples/92-query-any.php _carddav._tcp.mailbox.org

use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\Query;
use React\Dns\Query\TcpTransportExecutor;
use React\EventLoop\Factory;

require __DIR__ . '/../vendor/autoload.php';

$executor = new TcpTransportExecutor('8.8.8.8:53');

$name = $argv[1] ?? 'google.com';
assert(is_string($name));

$any = new Query($name, Message::TYPE_ANY, Message::CLASS_IN);

$executor->query($any)->then(function (Message $message): void {
    foreach ($message->answers as $answer) {
        $data = $answer->data;

        switch ($answer->type) {
            case Message::TYPE_A:
                assert(\is_string($data));
                $type = 'A';
                break;
            case Message::TYPE_AAAA:
                assert(\is_string($data));
                $type = 'AAAA';
                break;
            case Message::TYPE_NS:
                assert(\is_string($data));
                $type = 'NS';
                break;
            case Message::TYPE_PTR:
                assert(\is_string($data));
                $type = 'PTR';
                break;
            case Message::TYPE_CNAME:
                assert(\is_string($data));
                $type = 'CNAME';
                break;
            case Message::TYPE_TXT:
                assert(\is_array($data));
                // TXT records can contain a list of (binary) strings for each record.
                // here, we assume this is printable ASCII and simply concatenate output
                $type = 'TXT';
                $data = implode('', $data);
                break;
            case Message::TYPE_MX:
                assert(\is_array($data));
                // MX records contain "priority" and "target", only dump its values here
                $type = 'MX';
                $data = implode(' ', $data);
                break;
            case Message::TYPE_SRV:
                assert(\is_array($data));
                // SRV records contain priority, weight, port and target, dump structure here
                $type = 'SRV';
                $data = json_encode($data);
                break;
            case Message::TYPE_SSHFP:
                assert(\is_array($data));
                // SSHFP records contain algorithm, fingerprint type and hex fingerprint value
                $type = 'SSHFP';
                $data = implode(' ', $data);
                break;
            case Message::TYPE_SOA:
                assert(\is_array($data));
                // SOA records contain structured data, dump structure here
                $type = 'SOA';
                $data = json_encode($data);
                break;
            case Message::TYPE_CAA:
                assert(\is_array($data));
                // CAA records contains flag, tag and value
                $type = 'CAA';
                $data = $data['flag'] . ' ' . $data['tag'] . ' "' . $data['value'] . '"';
                break;
            default:
                assert(\is_string($data));
                // unknown type uses HEX format
                $type = 'TYPE' . $answer->type;
                $data = wordwrap(strtoupper(bin2hex($data)), 2, ' ', true);
        }

        echo $type . ': ' . $data . PHP_EOL;
    }
}, static function (Throwable $error) {
    echo $error;
});
