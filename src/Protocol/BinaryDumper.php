<?php

namespace React\Dns\Protocol;

use React\Dns\Model\HeaderBag;
use React\Dns\Model\Message;
use React\Dns\Model\Record;

class BinaryDumper
{
    /**
     * @param Message $message
     * @return string
     */
    public function toBinary(Message $message)
    {
        $data = '';

        $data .= $this->headerToBinary($message->header);
        $data .= $this->questionToBinary($message->questions);
        $data .= $this->recordsToBinary($message->answers);
        $data .= $this->recordsToBinary($message->authority);
        $data .= $this->recordsToBinary($message->additional);

        return $data;
    }

    /**
     * @param HeaderBag $header
     * @return string
     */
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

        return $data;
    }

    /**
     * @param array $questions
     * @return string
     */
    private function questionToBinary(array $questions)
    {
        $data = '';

        foreach ($questions as $question) {
            $data .= $this->domainNameToBinary($question['name']);
            $data .= pack('n*', $question['type'], $question['class']);
        }

        return $data;
    }

    /**
     * @param Record[] $records
     * @return string
     */
    private function recordsToBinary(array $records)
    {
        $data = '';

        foreach ($records as $record) {
            /* @var $record Record */
            switch ($record->type) {
                case Message::TYPE_A:
                case Message::TYPE_AAAA:
                    $binary = \inet_pton($record->data);
                    break;
                case Message::TYPE_CNAME:
                case Message::TYPE_NS:
                case Message::TYPE_PTR:
                    $binary = $this->domainNameToBinary($record->data);
                    break;
                case Message::TYPE_TXT:
                    $binary = $this->textsToBinary($record->data);
                    break;
                case Message::TYPE_MX:
                    $binary = \pack(
                        'n',
                        $record->data['priority']
                    );
                    $binary .= $this->domainNameToBinary($record->data['target']);
                    break;
                case Message::TYPE_SRV:
                    $binary = \pack(
                        'n*',
                        $record->data['priority'],
                        $record->data['weight'],
                        $record->data['port']
                    );
                    $binary .= $this->domainNameToBinary($record->data['target']);
                    break;
                case Message::TYPE_SOA:
                    $binary  = $this->domainNameToBinary($record->data['mname']);
                    $binary .= $this->domainNameToBinary($record->data['rname']);
                    $binary .= \pack(
                        'N*',
                        $record->data['serial'],
                        $record->data['refresh'],
                        $record->data['retry'],
                        $record->data['expire'],
                        $record->data['minimum']
                    );
                    break;
                default:
                    // RDATA is already stored as binary value for unknown record types
                    $binary = $record->data;
            }

            $data .= $this->domainNameToBinary($record->name);
            $data .= \pack('nnNn', $record->type, $record->class, $record->ttl, \strlen($binary));
            $data .= $binary;
        }

        return $data;
    }

    /**
     * @param string[] $texts
     * @return string
     */
    private function textsToBinary(array $texts)
    {
        $data = '';
        foreach ($texts as $text) {
            $data .= \chr(\strlen($text)) . $text;
        }
        return $data;
    }

    /**
     * @param string $host
     * @return string
     */
    private function domainNameToBinary($host)
    {
        if ($host === '') {
            return "\0";
        }

        return $this->textsToBinary(\explode('.', $host . '.'));
    }
}
