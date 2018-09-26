<?php

namespace React\Tests\Dns\Protocol;

use PHPUnit\Framework\TestCase;
use React\Dns\Protocol\Parser;
use React\Dns\Model\Message;

class ParserTest extends TestCase
{
    public function setUp()
    {
        $this->parser = new Parser();
    }

    /**
     * @dataProvider provideConvertTcpDumpToBinary
     */
    public function testConvertTcpDumpToBinary($expected, $data)
    {
        $this->assertSame($expected, $this->convertTcpDumpToBinary($data));
    }

    public function provideConvertTcpDumpToBinary()
    {
        return array(
            array(chr(0x72).chr(0x62), "72 62"),
            array(chr(0x72).chr(0x62).chr(0x01).chr(0x00), "72 62 01 00"),
            array(chr(0x72).chr(0x62).chr(0x01).chr(0x00).chr(0x00).chr(0x01), "72 62 01 00 00 01"),
            array(chr(0x01).chr(0x00).chr(0x01), "01 00 01"),
        );
    }

    public function testParseRequest()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 01 00 01";                         // question: type A, class IN

        $data = $this->convertTcpDumpToBinary($data);

        $request = $this->parser->parseMessage($data);

        $header = $request->header;
        $this->assertSame(0x7262, $header->get('id'));
        $this->assertSame(1, $header->get('qdCount'));
        $this->assertSame(0, $header->get('anCount'));
        $this->assertSame(0, $header->get('nsCount'));
        $this->assertSame(0, $header->get('arCount'));
        $this->assertSame(0, $header->get('qr'));
        $this->assertSame(Message::OPCODE_QUERY, $header->get('opcode'));
        $this->assertSame(0, $header->get('aa'));
        $this->assertSame(0, $header->get('tc'));
        $this->assertSame(1, $header->get('rd'));
        $this->assertSame(0, $header->get('ra'));
        $this->assertSame(0, $header->get('z'));
        $this->assertSame(Message::RCODE_OK, $header->get('rcode'));

        $this->assertCount(1, $request->questions);
        $this->assertSame('igor.io', $request->questions[0]['name']);
        $this->assertSame(Message::TYPE_A, $request->questions[0]['type']);
        $this->assertSame(Message::CLASS_IN, $request->questions[0]['class']);
    }

    public function testParseResponse()
    {
        $data = "";
        $data .= "72 62 81 80 00 01 00 01 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 01 00 01";                         // question: type A, class IN
        $data .= "c0 0c";                               // answer: offset pointer to igor.io
        $data .= "00 01 00 01";                         // answer: type A, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 04";                               // answer: rdlength 4
        $data .= "b2 4f a9 83";                         // answer: rdata 178.79.169.131

        $data = $this->convertTcpDumpToBinary($data);

        $response = $this->parser->parseMessage($data);

        $header = $response->header;
        $this->assertSame(0x7262, $header->get('id'));
        $this->assertSame(1, $header->get('qdCount'));
        $this->assertSame(1, $header->get('anCount'));
        $this->assertSame(0, $header->get('nsCount'));
        $this->assertSame(0, $header->get('arCount'));
        $this->assertSame(1, $header->get('qr'));
        $this->assertSame(Message::OPCODE_QUERY, $header->get('opcode'));
        $this->assertSame(0, $header->get('aa'));
        $this->assertSame(0, $header->get('tc'));
        $this->assertSame(1, $header->get('rd'));
        $this->assertSame(1, $header->get('ra'));
        $this->assertSame(0, $header->get('z'));
        $this->assertSame(Message::RCODE_OK, $header->get('rcode'));

        $this->assertCount(1, $response->questions);
        $this->assertSame('igor.io', $response->questions[0]['name']);
        $this->assertSame(Message::TYPE_A, $response->questions[0]['type']);
        $this->assertSame(Message::CLASS_IN, $response->questions[0]['class']);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_A, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(86400, $response->answers[0]->ttl);
        $this->assertSame('178.79.169.131', $response->answers[0]->data);
    }

    public function testParseQuestionWithTwoQuestions()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";              // question: igor.io
        $data .= "00 01 00 01";                             // question: type A, class IN
        $data .= "03 77 77 77 04 69 67 6f 72 02 69 6f 00";  // question: www.igor.io
        $data .= "00 01 00 01";                             // question: type A, class IN

        $data = $this->convertTcpDumpToBinary($data);

        $request = new Message();
        $request->header->set('qdCount', 2);
        $request->data = $data;

        $this->parser->parseQuestion($request);

        $this->assertCount(2, $request->questions);
        $this->assertSame('igor.io', $request->questions[0]['name']);
        $this->assertSame(Message::TYPE_A, $request->questions[0]['type']);
        $this->assertSame(Message::CLASS_IN, $request->questions[0]['class']);
        $this->assertSame('www.igor.io', $request->questions[1]['name']);
        $this->assertSame(Message::TYPE_A, $request->questions[1]['type']);
        $this->assertSame(Message::CLASS_IN, $request->questions[1]['class']);
    }

    public function testParseAnswerWithInlineData()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 01 00 01";                         // answer: type A, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 04";                               // answer: rdlength 4
        $data .= "b2 4f a9 83";                         // answer: rdata 178.79.169.131

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_A, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(86400, $response->answers[0]->ttl);
        $this->assertSame('178.79.169.131', $response->answers[0]->data);
    }

    public function testParseAnswerWithExcessiveTtlReturnsZeroTtl()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 01 00 01";                         // answer: type A, class IN
        $data .= "ff ff ff ff";                         // answer: ttl 2^32 - 1
        $data .= "00 04";                               // answer: rdlength 4
        $data .= "b2 4f a9 83";                         // answer: rdata 178.79.169.131

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_A, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(0, $response->answers[0]->ttl);
        $this->assertSame('178.79.169.131', $response->answers[0]->data);
    }

    public function testParseAnswerWithTtlExactlyBoundaryReturnsZeroTtl()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 01 00 01";                         // answer: type A, class IN
        $data .= "80 00 00 00";                         // answer: ttl 2^31
        $data .= "00 04";                               // answer: rdlength 4
        $data .= "b2 4f a9 83";                         // answer: rdata 178.79.169.131

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_A, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(0, $response->answers[0]->ttl);
        $this->assertSame('178.79.169.131', $response->answers[0]->data);
    }

    public function testParseAnswerWithMaximumTtlReturnsExactTtl()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 01 00 01";                         // answer: type A, class IN
        $data .= "7f ff ff ff";                         // answer: ttl 2^31 - 1
        $data .= "00 04";                               // answer: rdlength 4
        $data .= "b2 4f a9 83";                         // answer: rdata 178.79.169.131

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_A, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(0x7fffffff, $response->answers[0]->ttl);
        $this->assertSame('178.79.169.131', $response->answers[0]->data);
    }

    public function testParseAnswerWithUnknownType()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "23 28 00 01";                         // answer: type 9000, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 05";                               // answer: rdlength 5
        $data .= "68 65 6c 6c 6f";                      // answer: rdata "hello"

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(9000, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(86400, $response->answers[0]->ttl);
        $this->assertSame('hello', $response->answers[0]->data);
    }

    public function testParseResponseWithCnameAndOffsetPointers()
    {
        $data = "";
        $data .= "9e 8d 81 80 00 01 00 01 00 00 00 00";                 // header
        $data .= "04 6d 61 69 6c 06 67 6f 6f 67 6c 65 03 63 6f 6d 00";  // question: mail.google.com
        $data .= "00 05 00 01";                                         // question: type CNAME, class IN
        $data .= "c0 0c";                                               // answer: offset pointer to mail.google.com
        $data .= "00 05 00 01";                                         // answer: type CNAME, class IN
        $data .= "00 00 a8 9c";                                         // answer: ttl 43164
        $data .= "00 0f";                                               // answer: rdlength 15
        $data .= "0a 67 6f 6f 67 6c 65 6d 61 69 6c 01 6c";              // answer: rdata googlemail.l.
        $data .= "c0 11";                                               // answer: rdata offset pointer to google.com

        $data = $this->convertTcpDumpToBinary($data);

        $response = $this->parser->parseMessage($data);

        $this->assertCount(1, $response->questions);
        $this->assertSame('mail.google.com', $response->questions[0]['name']);
        $this->assertSame(Message::TYPE_CNAME, $response->questions[0]['type']);
        $this->assertSame(Message::CLASS_IN, $response->questions[0]['class']);

        $this->assertCount(1, $response->answers);
        $this->assertSame('mail.google.com', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_CNAME, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(43164, $response->answers[0]->ttl);
        $this->assertSame('googlemail.l.google.com', $response->answers[0]->data);
    }

    public function testParseAAAAResponse()
    {
        $data = "";
        $data .= "cd 72 81 80 00 01 00 01 00 00 00 00 06";          // header
        $data .= "67 6f 6f 67 6c 65 03 63 6f 6d 00";                // question: google.com
        $data .= "00 1c 00 01";                                     // question: type AAAA, class IN
        $data .= "c0 0c";                                           // answer: offset pointer to google.com
        $data .= "00 1c 00 01";                                     // answer: type AAAA, class IN
        $data .= "00 00 01 2b";                                     // answer: ttl 299
        $data .= "00 10";                                           // answer: rdlength 16
        $data .= "2a 00 14 50 40 09 08 09 00 00 00 00 00 00 20 0e"; // answer: 2a00:1450:4009:809::200e

        $data = $this->convertTcpDumpToBinary($data);

        $response = $this->parser->parseMessage($data);

        $header = $response->header;
        $this->assertSame(0xcd72, $header->get('id'));
        $this->assertSame(1, $header->get('qdCount'));
        $this->assertSame(1, $header->get('anCount'));
        $this->assertSame(0, $header->get('nsCount'));
        $this->assertSame(0, $header->get('arCount'));
        $this->assertSame(1, $header->get('qr'));
        $this->assertSame(Message::OPCODE_QUERY, $header->get('opcode'));
        $this->assertSame(0, $header->get('aa'));
        $this->assertSame(0, $header->get('tc'));
        $this->assertSame(1, $header->get('rd'));
        $this->assertSame(1, $header->get('ra'));
        $this->assertSame(0, $header->get('z'));
        $this->assertSame(Message::RCODE_OK, $header->get('rcode'));

        $this->assertCount(1, $response->questions);
        $this->assertSame('google.com', $response->questions[0]['name']);
        $this->assertSame(Message::TYPE_AAAA, $response->questions[0]['type']);
        $this->assertSame(Message::CLASS_IN, $response->questions[0]['class']);

        $this->assertCount(1, $response->answers);
        $this->assertSame('google.com', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_AAAA, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(299, $response->answers[0]->ttl);
        $this->assertSame('2a00:1450:4009:809::200e', $response->answers[0]->data);
    }

    public function testParseTXTResponse()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 10 00 01";                         // answer: type TXT, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 06";                               // answer: rdlength 6
        $data .= "05 68 65 6c 6c 6f";                   // answer: rdata length 5: hello

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_TXT, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(86400, $response->answers[0]->ttl);
        $this->assertSame(array('hello'), $response->answers[0]->data);
    }

    public function testParseTXTResponseMultiple()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 10 00 01";                         // answer: type TXT, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 0C";                               // answer: rdlength 12
        $data .= "05 68 65 6c 6c 6f 05 77 6f 72 6c 64"; // answer: rdata length 5: hello, length 5: world

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_TXT, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(86400, $response->answers[0]->ttl);
        $this->assertSame(array('hello', 'world'), $response->answers[0]->data);
    }

    public function testParseMXResponse()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 0f 00 01";                         // answer: type MX, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 09";                               // answer: rdlength 9
        $data .= "00 0a 05 68 65 6c 6c 6f 00";          // answer: rdata priority 10: hello

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_MX, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(86400, $response->answers[0]->ttl);
        $this->assertSame(array('priority' => 10, 'target' => 'hello'), $response->answers[0]->data);
    }

    public function testParseSRVResponse()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 21 00 01";                         // answer: type SRV, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 0C";                               // answer: rdlength 12
        $data .= "00 0a 00 14 1F 90 04 74 65 73 74 00"; // answer: rdata priority 10, weight 20, port 8080 test

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_SRV, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(86400, $response->answers[0]->ttl);
        $this->assertSame(
            array(
                'priority' => 10,
                'weight' => 20,
                'port' => 8080,
                'target' => 'test'
            ),
            $response->answers[0]->data
        );
    }

    public function testParseMessageResponseWithTwoAnswers()
    {
        $data = "";
        $data .= "bc 73 81 80 00 01 00 02 00 00 00 00";                 // header
        $data .= "02 69 6f 0d 77 68 6f 69 73 2d 73 65 72 76 65 72 73 03 6e 65 74 00";
                                                                        // question: io.whois-servers.net
        $data .= "00 01 00 01";                                         // question: type A, class IN
        $data .= "c0 0c";                                               // answer: offset pointer to io.whois-servers.net
        $data .= "00 05 00 01";                                         // answer: type CNAME, class IN
        $data .= "00 00 00 29";                                         // answer: ttl 41
        $data .= "00 0e";                                               // answer: rdlength 14
        $data .= "05 77 68 6f 69 73 03 6e 69 63 02 69 6f 00";           // answer: rdata whois.nic.io
        $data .= "c0 32";                                               // answer: offset pointer to whois.nic.io
        $data .= "00 01 00 01";                                         // answer: type CNAME, class IN
        $data .= "00 00 0d f7";                                         // answer: ttl 3575
        $data .= "00 04";                                               // answer: rdlength 4
        $data .= "c1 df 4e 98";                                         // answer: rdata 193.223.78.152

        $data = $this->convertTcpDumpToBinary($data);

        $response = $this->parser->parseMessage($data);

        $this->assertCount(1, $response->questions);
        $this->assertSame('io.whois-servers.net', $response->questions[0]['name']);
        $this->assertSame(Message::TYPE_A, $response->questions[0]['type']);
        $this->assertSame(Message::CLASS_IN, $response->questions[0]['class']);

        $this->assertCount(2, $response->answers);

        $this->assertSame('io.whois-servers.net', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_CNAME, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(41, $response->answers[0]->ttl);
        $this->assertSame('whois.nic.io', $response->answers[0]->data);

        $this->assertSame('whois.nic.io', $response->answers[1]->name);
        $this->assertSame(Message::TYPE_A, $response->answers[1]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[1]->class);
        $this->assertSame(3575, $response->answers[1]->ttl);
        $this->assertSame('193.223.78.152', $response->answers[1]->data);
    }

    public function testParseMessageResponseWithTwoAuthorityRecords()
    {
        $data = "";
        $data .= "bc 73 81 80 00 01 00 00 00 02 00 00";                 // header
        $data .= "02 69 6f 0d 77 68 6f 69 73 2d 73 65 72 76 65 72 73 03 6e 65 74 00";
        // question: io.whois-servers.net
        $data .= "00 01 00 01";                                         // question: type A, class IN
        $data .= "c0 0c";                                               // authority: offset pointer to io.whois-servers.net
        $data .= "00 05 00 01";                                         // authority: type CNAME, class IN
        $data .= "00 00 00 29";                                         // authority: ttl 41
        $data .= "00 0e";                                               // authority: rdlength 14
        $data .= "05 77 68 6f 69 73 03 6e 69 63 02 69 6f 00";           // authority: rdata whois.nic.io
        $data .= "c0 32";                                               // authority: offset pointer to whois.nic.io
        $data .= "00 01 00 01";                                         // authority: type CNAME, class IN
        $data .= "00 00 0d f7";                                         // authority: ttl 3575
        $data .= "00 04";                                               // authority: rdlength 4
        $data .= "c1 df 4e 98";                                         // authority: rdata 193.223.78.152

        $data = $this->convertTcpDumpToBinary($data);

        $response = $this->parser->parseMessage($data);

        $this->assertCount(1, $response->questions);
        $this->assertSame('io.whois-servers.net', $response->questions[0]['name']);
        $this->assertSame(Message::TYPE_A, $response->questions[0]['type']);
        $this->assertSame(Message::CLASS_IN, $response->questions[0]['class']);

        $this->assertCount(0, $response->answers);

        $this->assertCount(2, $response->authority);

        $this->assertSame('io.whois-servers.net', $response->authority[0]->name);
        $this->assertSame(Message::TYPE_CNAME, $response->authority[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->authority[0]->class);
        $this->assertSame(41, $response->authority[0]->ttl);
        $this->assertSame('whois.nic.io', $response->authority[0]->data);

        $this->assertSame('whois.nic.io', $response->authority[1]->name);
        $this->assertSame(Message::TYPE_A, $response->authority[1]->type);
        $this->assertSame(Message::CLASS_IN, $response->authority[1]->class);
        $this->assertSame(3575, $response->authority[1]->ttl);
        $this->assertSame('193.223.78.152', $response->authority[1]->data);
    }

    public function testParseMessageResponseWithAnswerAndAdditionalRecord()
    {
        $data = "";
        $data .= "bc 73 81 80 00 01 00 01 00 00 00 01";                 // header
        $data .= "02 69 6f 0d 77 68 6f 69 73 2d 73 65 72 76 65 72 73 03 6e 65 74 00";
        // question: io.whois-servers.net
        $data .= "00 01 00 01";                                         // question: type A, class IN
        $data .= "c0 0c";                                               // answer: offset pointer to io.whois-servers.net
        $data .= "00 05 00 01";                                         // answer: type CNAME, class IN
        $data .= "00 00 00 29";                                         // answer: ttl 41
        $data .= "00 0e";                                               // answer: rdlength 14
        $data .= "05 77 68 6f 69 73 03 6e 69 63 02 69 6f 00";           // answer: rdata whois.nic.io
        $data .= "c0 32";                                               // additional: offset pointer to whois.nic.io
        $data .= "00 01 00 01";                                         // additional: type CNAME, class IN
        $data .= "00 00 0d f7";                                         // additional: ttl 3575
        $data .= "00 04";                                               // additional: rdlength 4
        $data .= "c1 df 4e 98";                                         // additional: rdata 193.223.78.152

        $data = $this->convertTcpDumpToBinary($data);

        $response = $this->parser->parseMessage($data);

        $this->assertCount(1, $response->questions);
        $this->assertSame('io.whois-servers.net', $response->questions[0]['name']);
        $this->assertSame(Message::TYPE_A, $response->questions[0]['type']);
        $this->assertSame(Message::CLASS_IN, $response->questions[0]['class']);

        $this->assertCount(1, $response->answers);

        $this->assertSame('io.whois-servers.net', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_CNAME, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(41, $response->answers[0]->ttl);
        $this->assertSame('whois.nic.io', $response->answers[0]->data);

        $this->assertCount(0, $response->authority);
        $this->assertCount(1, $response->additional);

        $this->assertSame('whois.nic.io', $response->additional[0]->name);
        $this->assertSame(Message::TYPE_A, $response->additional[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->additional[0]->class);
        $this->assertSame(3575, $response->additional[0]->ttl);
        $this->assertSame('193.223.78.152', $response->additional[0]->data);
    }

    public function testParseNSResponse()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 02 00 01";                         // answer: type NS, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 07";                               // answer: rdlength 7
        $data .= "05 68 65 6c 6c 6f 00";                // answer: rdata hello

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_NS, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(86400, $response->answers[0]->ttl);
        $this->assertSame('hello', $response->answers[0]->data);
    }

    public function testParseSOAResponse()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 06 00 01";                         // answer: type SOA, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 27";                               // answer: rdlength 39
        $data .= "02 6e 73 05 68 65 6c 6c 6f 00";       // answer: rdata ns.hello (mname)
        $data .= "01 65 05 68 65 6c 6c 6f 00";          // answer: rdata e.hello (rname)
        $data .= "78 49 28 D5 00 00 2a 30 00 00 0e 10"; // answer: rdata 2018060501, 10800, 3600
        $data .= "00 09 3a 80 00 00 0e 10";             // answer: 605800, 3600

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(1, $response->answers);
        $this->assertSame('igor.io', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_SOA, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(86400, $response->answers[0]->ttl);
        $this->assertSame(
            array(
                'mname' => 'ns.hello',
                'rname' => 'e.hello',
                'serial' => 2018060501,
                'refresh' => 10800,
                'retry' => 3600,
                'expire' => 604800,
                'minimum' => 3600
            ),
            $response->answers[0]->data
        );
    }

    public function testParsePTRResponse()
    {
        $data = "";
        $data .= "5d d8 81 80 00 01 00 01 00 00 00 00";             // header
        $data .= "01 34 01 34 01 38 01 38 07 69 6e";                // question: 4.4.8.8.in-addr.arpa
        $data .= "2d 61 64 64 72 04 61 72 70 61 00";                // question (continued)
        $data .= "00 0c 00 01";                                     // question: type PTR, class IN
        $data .= "c0 0c";                                           // answer: offset pointer to rdata
        $data .= "00 0c 00 01";                                     // answer: type PTR, class IN
        $data .= "00 01 51 7f";                                     // answer: ttl 86399
        $data .= "00 20";                                           // answer: rdlength 32
        $data .= "13 67 6f 6f 67 6c 65 2d 70 75 62 6c 69 63 2d 64"; // answer: rdata google-public-dns-b.google.com.
        $data .= "6e 73 2d 62 06 67 6f 6f 67 6c 65 03 63 6f 6d 00";

        $data = $this->convertTcpDumpToBinary($data);

        $response = $this->parser->parseMessage($data);

        $header = $response->header;
        $this->assertSame(0x5dd8, $header->get('id'));
        $this->assertSame(1, $header->get('qdCount'));
        $this->assertSame(1, $header->get('anCount'));
        $this->assertSame(0, $header->get('nsCount'));
        $this->assertSame(0, $header->get('arCount'));
        $this->assertSame(1, $header->get('qr'));
        $this->assertSame(Message::OPCODE_QUERY, $header->get('opcode'));
        $this->assertSame(0, $header->get('aa'));
        $this->assertSame(0, $header->get('tc'));
        $this->assertSame(1, $header->get('rd'));
        $this->assertSame(1, $header->get('ra'));
        $this->assertSame(0, $header->get('z'));
        $this->assertSame(Message::RCODE_OK, $header->get('rcode'));

        $this->assertCount(1, $response->questions);
        $this->assertSame('4.4.8.8.in-addr.arpa', $response->questions[0]['name']);
        $this->assertSame(Message::TYPE_PTR, $response->questions[0]['type']);
        $this->assertSame(Message::CLASS_IN, $response->questions[0]['class']);

        $this->assertCount(1, $response->answers);
        $this->assertSame('4.4.8.8.in-addr.arpa', $response->answers[0]->name);
        $this->assertSame(Message::TYPE_PTR, $response->answers[0]->type);
        $this->assertSame(Message::CLASS_IN, $response->answers[0]->class);
        $this->assertSame(86399, $response->answers[0]->ttl);
        $this->assertSame('google-public-dns-b.google.com', $response->answers[0]->data);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testParseIncompleteQuestionThrows()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        //$data .= "00 01 00 01";                         // question: type A, class IN

        $data = $this->convertTcpDumpToBinary($data);

        $this->parser->parseMessage($data);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testParseIncompleteQuestionLabelThrows()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "04 69 67";          // question: ig …?

        $data = $this->convertTcpDumpToBinary($data);

        $this->parser->parseMessage($data);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testParseIncompleteQuestionNameThrows()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "04 69 67 6f 72";          // question: igor. …?

        $data = $this->convertTcpDumpToBinary($data);

        $this->parser->parseMessage($data);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testParseIncompleteOffsetPointerInQuestionNameThrows()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "ff";          // question: incomplete offset pointer

        $data = $this->convertTcpDumpToBinary($data);

        $this->parser->parseMessage($data);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testParseInvalidOffsetPointerInQuestionNameThrows()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "ff ff";          // question: offset pointer to invalid address

        $data = $this->convertTcpDumpToBinary($data);

        $this->parser->parseMessage($data);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testParseInvalidOffsetPointerToSameLabelInQuestionNameThrows()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "c0 0c";          // question: offset pointer to invalid address

        $data = $this->convertTcpDumpToBinary($data);

        $this->parser->parseMessage($data);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testParseInvalidOffsetPointerToStartOfMessageInQuestionNameThrows()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "c0 00";          // question: offset pointer to start of message

        $data = $this->convertTcpDumpToBinary($data);

        $this->parser->parseMessage($data);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testParseIncompleteAnswerFieldsThrows()
    {
        $data = "";
        $data .= "72 62 81 80 00 01 00 01 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 01 00 01";                         // question: type A, class IN
        $data .= "c0 0c";                               // answer: offset pointer to igor.io

        $data = $this->convertTcpDumpToBinary($data);

        $this->parser->parseMessage($data);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testParseMessageResponseWithIncompleteAuthorityRecordThrows()
    {
        $data = "";
        $data .= "72 62 81 80 00 01 00 00 00 01 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 01 00 01";                         // question: type A, class IN
        $data .= "c0 0c";                               // authority: offset pointer to igor.io

        $data = $this->convertTcpDumpToBinary($data);

        $this->parser->parseMessage($data);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testParseMessageResponseWithIncompleteAdditionalRecordThrows()
    {
        $data = "";
        $data .= "72 62 81 80 00 01 00 00 00 00 00 01"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 01 00 01";                         // question: type A, class IN
        $data .= "c0 0c";                               // additional: offset pointer to igor.io

        $data = $this->convertTcpDumpToBinary($data);

        $this->parser->parseMessage($data);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testParseIncompleteAnswerRecordDataThrows()
    {
        $data = "";
        $data .= "72 62 81 80 00 01 00 01 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 01 00 01";                         // question: type A, class IN
        $data .= "c0 0c";                               // answer: offset pointer to igor.io
        $data .= "00 01 00 01";                         // answer: type A, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 04";                               // answer: rdlength 4

        $data = $this->convertTcpDumpToBinary($data);

        $this->parser->parseMessage($data);
    }

    public function testParseInvalidNSResponseWhereDomainNameIsMissing()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 02 00 01";                         // answer: type NS, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 00";                               // answer: rdlength 0

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(0, $response->answers);
    }

    public function testParseInvalidAResponseWhereIPIsMissing()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 01 00 01";                         // answer: type A, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 00";                               // answer: rdlength 0

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(0, $response->answers);
    }

    public function testParseInvalidAAAAResponseWhereIPIsMissing()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 1c 00 01";                         // answer: type AAAA, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 00";                               // answer: rdlength 0

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(0, $response->answers);
    }

    public function testParseInvalidTXTResponseWhereTxtChunkExceedsLimit()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 10 00 01";                         // answer: type TXT, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 06";                               // answer: rdlength 6
        $data .= "06 68 65 6c 6c 6f 6f";                // answer: rdata length 6: helloo

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(0, $response->answers);
    }

    public function testParseInvalidMXResponseWhereDomainNameIsIncomplete()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 0f 00 01";                         // answer: type MX, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 08";                               // answer: rdlength 8
        $data .= "00 0a 05 68 65 6c 6c 6f";             // answer: rdata priority 10: hello (missing label end)

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(0, $response->answers);
    }

    public function testParseInvalidMXResponseWhereDomainNameIsMissing()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 0f 00 01";                         // answer: type MX, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 02";                               // answer: rdlength 2
        $data .= "00 0a";                               // answer: rdata priority 10

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(0, $response->answers);
    }

    public function testParseInvalidSRVResponseWhereDomainNameIsIncomplete()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 21 00 01";                         // answer: type SRV, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 0b";                               // answer: rdlength 11
        $data .= "00 0a 00 14 1F 90 04 74 65 73 74";    // answer: rdata priority 10, weight 20, port 8080 test (missing label end)

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(0, $response->answers);
    }

    public function testParseInvalidSRVResponseWhereDomainNameIsMissing()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 21 00 01";                         // answer: type SRV, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 06";                               // answer: rdlength 6
        $data .= "00 0a 00 14 1F 90";                   // answer: rdata priority 10, weight 20, port 8080

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(0, $response->answers);
    }

    public function testParseInvalidSOAResponseWhereFlagsAreMissing()
    {
        $data = "";
        $data .= "04 69 67 6f 72 02 69 6f 00";          // answer: igor.io
        $data .= "00 06 00 01";                         // answer: type SOA, class IN
        $data .= "00 01 51 80";                         // answer: ttl 86400
        $data .= "00 13";                               // answer: rdlength 19
        $data .= "02 6e 73 05 68 65 6c 6c 6f 00";       // answer: rdata ns.hello (mname)
        $data .= "01 65 05 68 65 6c 6c 6f 00";          // answer: rdata e.hello (rname)

        $data = $this->convertTcpDumpToBinary($data);

        $response = new Message();
        $response->header->set('anCount', 1);
        $response->data = $data;

        $this->parser->parseAnswer($response);

        $this->assertCount(0, $response->answers);
    }

    private function convertTcpDumpToBinary($input)
    {
        // sudo ngrep -d en1 -x port 53

        return pack('H*', str_replace(' ', '', $input));
    }
}
