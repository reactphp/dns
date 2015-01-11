<?php

namespace React\Dns\Protocol;

use React\Dns\Model\Message;
use React\Dns\Model\HeaderBag;

class BinaryDumper
{
    private $labelRegistry;
    private $consumed;

    public function toBinary(Message $message)
    {
        $this->labelRegistry = array();
        $data = '';

        $data .= $this->headerToBinary($message->header);
        $data .= $this->questionToBinary($message->questions);
        $data .= $this->recordToBinary($message->answers);
        $data .= $this->recordToBinary($message->authority);
        $data .= $this->recordToBinary($message->additional);

        // when TCP then first two octets are the length of data
        if ($message->transport == 'tcp') {
            $data = pack('n', strlen($data)).$data;
        }

        return $data;
    }

    private function headerToBinary(HeaderBag $header)
    {
        $data = '';

        $data .= pack('n', $header->get('id'));

        $flags = 0x00;
        $flags = ($flags << 1) | $header->get('qr');
        $flags = ($flags << 4) | $header->get('opcode');
        $flags = ($flags << 1) | $header->get('aa');
        $flags = ($flags << 1) | $header->get('tc');
        $flags = ($flags << 1) | $header->get('rd');
        $flags = ($flags << 1) | $header->get('ra');
        $flags = ($flags << 3) | $header->get('z');
        $flags = ($flags << 4) | $header->get('rcode');

        $data .= pack('n', $flags);

        $data .= pack('n', $header->get('qdCount'));
        $data .= pack('n', $header->get('anCount'));
        $data .= pack('n', $header->get('nsCount'));
        $data .= pack('n', $header->get('arCount'));

        $this->consumed = 12;

        return $data;
    }

    private function questionToBinary(array $questions)
    {
        $data = '';

        foreach ($questions as $question) {
            $data .= $this->encodeDomainName($question->name, true);
            $data .= pack('n*', $question->type, $question->class);
            $this->consumed += (2 * 2);
        }

        return $data;
    }

    private function recordToBinary(array $records)
    {
        $data = '';

        foreach ($records as $record) {
            $data .= $this->encodeDomainName($record->name, true);
            $data .= pack('n*', $record->type, $record->class);
            $this->consumed += (2 * 2);
            $data .= pack('N', $record->ttl);
            $this->consumed += 4;

            $this->consumed += 2; // this is is for pack('n', strlen($rdata)); and need to be done here

            $rdata = '';
            switch ($record->type) {
                case Message::TYPE_A:
                    $rdata .= inet_pton($record->data);
                    $this->consumed += 4;
                    break;

                case Message::TYPE_AAAA:
                    $rdata .= inet_pton($record->data);
                    $this->consumed += 16;
                    break;

                case Message::TYPE_CNAME:
                    $rdata .= $this->encodeDomainName($record->data, true);
                    break;

                case Message::TYPE_NS:
                    $rdata .= $this->encodeDomainName($record->data, true);
                    break;

                case Message::TYPE_PTR:
                    $rdata .= $this->encodeDomainName($record->data, true);
                    break;

                case Message::TYPE_TXT:
                    $rdLength = strlen($record->data);
                    $rdata  .= substr(pack('n', $rdLength), 1, 1);
                    $rdata .= $record->data;
                    $this->consumed += strlen($rdata);
                    break;

                case Message::TYPE_MX:
                    $rdata .= pack('n', $record->priority);
                    $this->consumed += 2;
                    $rdata .= $this->encodeDomainName($record->data, true);
                    break;

                case Message::TYPE_SOA:
                    // SOA RDATA FORMAT: mname rname serial(32bit) refresh(32bit) retry(32bit) expire(32bit) minimum(32bit)
                    $parts = explode(' ', $record->data);
                    $rdata .= $this->encodeDomainName($parts[0], true);
                    $rdata .= $this->encodeDomainName($parts[1], true);
                    $rdata .= pack('N*', $parts[2], $parts[3], $parts[4], $parts[5], $parts[6]);
                    $this->consumed += (4 * 5);
                    break;

                default:
                    $rdata = 'unknown';
                    break;
            }


            $data .= pack('n', strlen($rdata));
            $data .= $rdata;
        }

        return $data;
    }

    /**
     * @author RobinvdVleuten
     * @url  https://github.com/reactphp/react/pull/272
     */
    private function encodeDomainName($domainName, $compress = false)
    {
        // There can be empty names as well e.g.
        // ;; QUESTION SECTION:
        // ;8.8.8.8.                       IN      SOA
        // 
        // ;; AUTHORITY SECTION:
        // .                       0       IN      SOA     a.root-servers.net. nstld.verisign-grs.com. 2014101100 1800 900 604800 86400
        if ($domainName === '') {
            $this->consumed += 1;
            return "\x00";
        }

        $data = '';
        $labels = explode('.', $domainName);

        if ($compress) {
            while (count($labels)) {
                $part = implode('.', $labels);

                if (!isset($this->labelRegistry[$part])) {
                    $this->labelRegistry[$part] = $this->consumed;

                    $label = array_shift($labels);
                    $length = strlen($label);

                    $data .= chr($length) . $label;
                    $this->consumed += $length + 1;
                } else {
                    $x = pack('n', 0b1100000000000000 | $this->labelRegistry[$part]);
                    $data .= $x;
                    $this->consumed += 2;
                    break;
                }
            }

            if (!$labels) {
                $data .= "\x00";
                $this->consumed += 1;
            }
        } else {
            foreach ($labels as $label) {
                $data .= chr(strlen($label)).$label;
                $this->consumed += 1 + strlen($label);
            }

            $data .= "\x00";
            $this->consumed += 1;
        }

        return $data;
    }
}
