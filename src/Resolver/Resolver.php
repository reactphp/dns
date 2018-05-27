<?php

namespace React\Dns\Resolver;

use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\RecordNotFoundException;
use React\Dns\Model\Message;
use React\Promise\PromiseInterface;

class Resolver
{
    private $nameserver;
    private $executor;

    public function __construct($nameserver, ExecutorInterface $executor)
    {
        $this->nameserver = $nameserver;
        $this->executor = $executor;
    }

    /**
     * Resolves the given $domain name to a single IPv4 address (type `A` query).
     *
     * ```php
     * $resolver->resolve('reactphp.org')->then(function ($ip) {
     *     echo 'IP for reactphp.org is ' . $ip . PHP_EOL;
     * });
     * ```
     *
     * This is one of the main methods in this package. It sends a DNS query
     * for the given $domain name to your DNS server and returns a single IP
     * address on success.
     *
     * If the DNS server sends a DNS response message that contains more than
     * one IP address for this query, it will randomly pick one of the IP
     * addresses from the response.
     *
     * If the DNS server sends a DNS response message that indicated an error
     * code, this method will reject with a `RecordNotFoundException`. Its
     * message and code can be used to check for the response code.
     *
     * If the DNS communication fails and the server does not respond with a
     * valid response message, this message will reject with an `Exception`.
     *
     * Pending DNS queries can be cancelled by cancelling its pending promise like so:
     *
     * ```php
     * $promise = $resolver->resolve('reactphp.org');
     *
     * $promise->cancel();
     * ```
     *
     * @param string $domain
     * @return PromiseInterface Returns a promise which resolves with a single IP address on success or
     *     rejects with an Exception on error.
     */
    public function resolve($domain)
    {
        $query = new Query($domain, Message::TYPE_A, Message::CLASS_IN);
        $that = $this;

        return $this->executor
            ->query($this->nameserver, $query)
            ->then(function (Message $response) use ($query, $that) {
                return $that->extractAddress($query, $response);
            });
    }

    public function extractAddress(Query $query, Message $response)
    {
        // reject if response code indicates this is an error response message
        $code = $response->getResponseCode();
        if ($code !== Message::RCODE_OK) {
            switch ($code) {
                case Message::RCODE_FORMAT_ERROR:
                    $message = 'Format Error';
                    break;
                case Message::RCODE_SERVER_FAILURE:
                    $message = 'Server Failure';
                    break;
                case Message::RCODE_NAME_ERROR:
                    $message = 'Non-Existent Domain / NXDOMAIN';
                    break;
                case Message::RCODE_NOT_IMPLEMENTED:
                    $message = 'Not Implemented';
                    break;
                case Message::RCODE_REFUSED:
                    $message = 'Refused';
                    break;
                default:
                    $message = 'Unknown error response code ' . $code;
            }
            throw new RecordNotFoundException(
                'DNS query for ' . $query->name . ' returned an error response (' . $message . ')',
                $code
            );
        }

        $answers = $response->answers;
        $addresses = $this->resolveAliases($answers, $query->name);

        // reject if we did not receive a valid answer (domain is valid, but no record for this type could be found)
        if (0 === count($addresses)) {
            throw new RecordNotFoundException(
                'DNS query for ' . $query->name . ' did not return a valid answer (NOERROR / NODATA)'
            );
        }

        $address = $addresses[array_rand($addresses)];
        return $address;
    }

    public function resolveAliases(array $answers, $name)
    {
        $named = $this->filterByName($answers, $name);
        $aRecords = $this->filterByType($named, Message::TYPE_A);
        $cnameRecords = $this->filterByType($named, Message::TYPE_CNAME);

        if ($aRecords) {
            return $this->mapRecordData($aRecords);
        }

        if ($cnameRecords) {
            $aRecords = array();

            $cnames = $this->mapRecordData($cnameRecords);
            foreach ($cnames as $cname) {
                $targets = $this->filterByName($answers, $cname);
                $aRecords = array_merge(
                    $aRecords,
                    $this->resolveAliases($answers, $cname)
                );
            }

            return $aRecords;
        }

        return array();
    }

    private function filterByName(array $answers, $name)
    {
        return $this->filterByField($answers, 'name', $name);
    }

    private function filterByType(array $answers, $type)
    {
        return $this->filterByField($answers, 'type', $type);
    }

    private function filterByField(array $answers, $field, $value)
    {
        $value = strtolower($value);
        return array_filter($answers, function ($answer) use ($field, $value) {
            return $value === strtolower($answer->$field);
        });
    }

    private function mapRecordData(array $records)
    {
        return array_map(function ($record) {
            return $record->data;
        }, $records);
    }
}
