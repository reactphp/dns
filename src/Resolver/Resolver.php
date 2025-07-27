<?php

namespace React\Dns\Resolver;

use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\RecordNotFoundException;
use React\Promise\PromiseInterface;

/**
 * @see ResolverInterface for the base interface
 */
final class Resolver implements ResolverInterface
{
    /**
     * @var ExecutorInterface
     */
    private $executor;

    public function __construct(ExecutorInterface $executor)
    {
        $this->executor = $executor;
    }

    public function resolve(string $domain): PromiseInterface
    {
        return $this->resolveAll($domain, Message::TYPE_A)->then(function (array $ips) {
            return $ips[array_rand($ips)];
        });
    }

    public function resolveAll(string $domain, int $type): PromiseInterface
    {
        $query = new Query($domain, $type, Message::CLASS_IN);

        return $this->executor->query(
            $query
        )->then(function (Message $response) use ($query) {
            return $this->extractValues($query, $response);
        });
    }

    /**
     * Extract all resource record values from response for this query
     *
     * @param Query   $query
     * @param Message $response
     * @return array<string>

     */
    private function extractValues(Query $query, Message $response): array
    {
        // reject if response code indicates this is an error response message
        $code = $response->rcode;
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
                'DNS query for ' . $query->describe() . ' returned an error response (' . $message . ')',
                $code
            );
        }

        $answers = $response->answers;
        $addresses = $this->valuesByNameAndType($answers, $query->name, $query->type);

        // reject if we did not receive a valid answer (domain is valid, but no record for this type could be found)
        if (0 === count($addresses)) {
            throw new RecordNotFoundException(
                'DNS query for ' . $query->describe() . ' did not return a valid answer (NOERROR / NODATA)'
            );
        }

        return array_values($addresses);
    }

    /**
     * @param array<Record> $answers
     * @param string        $name
     * @param int           $type
     * @return array<string|int|float|null>
     */
    private function valuesByNameAndType(array $answers, string $name, int $type): array
    {
        // return all record values for this name and type (if any)
        $named = $this->filterByName($answers, $name);
        $records = $this->filterByType($named, $type);
        if ($records) {
            return $this->mapRecordData($records);
        }

        // no matching records found? check if there are any matching CNAMEs instead
        $cnameRecords = $this->filterByType($named, Message::TYPE_CNAME);
        if ($cnameRecords) {
            $cnames = $this->mapRecordData($cnameRecords);
            foreach ($cnames as $cname) {
                $records = array_merge(
                    $records,
                    $this->valuesByNameAndType($answers, $cname, $type)
                );
            }
        }

        return $records;
    }

    /**
     * @param array<Record> $answers
     * @return array<Record>
     */
    private function filterByName(array $answers, string $name): array
    {
        return $this->filterByField($answers, 'name', $name);
    }

    /**
     * @param array<Record> $answers
     * @return array<Record>
     */
    private function filterByType(array $answers, int $type): array
    {
        return $this->filterByField($answers, 'type', $type);
    }

    /**
     * @param array<Record> $answers
     * @param string|int $value
     * @return array<Record>
     */
    private function filterByField(array $answers, string $field, $value): array
    {
        if (is_string($value)) {
            $value = strtolower($value);
        }
        return array_filter($answers, static function (Record $answer) use ($field, $value) {
            return $value === (is_string($value) ? strtolower($answer->$field) : $answer->$field);
        });
    }

    /**
     * @param array<Record> $records
     * @return array<string|int|float|null>
     */
    private function mapRecordData(array $records): array
    {
        $recordData = [];

        foreach ($records as $record) {
            if (is_array($record->data)) {
                foreach ($record->data as $recordDataItem) {
                    $recordData[] = $recordDataItem;
                }

                continue;
            }

            $recordData[] = $record->data;
        }

        return $recordData;
    }
}
