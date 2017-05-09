<?php


namespace React\Dns\Query;


use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Promise\Deferred;

class HostsExecutor implements ExecutorInterface
{
    private $ipv4 = [];
    private $ivp6 = [];

    public function __construct($file = '/etc/hosts')
    {
        if (file_exists($file)) {
            foreach(file($file) as $line) {
                // IPV4
                if (preg_match('/^(?<ip>(?:\d+\.){3}\d+)\s*(?<name>.*?)\s*$/', $line, $matches)) {
                    $this->add4($matches['ip'], $matches['name']);
                } elseif (preg_match('/^(?<ip>(?:[:a-f0-9]+))\s*(?<name>.*?)\s*$/', $line, $matches)) {
                    $this->add6($matches['ip'], $matches['name']);
                }
            }
        }
    }


    public function query($nameserver, Query $query)
    {
        $result = new Deferred();
        if ($query->type === Message::TYPE_A
            && isset($this->ipv4[$query->name])) {
            $response = new Message();
            $response->answers[] = new Record($query->name, $query->type, $query->class, 0, $this->ipv4[$query->name]);
            $result->resolve($response);
        } else {
            $result->reject('Name not found in hosts file.');
        }
        return $result->promise();
    }

    public function add4($address, $name)
    {
        $this->ipv4[$name] = $address;
    }

    public function add6($address, $name)
    {
        $this->ipv6[$name] = $address;

    }
}