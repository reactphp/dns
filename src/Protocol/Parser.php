<?php

namespace React\Dns\Protocol;

use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\Query;

/**
 * DNS protocol parser
 *
 * Obsolete and uncommon types and classes are not implemented.
 */
class Parser
{
    public function parseChunk($data, Message $message)
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

        if ($message->header->get('anCount') != count($message->answers)) {
            if (!$this->parseRecord($message, 'answer')) {
                return;
            }
        }
        if ($message->header->get('nsCount') != count($message->authority)) {
            if (!$this->parseRecord($message, 'authority')) {
                return;
            }
        }

        if ($message->header->get('arCount') != count($message->additional)) {
            if (!$this->parseRecord($message, 'additional')) {
                return;
            }
        }

        return $message;
    }

    public function parseHeader(Message $message)
    {
        if (strlen($message->data) < 12) {
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
        if (strlen($message->data) < 2) {
            return;
        }

        $consumed = $message->consumed;

        list($labels, $consumed) = $this->readLabels($message->data, $consumed);

        if (null === $labels) {
            return;
        }

        if (strlen($message->data) - $consumed < 4) {
            return;
        }

        list($type, $class) = array_values(unpack('n*', substr($message->data, $consumed, 4)));
        $consumed += 4;

        $message->consumed = $consumed;

        $message->questions[] = new Query(
            implode('.', $labels),
            $type,
            $class,
            NULL);

        if ($message->header->get('qdCount') != count($message->questions)) {
            return $this->parseQuestion($message);
        }

        return $message;
    }

    public function parseRecord(Message $message, $parseType = 'answer')
    {
        if (strlen($message->data) < 2) {
            return;
        }

        $priority = $countItems = $messageHeaderKey = null;
        $consumed = $message->consumed;

        list($labels, $consumed) = $this->readLabels($message->data, $consumed);

        if (null === $labels) {
            return;
        }

        if (strlen($message->data) - $consumed < 10) {
            return;
        }

        list($type, $class) = array_values(unpack('n*', substr($message->data, $consumed, 4)));
        $consumed += 4;

        list($ttl) = array_values(unpack('N', substr($message->data, $consumed, 4)));
        $consumed += 4;

        list($rdLength) = array_values(unpack('n', substr($message->data, $consumed, 2)));
        $consumed += 2;

        $rdata = null;

        switch($type)
        {
            case Message::TYPE_A:
                $ip = substr($message->data, $consumed, $rdLength);
                $consumed += $rdLength;

                $rdata = inet_ntop($ip);
                break;

            case Message::TYPE_CNAME:
                list($bodyLabels, $consumed) = $this->readLabels($message->data, $consumed);

                $rdata = implode('.', $bodyLabels);
                break;

            case Message::TYPE_NS:
                list($bodyLabels, $consumed) = $this->readLabels($message->data, $consumed);

                $rdata = implode('.', $bodyLabels);
                break;

            case Message::TYPE_PTR:
                list($bodyLabels, $consumed) = $this->readLabels($message->data, $consumed);

                $rdata = implode('.', $bodyLabels);
                break;

            case Message::TYPE_TXT:
                $rdata = substr($message->data, $consumed + 1, $rdLength);
                $consumed += $rdLength;
                break;

            case Message::TYPE_MX:
                list($priority) = array_values(unpack('n', substr($message->data, $consumed, 2)));
                $consumed += 2;

                list($bodyLabels, $consumed) = $this->readLabels($message->data, $consumed);
                $rdata = implode('.', $bodyLabels);
                break;

            case Message::TYPE_SOA:
                // SOA RDATA FORMAT: mname rname serial(32bit) refresh(32bit) retry(32bit) expire(32bit) minimum(32bit)

                list($bodyLabels, $consumed) = $this->readLabels($message->data, $consumed);
                $mname = implode('.', $bodyLabels) . '.';

                list($bodyLabels, $consumed) = $this->readLabels($message->data, $consumed);
                $rname = implode('.', $bodyLabels);

                list($serial) = array_values(unpack('N', substr($message->data, $consumed, 4)));
                $consumed += 4;

                list($refresh) = array_values(unpack('N', substr($message->data, $consumed, 4)));
                $consumed += 4;

                list($retry) = array_values(unpack('N', substr($message->data, $consumed, 4)));
                $consumed += 4;

                list($expire) = array_values(unpack('N', substr($message->data, $consumed, 4)));
                $consumed += 4;

                list($minimum) = array_values(unpack('N', substr($message->data, $consumed, 4)));
                $consumed += 4;

                $rdata = "$mname $rname $serial $refresh $retry $expire $minimum";
                break;

                
            default:
                $consumed += $rdLength;
        }

        $message->consumed = $consumed;

        $name = implode('.', $labels);
        $ttl = $this->signedLongToUnsignedLong($ttl);
        $record = new Record($name, $type, $class, $ttl, $rdata, $priority);

        if ($parseType == 'answer')
        {
            $message->answers[] = $record;
            $countItems = count($message->answers);
            $messageHeaderKey = 'anCount';
        }
        else if ($parseType == 'authority')
        {
            $message->authority[] = $record;
            $countItems = count($message->authority);
            $messageHeaderKey = 'nsCount';
        }
        else if ($parseType == 'additional')
        {
            $message->additional[] = $record;
            $countItems = count($message->additional);
            $messageHeaderKey = 'arCount';
        }

        if ($message->header->get($messageHeaderKey) != $countItems) {
            return $this->parseRecord($message, $parseType);
        }

        return $message;
    }

    /**
     * backward compatible
     * @deprecated
     */
    public function parseAnswer(Message $message)
    {
        return $this->parseRecord($message, 'answer');
    }

    private function readLabels($data, $consumed)
    {
        $labels = array();

        while (true) {
            if ($this->isEndOfLabels($data, $consumed)) {
                $consumed += 1;
                break;
            }

            if ($this->isCompressedLabel($data, $consumed)) {
                list($newLabels, $consumed) = $this->getCompressedLabel($data, $consumed);
                $labels = array_merge($labels, $newLabels);
                break;
            }

            $length = ord(substr($data, $consumed, 1));
            $consumed += 1;

            if (strlen($data) - $consumed < $length) {
                return array(null, null);
            }

            $labels[] = substr($data, $consumed, $length);
            $consumed += $length;
        }

        return array($labels, $consumed);
    }

    public function isEndOfLabels($data, $consumed)
    {
        $length = ord(substr($data, $consumed, 1));
        return 0 === $length;
    }

    public function getCompressedLabel($data, $consumed)
    {
        list($nameOffset, $consumed) = $this->getCompressedLabelOffset($data, $consumed);
        list($labels) = $this->readLabels($data, $nameOffset);

        return array($labels, $consumed);
    }

    public function isCompressedLabel($data, $consumed)
    {
        $mask = 0xc000; // 1100000000000000
        list($peek) = array_values(unpack('n', substr($data, $consumed, 2)));

        return (bool) ($peek & $mask);
    }

    public function getCompressedLabelOffset($data, $consumed)
    {
        $mask = 0x3fff; // 0011111111111111
        list($peek) = array_values(unpack('n', substr($data, $consumed, 2)));

        return array($peek & $mask, $consumed + 2);
    }

    public function signedLongToUnsignedLong($i)
    {
        return $i & 0x80000000 ? $i - 0xffffffff : $i;
    }
}
