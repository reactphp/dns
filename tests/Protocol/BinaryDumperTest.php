<?php

namespace React\Tests\Dns\Protocol;

use PHPUnit\Framework\TestCase;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Model\Message;
use React\Dns\Model\Record;

class BinaryDumperTest extends TestCase
{
    public function testToBinaryRequestMessage()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 01 00 01";                         // question: type A, class IN

        $expected = $this->formatHexDump($data);

        $request = new Message();
        $request->header->set('id', 0x7262);
        $request->header->set('rd', 1);

        $request->questions[] = array(
            'name'  => 'igor.io',
            'type'  => Message::TYPE_A,
            'class' => Message::CLASS_IN,
        );

        $request->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($request);
        $data = $this->convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testToBinaryRequestMessageWithCustomOptForEdns0()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 01"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 01 00 01";                         // question: type A, class IN
        $data .= "00";                                  // additional: (empty hostname)
        $data .= "00 29 03 e8 00 00 00 00 00 00 ";      // additional: type OPT, class UDP size, TTL 0, no RDATA

        $expected = $this->formatHexDump($data);

        $request = new Message();
        $request->header->set('id', 0x7262);
        $request->header->set('rd', 1);

        $request->questions[] = array(
            'name'  => 'igor.io',
            'type'  => Message::TYPE_A,
            'class' => Message::CLASS_IN,
        );

        $request->additional[] = new Record('', 41, 1000, 0, '');

        $request->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($request);
        $data = $this->convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testToBinaryResponseMessageWithoutRecords()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 01 00 01";                         // question: type A, class IN

        $expected = $this->formatHexDump($data);

        $response = new Message();
        $response->header->set('id', 0x7262);
        $response->header->set('rd', 1);
        $response->header->set('rcode', Message::RCODE_OK);

        $response->questions[] = array(
            'name' => 'igor.io',
            'type' => Message::TYPE_A,
            'class' => Message::CLASS_IN
        );

        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = $this->convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testToBinaryForResponseWithSRVRecord()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 01 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 21 00 01";                         // question: type SRV, class IN
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 21 00 01";                         // answer: type SRV, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 0c";                               // answer: rdlength 12
        $data .= "00 0a 00 14 1f 90 04 74 65 73 74 00"; // answer: rdata priority 10, weight 20, port 8080 test

        $expected = $this->formatHexDump($data);

        $response = new Message();
        $response->header->set('id', 0x7262);
        $response->header->set('rd', 1);
        $response->header->set('rcode', Message::RCODE_OK);

        $response->questions[] = array(
            'name' => 'igor.io',
            'type' => Message::TYPE_SRV,
            'class' => Message::CLASS_IN
        );

        $response->answers[] = new Record('igor.io', Message::TYPE_SRV, Message::CLASS_IN, 86400, array(
            'priority' => 10,
            'weight' => 20,
            'port' => 8080,
            'target' => 'test'
        ));
        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = $this->convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testToBinaryForResponseWithSOARecord()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 01 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 06 00 01";                         // question: type SOA, class IN
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 06 00 01";                         // answer: type SOA, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 27";                               // answer: rdlength 39
        $data .= "02 6e 73 05 68 65 6c 6c 6f 00";       // answer: rdata ns.hello (mname)
        $data .= "01 65 05 68 65 6c 6c 6f 00";          // answer: rdata e.hello (rname)
        $data .= "78 49 28 d5 00 00 2a 30 00 00 0e 10"; // answer: rdata 2018060501, 10800, 3600
        $data .= "00 09 3e 68 00 00 0e 10";             // answer: 605800, 3600

        $expected = $this->formatHexDump($data);

        $response = new Message();
        $response->header->set('id', 0x7262);
        $response->header->set('rd', 1);
        $response->header->set('rcode', Message::RCODE_OK);

        $response->questions[] = array(
            'name' => 'igor.io',
            'type' => Message::TYPE_SOA,
            'class' => Message::CLASS_IN
        );

        $response->answers[] = new Record('igor.io', Message::TYPE_SOA, Message::CLASS_IN, 86400, array(
            'mname' => 'ns.hello',
            'rname' => 'e.hello',
            'serial' => 2018060501,
            'refresh' => 10800,
            'retry' => 3600,
            'expire' => 605800,
            'minimum' => 3600
        ));
        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = $this->convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testToBinaryForResponseWithMultipleAnswerRecords()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 04 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 ff 00 01";                         // question: type ANY, class IN
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 01 00 01 00 00 00 00 00 04";       // answer: type A, class IN, TTL 0, 4 bytes
        $data .= "7f 00 00 01";                         // answer: 127.0.0.1
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 1c 00 01 00 00 00 00 00 10";       // question: type AAAA, class IN, TTL 0, 16 bytes
        $data .= "00 00 00 00 00 00 00 00 00 00 00 00 00 00 00 01"; // answer: ::1
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 10 00 01 00 00 00 00 00 0c";       // answer: type TXT, class IN, TTL 0, 12 bytes
        $data .= "05 68 65 6c 6c 6f 05 77 6f 72 6c 64"; // answer: hello, world
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 0f 00 01 00 00 00 00 00 03";       // anwser: type MX, class IN, TTL 0, 3 bytes
        $data .= "00 00 00";                            // priority 0, no target

        $expected = $this->formatHexDump($data);

        $response = new Message();
        $response->header->set('id', 0x7262);
        $response->header->set('rd', 1);
        $response->header->set('rcode', Message::RCODE_OK);

        $response->questions[] = array(
            'name' => 'igor.io',
            'type' => Message::TYPE_ANY,
            'class' => Message::CLASS_IN
        );

        $response->answers[] = new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 0, '127.0.0.1');
        $response->answers[] = new Record('igor.io', Message::TYPE_AAAA, Message::CLASS_IN, 0, '::1');
        $response->answers[] = new Record('igor.io', Message::TYPE_TXT, Message::CLASS_IN, 0, array('hello', 'world'));
        $response->answers[] = new Record('igor.io', Message::TYPE_MX, Message::CLASS_IN, 0, array('priority' => 0, 'target' => ''));
        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = $this->convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testToBinaryForResponseWithAnswerAndAdditionalRecord()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 01 00 00 00 01"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 02 00 01";                         // question: type NS, class IN
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 02 00 01 00 00 00 00 00 0d";       // answer: type NS, class IN, TTL 0, 10 bytes
        $data .= "07 65 78 61 6d 70 6c 65 03 63 6f 6d 00"; // answer: example.com
        $data .= "07 65 78 61 6d 70 6c 65 03 63 6f 6d 00"; // additional: example.com
        $data .= "00 01 00 01 00 00 00 00 00 04";       // additional: type A, class IN, TTL 0, 4 bytes
        $data .= "7f 00 00 01";                         // additional: 127.0.0.1

        $expected = $this->formatHexDump($data);

        $response = new Message();
        $response->header->set('id', 0x7262);
        $response->header->set('rd', 1);
        $response->header->set('rcode', Message::RCODE_OK);

        $response->questions[] = array(
            'name' => 'igor.io',
            'type' => Message::TYPE_NS,
            'class' => Message::CLASS_IN
        );

        $response->answers[] = new Record('igor.io', Message::TYPE_NS, Message::CLASS_IN, 0, 'example.com');
        $response->additional[] = new Record('example.com', Message::TYPE_A, Message::CLASS_IN, 0, '127.0.0.1');
        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = $this->convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    private function convertBinaryToHexDump($input)
    {
        return $this->formatHexDump(implode('', unpack('H*', $input)));
    }

    private function formatHexDump($input)
    {
        return implode(' ', str_split(str_replace(' ', '', $input), 2));
    }
}
