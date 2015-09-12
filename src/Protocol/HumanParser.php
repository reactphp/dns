<?php

namespace React\Dns\Protocol;

use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\Query;

class HumanParser
{
    private static $arrTYPE = array('A' => 1,
                                    'NS' => 2,
                                    'CNAME' => 5,
                                    'SOA' => 6,
                                    'PTR' => 12,
                                    'MX' => 15,
                                    'TXT' => 16,
                                    'AAAA' => 28,
                                    'ANY' => 255);

    private static $arrTYPEHuman = array(1 => 'A',
                                         2 => 'NS',
                                         5 => 'CNAME',
                                         6 => 'SOA',
                                         12 => 'PTR',
                                         15 => 'MX',
                                         16 => 'TXT',
                                         28 => 'AAAA',
                                         255 => 'ANY');

    private static $arrCLASS = array('IN' => 1);

    private static $arrCLASSHuman = array(1 => 'IN');

    private static $arrOPCODE = array('QUERY' => 0,
                                      'IQUERY' => 1,
                                      'STATUS' => 2);

    private static $arrOPCODEHuman = array(0 => 'QUERY',
                                           1 => 'IQUERY',
                                           2 => 'STATUS');

    private static $arrRCODE = array('OK' => 0,
                                     'FORMAT_ERROR' => 1,
                                     'SERVER_FAILURE' => 2,
                                     'NAME_ERROR' => 3,
                                     'NOT_IMPLEMENTED' => 4,
                                     'REFUSED' => 5);

    private static $arrRCODEHuman = array(0 => 'OK',
                                          1 => 'FORMAT_ERROR',
                                          2 => 'SERVER_FAILURE',
                                          3 => 'NAME_ERROR',
                                          4 => 'NOT_IMPLEMENTED',
                                          5 => 'REFUSED');

    /**
     * @param      $kind e.g. TYPE, OPCODE, RCODE, CLASS
     * @param      $value e.g. A, CNAME, OK, IN, 1, 255
     *
     * @return int|string
     */
    private static function getIntValue($kind, $value)
    {
        $property = 'arr'. $kind;

        if (!is_numeric($value)) {
            $value = strtoupper($value);
        }

        if (($arr = static::$$property) && isset($arr[$value])) {
            $value = $arr[$value];
        }

        return $value;
    }

    /**
     * @param      $kind e.g. TYPE, OPCODE, RCODE, CLASS
     * @param      $value e.g. 1, 255, A, CNAME
     *
     * @return int|string
     */
    private static function getHumanValue($kind, $value)
    {
        $property = 'arr'. $kind . 'Human';

        if (!is_numeric($value)) {
            $value = strtoupper($value);
        }

        if (($arr = static::$$property) && isset($arr[$value])) {
            $value = $arr[$value];
        }

        return $value;
    }

    /**
     * Converts human type (e.g. MX) to TYPE int value
     */
    public static function human2Type($v)
    {
        return self::getIntValue('TYPE', $v);
    }

    /**
     * Converts type from int to Human
     */
    public static function type2Human($v)
    {
        return self::getHumanValue('TYPE', $v);
    }

    /**
     * Converts human opcode (e.g. QUERY) to OPCODE int vlaue
     */
    public static function human2Opcode($v)
    {
        return self::getIntValue('OPCODE', $v);
    }

    /**
     * Converts opcode from int to Human
     */
    public static function opcode2Human($v)
    {
        return self::getHumanValue('OPCODE', $v);
    }

    /**
     * Converts human rcode (e.g. OK) to RCODE int vlaue
     */
    public static function human2Rcode($v)
    {
        return self::getIntValue('RCODE', $v);
    }

    /**
     * Converts rcode from int to Human
     */
    public static function rcode2Human($v)
    {
        return self::getHumanValue('RCODE', $v);
    }

    /**
     * Converts human rcode (e.g. IN) to CLASS int vlaue
     */
    public static function human2Class($v)
    {
        return self::getIntValue('CLASS', $v);
    }

    /**
     * Converts CLASS from int to Human
     */
    public static function class2Human($v)
    {
        return self::getHumanValue('CLASS', $v);
    }

    /**
     * Output message in human readable format (similar to dig's output)
     */
    public static function explainMessage(Message $message)
    {
        $output = 'HEADER:' . "\n" .
                  "\t" . 'opcode: %s, status: %s, id: %s'. "\n";

        $output .= "\t" .'flags: ';
        if ($message->header->attributes['qr'] === 1) {
            $output .= 'qr';
        }
        if ($message->header->attributes['rd'] === 1) {
            $output .= ' rd';
        }
        if ($message->header->attributes['ra'] === 1) {
            $output .= ' ra';
        }
        if ($message->header->attributes['aa'] === 1) {
            $output .= ' aa';
        }
        $output .= '; QUERY: %d, ANSWER: %d, AUTHORITY: %d, ADDITIONAL: %d' . "\n\n";

        $output .= 'QUESTION SECTION: ' . "\n" . '%s' ."\n";
        $questionsOutput = '';
        foreach ($message->questions as $question) {
            $questionsOutput .= "\t" . self::explainQuery($question);
        }

        $output .= 'ANSWER SECTION: ' . "\n" . '%s' ."\n";
        $answersOutput = '';
        foreach ($message->answers as $record) {
            $answersOutput .= "\t" . self::explainRecord($record);
        }

        if ($message->header->attributes['nsCount']) {
            $authorityOutput = '';
            foreach ($message->authority as $record) {
                $authorityOutput .= "\t" . self::explainRecord($record);
            }
            $output .= 'AUTHORITY SECTION: ' . "\n" . $authorityOutput ."\n";
        }

        if ($message->header->attributes['arCount']) {
            $additionalOutput = '';
            foreach ($message->additional as $record) {
                $additionalOutput .= "\t" . self::explainRecord($record);
            }
            $output .= 'ADDITIONAL SECTION: ' . "\n" . $additionalOutput ."\n";
        }

        $output .= "\n" .
                   'Query time: %s ms' . "\n" .
                   'Name Server: %s' . "\n" .
                   'Transport: %s'  . "\n" .
                   'Message Size: %d' . "\n";

        return sprintf($output,
                        self::opcode2Human($message->header->get('opcode')),
                        self::rcode2Human($message->header->get('rcode')),
                        self::opcode2Human($message->header->get('id')),
                        $message->header->attributes['qdCount'],
                        $message->header->attributes['anCount'],
                        $message->header->attributes['nsCount'],
                        $message->header->attributes['arCount'],
                        $questionsOutput,
                        $answersOutput,
                        $message->meta->execTime,
                        $message->nameserver,
                        $message->transport,
                        (($message->transport == 'tcp' ? 2 : 0) + strlen($message->data)));

    }

    public static function explainQuery(Query $query)
    {
        return sprintf('%-25s %-10s %-10s %s' . "\n", $query->name . '.', '', self::class2Human($query->class), self::type2Human($query->type));
    }

    public static function explainRecord(Record $record)
    {
        $data = $record->data;
        if ($record->type == Message::TYPE_TXT) {
            $data = '"'. $data . '"';
        }

        return sprintf('%-25s %-10s %-10s %-8s %-2s %s' . "\n",
                       $record->name . '.',
                       $record->ttl,
                       self::class2Human($record->class),
                       self::type2Human($record->type),
                       $record->priority,
                       $data);
    }

    /**
     * Debugs header binary octets 3-4
     * i.e. the  part which has QR|   Opcode  |AA|TC|RD|RA|   Z    |   RCODE
     *
     * @param string $fields the unpack value (hex value)
     * @return string
     */
    public static function explainHeaderFlagsBinary($fields)
    {
        // string representation of binary value
        $bin = sprintf('%016b', $fields);
        $output = sprintf("Flags Value: %x\n".
                          "16 Bit Binary: %s\n\n", $fields, $bin);


        $mask = "%10s: %-20s %-50s\n";
        $output .= sprintf($mask, 'Flag', 'Binary Value', '[Explanation]');
        $output .= sprintf($mask, 'QR', substr($bin, 0, 1), '[0 = Query, 1 = Response]');
        $output .= sprintf($mask, 'Opcode', substr($bin, 1, 4), '[Decimal value 0 = standard query]');
        $output .= sprintf($mask, 'AA', substr($bin, 5, 1), '[1 = Authoritative Answer]');
        $output .= sprintf($mask, 'TC', substr($bin, 6, 1), '[1 = Message truncated]');
        $output .= sprintf($mask, 'RD', substr($bin, 7, 1), '[1 = Recursion Desired]');
        $output .= sprintf($mask, 'RA', substr($bin, 8, 1), '[1 = Recursion Available]');
        $output .= sprintf($mask, 'Z', substr($bin, 9, 3), '[Future use]');
        $output .= sprintf($mask, 'RCODE', substr($bin, 12, 4), '[Human value = '. self::rcode2Human(bindec(substr($bin, 12, 4))) .']');

        return $output;
    }

    /**
     * Dump hex
     * @author mindplay.dk
     * @url http://stackoverflow.com/a/4225813/394870
     */
    public static function dumpHex($data, $newline="\n")
    {
        static $from = '';
        static $to = '';

        static $width = 16; # number of bytes per line

        static $pad = '.'; # padding for non-visible characters

        if ($from==='') {
            for ($i=0; $i <= 0xff; $i++) {
                $from .= chr($i);
                $to .= ($i >= 0x20 && $i <= 0x7e) ? chr($i) : $pad;
            }
        }

        $hex = str_split(bin2hex($data), $width*2);
        $chars = str_split(strtr($data, $from, $to), $width);

        $offset = 0;
        foreach ($hex as $i => $line) {
            echo sprintf('%6X',$offset).' : '.implode(' ', str_split($line,2)) . ' [' . $chars[$i] . ']' . $newline;
            $offset += $width;
        }
    }

    public static function convertBinaryToHexDump($input)
    {
        return self::formatHexDump(implode('', unpack('H*', $input)));
    }

    public static function formatHexDump($input)
    {
        return implode(' ', str_split($input, 2));
    }
}