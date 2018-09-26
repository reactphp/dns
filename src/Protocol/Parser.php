<?php

namespace React\Dns\Protocol;

use React\Dns\Model\Message;
use React\Dns\Model\Record;
use InvalidArgumentException;

/**
 * DNS protocol parser
 *
 * Obsolete and uncommon types and classes are not implemented.
 */
class Parser
{
    /**
     * Parses the given raw binary message into a Message object
     *
     * @param string $data
     * @throws InvalidArgumentException
     * @return Message
     */
    public function parseMessage($data)
    {
        $message = new Message();
        if ($this->parse($data, $message) !== $message) {
            throw new InvalidArgumentException('Unable to parse binary message');
        }

        return $message;
    }

    /**
     * @deprecated unused, exists for BC only
     * @codeCoverageIgnore
     */
    public function parseChunk($data, Message $message)
    {
        return $this->parse($data, $message);
    }

    private function parse($data, Message $message)
    {
        $message->data .= $data;

        if (!$message->header->get('id')) {
            if (!$this->parseHeader($message)) {
                return;
            }
        }

        if ($message->header->get('qdCount') != count($message->questions)) {
            if (!$this->parseQuestion($message)) {
                return;
            }
        }

        // parse all answer records
        for ($i = $message->header->get('anCount'); $i > 0; --$i) {
            $record = $this->parseRecord($message);
            if ($record === null) {
                return;
            } else {
                $message->answers[] = $record;
            }
        }

        // parse all authority records
        for ($i = $message->header->get('nsCount'); $i > 0; --$i) {
            $record = $this->parseRecord($message);
            if ($record === null) {
                return;
            } else {
                $message->authority[] = $record;
            }
        }

        // parse all additional records
        for ($i = $message->header->get('arCount'); $i > 0; --$i) {
            $record = $this->parseRecord($message);
            if ($record === null) {
                return;
            } else {
                $message->additional[] = $record;
            }
        }

        return $message;
    }

    public function parseHeader(Message $message)
    {
        if (!isset($message->data[12 - 1])) {
            return;
        }

        $header = substr($message->data, 0, 12);
        $message->consumed += 12;

        list($id, $fields, $qdCount, $anCount, $nsCount, $arCount) = array_values(unpack('n*', $header));

        $rcode = $fields & bindec('1111');
        $z = ($fields >> 4) & bindec('111');
        $ra = ($fields >> 7) & 1;
        $rd = ($fields >> 8) & 1;
        $tc = ($fields >> 9) & 1;
        $aa = ($fields >> 10) & 1;
        $opcode = ($fields >> 11) & bindec('1111');
        $qr = ($fields >> 15) & 1;

        $vars = compact('id', 'qdCount', 'anCount', 'nsCount', 'arCount',
                            'qr', 'opcode', 'aa', 'tc', 'rd', 'ra', 'z', 'rcode');

        foreach ($vars as $name => $value) {
            $message->header->set($name, $value);
        }

        return $message;
    }

    public function parseQuestion(Message $message)
    {
        $consumed = $message->consumed;

        list($labels, $consumed) = $this->readLabels($message->data, $consumed);

        if ($labels === null || !isset($message->data[$consumed + 4 - 1])) {
            return;
        }

        list($type, $class) = array_values(unpack('n*', substr($message->data, $consumed, 4)));
        $consumed += 4;

        $message->consumed = $consumed;

        $message->questions[] = array(
            'name' => implode('.', $labels),
            'type' => $type,
            'class' => $class,
        );

        if ($message->header->get('qdCount') != count($message->questions)) {
            return $this->parseQuestion($message);
        }

        return $message;
    }

    /**
     * recursively parse all answers from the message data into message answer records
     *
     * @param Message $message
     * @return ?Message returns the updated message on success or null if the data is invalid/incomplete
     * @deprecated unused, exists for BC only
     * @codeCoverageIgnore
     */
    public function parseAnswer(Message $message)
    {
        $record = $this->parseRecord($message);
        if ($record === null) {
            return null;
        }

        $message->answers[] = $record;

        if ($message->header->get('anCount') != count($message->answers)) {
            return $this->parseAnswer($message);
        }

        return $message;
    }

    /**
     * @param Message $message
     * @return ?Record returns parsed Record on success or null if data is invalid/incomplete
     */
    private function parseRecord(Message $message)
    {
        $consumed = $message->consumed;

        list($name, $consumed) = $this->readDomain($message->data, $consumed);

        if ($name === null || !isset($message->data[$consumed + 10 - 1])) {
            return null;
        }

        list($type, $class) = array_values(unpack('n*', substr($message->data, $consumed, 4)));
        $consumed += 4;

        list($ttl) = array_values(unpack('N', substr($message->data, $consumed, 4)));
        $consumed += 4;

        // TTL is a UINT32 that must not have most significant bit set for BC reasons
        if ($ttl < 0 || $ttl >= 1 << 31) {
            $ttl = 0;
        }

        list($rdLength) = array_values(unpack('n', substr($message->data, $consumed, 2)));
        $consumed += 2;

        if (!isset($message->data[$consumed + $rdLength - 1])) {
            return null;
        }

        $rdata = null;
        $expected = $consumed + $rdLength;

        if (Message::TYPE_A === $type) {
            if ($rdLength === 4) {
                $rdata = inet_ntop(substr($message->data, $consumed, $rdLength));
                $consumed += $rdLength;
            }
        } elseif (Message::TYPE_AAAA === $type) {
            if ($rdLength === 16) {
                $rdata = inet_ntop(substr($message->data, $consumed, $rdLength));
                $consumed += $rdLength;
            }
        } elseif (Message::TYPE_CNAME === $type || Message::TYPE_PTR === $type || Message::TYPE_NS === $type) {
            list($rdata, $consumed) = $this->readDomain($message->data, $consumed);
        } elseif (Message::TYPE_TXT === $type) {
            $rdata = array();
            while ($consumed < $expected) {
                $len = ord($message->data[$consumed]);
                $rdata[] = (string)substr($message->data, $consumed + 1, $len);
                $consumed += $len + 1;
            }
        } elseif (Message::TYPE_MX === $type) {
            if ($rdLength > 2) {
                list($priority) = array_values(unpack('n', substr($message->data, $consumed, 2)));
                list($target, $consumed) = $this->readDomain($message->data, $consumed + 2);

                $rdata = array(
                    'priority' => $priority,
                    'target' => $target
                );
            }
        } elseif (Message::TYPE_SRV === $type) {
            if ($rdLength > 6) {
                list($priority, $weight, $port) = array_values(unpack('n*', substr($message->data, $consumed, 6)));
                list($target, $consumed) = $this->readDomain($message->data, $consumed + 6);

                $rdata = array(
                    'priority' => $priority,
                    'weight' => $weight,
                    'port' => $port,
                    'target' => $target
                );
            }
        } elseif (Message::TYPE_SOA === $type) {
            list($mname, $consumed) = $this->readDomain($message->data, $consumed);
            list($rname, $consumed) = $this->readDomain($message->data, $consumed);

            if ($mname !== null && $rname !== null && isset($message->data[$consumed + 20 - 1])) {
                list($serial, $refresh, $retry, $expire, $minimum) = array_values(unpack('N*', substr($message->data, $consumed, 20)));
                $consumed += 20;

                $rdata = array(
                    'mname' => $mname,
                    'rname' => $rname,
                    'serial' => $serial,
                    'refresh' => $refresh,
                    'retry' => $retry,
                    'expire' => $expire,
                    'minimum' => $minimum
                );
            }
        } else {
            // unknown types simply parse rdata as an opaque binary string
            $rdata = substr($message->data, $consumed, $rdLength);
            $consumed += $rdLength;
        }

        // ensure parsing record data consumes expact number of bytes indicated in record length
        if ($consumed !== $expected || $rdata === null) {
            return null;
        }

        $message->consumed = $consumed;

        return new Record($name, $type, $class, $ttl, $rdata);
    }

    private function readDomain($data, $consumed)
    {
        list ($labels, $consumed) = $this->readLabels($data, $consumed);

        if ($labels === null) {
            return array(null, null);
        }

        return array(implode('.', $labels), $consumed);
    }

    private function readLabels($data, $consumed)
    {
        $labels = array();

        while (true) {
            if (!isset($data[$consumed])) {
                return array(null, null);
            }

            $length = \ord($data[$consumed]);

            // end of labels reached
            if ($length === 0) {
                $consumed += 1;
                break;
            }

            // first two bits set? this is a compressed label (14 bit pointer offset)
            if (($length & 0xc0) === 0xc0 && isset($data[$consumed + 1])) {
                $offset = ($length & ~0xc0) << 8 | \ord($data[$consumed + 1]);
                if ($offset >= $consumed) {
                    return array(null, null);
                }

                $consumed += 2;
                list($newLabels) = $this->readLabels($data, $offset);

                if ($newLabels === null) {
                    return array(null, null);
                }

                $labels = array_merge($labels, $newLabels);
                break;
            }

            // length MUST be 0-63 (6 bits only) and data has to be large enough
            if ($length & 0xc0 || !isset($data[$consumed + $length - 1])) {
                return array(null, null);
            }

            $labels[] = substr($data, $consumed + 1, $length);
            $consumed += $length + 1;
        }

        return array($labels, $consumed);
    }

    /**
     * @deprecated unused, exists for BC only
     * @codeCoverageIgnore
     */
    public function isEndOfLabels($data, $consumed)
    {
        $length = ord(substr($data, $consumed, 1));
        return 0 === $length;
    }

    /**
     * @deprecated unused, exists for BC only
     * @codeCoverageIgnore
     */
    public function getCompressedLabel($data, $consumed)
    {
        list($nameOffset, $consumed) = $this->getCompressedLabelOffset($data, $consumed);
        list($labels) = $this->readLabels($data, $nameOffset);

        return array($labels, $consumed);
    }

    /**
     * @deprecated unused, exists for BC only
     * @codeCoverageIgnore
     */
    public function isCompressedLabel($data, $consumed)
    {
        $mask = 0xc000; // 1100000000000000
        list($peek) = array_values(unpack('n', substr($data, $consumed, 2)));

        return (bool) ($peek & $mask);
    }

    /**
     * @deprecated unused, exists for BC only
     * @codeCoverageIgnore
     */
    public function getCompressedLabelOffset($data, $consumed)
    {
        $mask = 0x3fff; // 0011111111111111
        list($peek) = array_values(unpack('n', substr($data, $consumed, 2)));

        return array($peek & $mask, $consumed + 2);
    }

    /**
     * @deprecated unused, exists for BC only
     * @codeCoverageIgnore
     */
    public function signedLongToUnsignedLong($i)
    {
        return $i & 0x80000000 ? $i - 0xffffffff : $i;
    }
}
