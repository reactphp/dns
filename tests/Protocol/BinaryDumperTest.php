<?php

namespace React\Tests\Dns\Protocol;

use React\Dns\Model\Record;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Model\Message;
use React\Dns\Protocol\HumanParser;
use React\Dns\Query\Query;

class BinaryDumperTest extends \PHPUnit_Framework_TestCase
{
    public function testRequestToBinary()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 01 00 01";                         // question: type A, class IN

        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $request = new Message();
        $request->header->set('id', 0x7262);
        $request->header->set('rd', 1);

        $request->questions[] = new Query(
            'igor.io',
            Message::TYPE_A,
            Message::CLASS_IN,
            time()
        );

        $request->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($request);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseToBinary()
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

        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0x7262);
        $response->header->set('rd', 1);
        $response->header->set('qr', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query(
            'igor.io',
            Message::TYPE_A,
            Message::CLASS_IN,
            time()
        );

        $response->answers[] = new Record(
            'igor.io',
            Message::TYPE_A,
            Message::CLASS_IN,
            86400,
            '178.79.169.131'
        );

        $response->prepare();

        $this->assertTrue($response->header->isResponse());

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseWithCnameAndOffsetPointersToBinary()
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

        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0x9e8d);
        $response->header->set('rd', 1);
        $response->header->set('qr', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query(
            'mail.google.com',
            Message::TYPE_CNAME,
            Message::CLASS_IN,
            time()
        );

        $response->answers[] = new Record(
            'mail.google.com',
            Message::TYPE_CNAME,
            Message::CLASS_IN,
            43164,
            'googlemail.l.google.com'
        );

        $response->prepare();

        $this->assertTrue($response->header->isResponse());

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseWithManyARecordsToBinary()
    {
        /**

        Looking A records for google.com

        HEADER:
                opcode: QUERY, status: OK, id: 48970
                flags: qr rd ra; QUERY: 1, ANSWER: 11, AUTHORITY: 0, ADDITIONAL: 0

        QUESTION SECTION:
                google.com.                          IN         A

        ANSWER SECTION:
                google.com.               97         IN         A           74.125.227.194
                google.com.               97         IN         A           74.125.227.201
                google.com.               97         IN         A           74.125.227.197
                google.com.               97         IN         A           74.125.227.195
                google.com.               97         IN         A           74.125.227.196
                google.com.               97         IN         A           74.125.227.198
                google.com.               97         IN         A           74.125.227.206
                google.com.               97         IN         A           74.125.227.193
                google.com.               97         IN         A           74.125.227.192
                google.com.               97         IN         A           74.125.227.200
                google.com.               97         IN         A           74.125.227.199

        HexDump:
             0 : bf 4a 81 80 00 01 00 0b 00 00 00 00 06 67 6f 6f [.J...........goo]
            10 : 67 6c 65 03 63 6f 6d 00 00 01 00 01 c0 0c 00 01 [gle.com.........]
            20 : 00 01 00 00 00 61 00 04 4a 7d e3 c2 c0 0c 00 01 [.....a..J}......]
            30 : 00 01 00 00 00 61 00 04 4a 7d e3 c9 c0 0c 00 01 [.....a..J}......]
            40 : 00 01 00 00 00 61 00 04 4a 7d e3 c5 c0 0c 00 01 [.....a..J}......]
            50 : 00 01 00 00 00 61 00 04 4a 7d e3 c3 c0 0c 00 01 [.....a..J}......]
            60 : 00 01 00 00 00 61 00 04 4a 7d e3 c4 c0 0c 00 01 [.....a..J}......]
            70 : 00 01 00 00 00 61 00 04 4a 7d e3 c6 c0 0c 00 01 [.....a..J}......]
            80 : 00 01 00 00 00 61 00 04 4a 7d e3 ce c0 0c 00 01 [.....a..J}......]
            90 : 00 01 00 00 00 61 00 04 4a 7d e3 c1 c0 0c 00 01 [.....a..J}......]
            A0 : 00 01 00 00 00 61 00 04 4a 7d e3 c0 c0 0c 00 01 [.....a..J}......]
            B0 : 00 01 00 00 00 61 00 04 4a 7d e3 c8 c0 0c 00 01 [.....a..J}......]
            C0 : 00 01 00 00 00 61 00 04 4a 7d e3 c7 [.....a..J}..]
        */

        $data = "";
        $data .= "bf 4a 81 80 00 01 00 0b 00 00 00";                // header
        $data .= "00 06 67 6f 6f 67 6c 65 03 63 6f 6d 00";          // question: google.com
        $data .= "00 01 00 01";                                     // question: type A, class IN
        $data .= "c0 0c";                                           // answer1: pointer to google.com
        $data .= "00 01 00 01";                                     // answer1: type A, class IN
        $data .= "00 00 00 61";                                     // answer1: ttl 97
        $data .= "00 04";                                           // answer1: rdlength 4
        $data .= "4a 7d e3 c2";                                     // answer1: rdata 74.125.227.194
        $data .= "c0 0c 00 01 00 01 00 00 00 61 00 04 4a 7d e3 c9"; // answer2
        $data .= "c0 0c 00 01 00 01 00 00 00 61 00 04 4a 7d e3 c5"; // answer3
        $data .= "c0 0c 00 01 00 01 00 00 00 61 00 04 4a 7d e3 c3"; // answer4
        $data .= "c0 0c 00 01 00 01 00 00 00 61 00 04 4a 7d e3 c4"; // answer5
        $data .= "c0 0c 00 01 00 01 00 00 00 61 00 04 4a 7d e3 c6"; // answer6
        $data .= "c0 0c 00 01 00 01 00 00 00 61 00 04 4a 7d e3 ce"; // answer7
        $data .= "c0 0c 00 01 00 01 00 00 00 61 00 04 4a 7d e3 c1"; // answer8
        $data .= "c0 0c 00 01 00 01 00 00 00 61 00 04 4a 7d e3 c0"; // answer9
        $data .= "c0 0c 00 01 00 01 00 00 00 61 00 04 4a 7d e3 c8"; // answer10
        $data .= "c0 0c 00 01 00 01 00 00 00 61 00 04 4a 7d e3 c7"; // answer 11

        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0xbf4a);
        $response->header->set('rd', 1);
        $response->header->set('qr', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query('google.com', Message::TYPE_A, Message::CLASS_IN, time());
        $response->answers[] = new Record('google.com', Message::TYPE_A, Message::CLASS_IN, 97, '74.125.227.194');
        $response->answers[] = new Record('google.com', Message::TYPE_A, Message::CLASS_IN, 97, '74.125.227.201');
        $response->answers[] = new Record('google.com', Message::TYPE_A, Message::CLASS_IN, 97, '74.125.227.197');
        $response->answers[] = new Record('google.com', Message::TYPE_A, Message::CLASS_IN, 97, '74.125.227.195');
        $response->answers[] = new Record('google.com', Message::TYPE_A, Message::CLASS_IN, 97, '74.125.227.196');
        $response->answers[] = new Record('google.com', Message::TYPE_A, Message::CLASS_IN, 97, '74.125.227.198');
        $response->answers[] = new Record('google.com', Message::TYPE_A, Message::CLASS_IN, 97, '74.125.227.206');
        $response->answers[] = new Record('google.com', Message::TYPE_A, Message::CLASS_IN, 97, '74.125.227.193');
        $response->answers[] = new Record('google.com', Message::TYPE_A, Message::CLASS_IN, 97, '74.125.227.192');
        $response->answers[] = new Record('google.com', Message::TYPE_A, Message::CLASS_IN, 97, '74.125.227.200');
        $response->answers[] = new Record('google.com', Message::TYPE_A, Message::CLASS_IN, 97, '74.125.227.199');

        $response->prepare();

        $this->assertTrue($response->header->isResponse());

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseWithCNAMEToBinary()
    {
        /**
            Looking for CNAME for www.stackoverflow.com

            HEADER:
                    opcode: QUERY, status: OK, id: 19799
                    flags: qr rd ra; QUERY: 1, ANSWER: 1, AUTHORITY: 0, ADDITIONAL: 0

            QUESTION SECTION:
                    www.stackoverflow.com.               IN         CNAME

            ANSWER SECTION:
                    www.stackoverflow.com.    242        IN         CNAME       stackoverflow.com

            Hexdump:
                 0 : 4d 57 81 80 00 01 00 01 00 00 00 00 03 77 77 77 [MW...........www]
                10 : 0d 73 74 61 63 6b 6f 76 65 72 66 6c 6f 77 03 63 [.stackoverflow.c]
                20 : 6f 6d 00 00 05 00 01 c0 0c 00 05 00 01 00 00 00 [om..............]
                30 : f2 00 02 c0 10 [.....]

         */
        $data = "4d 57 81 80 00 01 00 01 00 00 00 00 03 77 77 77 0d 73 74 61 63 6b 6f 76 65 72 66".
                 "6c 6f 77 03 63 6f 6d 00 00 05 00 01 c0 0c 00 05 00 01 00 00 00 f2 00 02 c0 10";
        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0x4d57);
        $response->header->set('rd', 1);
        $response->header->set('qr', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query('www.stackoverflow.com', Message::TYPE_CNAME, Message::CLASS_IN, time());
        $response->answers[] = new Record('www.stackoverflow.com', Message::TYPE_CNAME, Message::CLASS_IN, 242, 'stackoverflow.com');
        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseWithNSToBinary()
    {
        /**
            Looking for NS for igor.io

            HEADER:
                    opcode: QUERY, status: OK, id: 18800
                    flags: qr rd ra; QUERY: 1, ANSWER: 5, AUTHORITY: 0, ADDITIONAL: 0

            QUESTION SECTION:
                    igor.io.                             IN         NS

            ANSWER SECTION:
                    igor.io.                  21599      IN         NS          ns2.linode.com
                    igor.io.                  21599      IN         NS          ns4.linode.com
                    igor.io.                  21599      IN         NS          ns5.linode.com
                    igor.io.                  21599      IN         NS          ns3.linode.com
                    igor.io.                  21599      IN         NS          ns1.linode.com

            Hexdump:
                 0 : 49 70 81 80 00 01 00 05 00 00 00 00 04 69 67 6f [Ip...........igo]
                10 : 72 02 69 6f 00 00 02 00 01 c0 0c 00 02 00 01 00 [r.io............]
                20 : 00 54 5f 00 10 03 6e 73 32 06 6c 69 6e 6f 64 65 [.T_...ns2.linode]
                30 : 03 63 6f 6d 00 c0 0c 00 02 00 01 00 00 54 5f 00 [.com.........T_.]
                40 : 06 03 6e 73 34 c0 29 c0 0c 00 02 00 01 00 00 54 [..ns4.)........T]
                50 : 5f 00 06 03 6e 73 35 c0 29 c0 0c 00 02 00 01 00 [_...ns5.).......]
                60 : 00 54 5f 00 06 03 6e 73 33 c0 29 c0 0c 00 02 00 [.T_...ns3.).....]
                70 : 01 00 00 54 5f 00 06 03 6e 73 31 c0 29 [...T_...ns1.)]
         */
        $data = "49 70 81 80 00 01 00 05 00 00 00 00 04 69 67 6f 72 02 69 6f 00 00 02 00 01 c0 0c 00 02 00 01 00".
                "00 54 5f 00 10 03 6e 73 32 06 6c 69 6e 6f 64 65 03 63 6f 6d 00 c0 0c 00 02 00 01 00 00 54 5f 00".
                "06 03 6e 73 34 c0 29 c0 0c 00 02 00 01 00 00 54 5f 00 06 03 6e 73 35 c0 29 c0 0c 00 02 00 01 00".
                "00 54 5f 00 06 03 6e 73 33 c0 29 c0 0c 00 02 00 01 00 00 54 5f 00 06 03 6e 73 31 c0 29";

        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0x4970);
        $response->header->set('rd', 1);
        $response->header->set('qr', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query('igor.io', Message::TYPE_NS, Message::CLASS_IN, time());
        $response->answers[] = new Record('igor.io', Message::TYPE_NS, Message::CLASS_IN, 21599, 'ns2.linode.com');
        $response->answers[] = new Record('igor.io', Message::TYPE_NS, Message::CLASS_IN, 21599, 'ns4.linode.com');
        $response->answers[] = new Record('igor.io', Message::TYPE_NS, Message::CLASS_IN, 21599, 'ns5.linode.com');
        $response->answers[] = new Record('igor.io', Message::TYPE_NS, Message::CLASS_IN, 21599, 'ns3.linode.com');
        $response->answers[] = new Record('igor.io', Message::TYPE_NS, Message::CLASS_IN, 21599, 'ns1.linode.com');
        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseWithInvalidPTRToBinary()
    {
        /**
            Looking for PTR for 8.8.8.8

            HEADER:
                    opcode: QUERY, status: NAME_ERROR, id: 57090
                    flags: qr rd ra; QUERY: 1, ANSWER: 0, AUTHORITY: 1, ADDITIONAL: 0

            QUESTION SECTION:
                    8.8.8.8.                             IN         PTR

            ANSWER SECTION:

            AUTHORITY SECTION:
                    .                         1799       IN         SOA         a.root-servers.net. nstld.verisign-grs.com. 2014101100 1800 900 604800 86400

            Hexdump:
                 0 : df 02 81 83 00 01 00 00 00 01 00 00 01 38 01 38 [.............8.8]
                10 : 01 38 01 38 00 00 0c 00 01 00 00 06 00 01 00 00 [.8.8............]
                20 : 07 07 00 40 01 61 0c 72 6f 6f 74 2d 73 65 72 76 [...@.a.root-serv]
                30 : 65 72 73 03 6e 65 74 00 05 6e 73 74 6c 64 0c 76 [ers.net..nstld.v]
                40 : 65 72 69 73 69 67 6e 2d 67 72 73 03 63 6f 6d 00 [erisign-grs.com.]
                50 : 78 0c be 6c 00 00 07 08 00 00 03 84 00 09 3a 80 [x..l..........:.]
                60 : 00 01 51 80 [..Q.]
         */

        $data = "";
        $data .= "df 02 81 83 00 01 00 00 00 01 00 00";             // header
        $data .= "01 38 01 3801 38 01 38 00";                       // question: name
        $data .= "00 0c 00 01";                                     // question: type PTR, class IN

        $data .= "00";                                              // authority: empty
        $data .= "00 06 00 01";                                     // authority: type SOA, class IN
        $data .= "00 00 07 07";                                     // authority: ttl: 1799
        $data .= "00 40";                                           // authority: rdlength 64
        $data .= "01 61 0c 72 6f 6f 74 2d 73 65 72 76 65 72 73 03". // authority: rdata 64 octets
                 "6e 65 74 00 05 6e 73 74 6c 64 0c 76 65 72 69 73".
                 "69 67 6e 2d 67 72 73 03 63 6f 6d 00 78 0c be 6c".
                 "00 00 07 08 00 00 03 84 00 09 3a 80 00 01 51 80";

        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0xdf02);
        $response->header->set('rd', 1);
        $response->header->set('qr', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);
        $response->header->set('rcode', Message::RCODE_NAME_ERROR);

        $response->questions[] = new Query('8.8.8.8', Message::TYPE_PTR, Message::CLASS_IN, time());
        $response->authority[] = new Record('', Message::TYPE_SOA, Message::CLASS_IN, 1799, 'a.root-servers.net nstld.verisign-grs.com 2014101100 1800 900 604800 86400');
        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseWithPTRToBinary()
    {
        /**
            Looking for PTR for 8.8.4.4 (i.e. 4.4.8.8.in-addr.arpa)

            HEADER:
                    opcode: QUERY, status: OK, id: 19043
                    flags: qr rd ra; QUERY: 1, ANSWER: 1, AUTHORITY: 0, ADDITIONAL: 0

            QUESTION SECTION:
                    4.4.8.8.in-addr.arpa.                IN         PTR

            ANSWER SECTION:
                    4.4.8.8.in-addr.arpa.     21599      IN         PTR         google-public-dns-b.google.com

            Hexdump:
                 0 : 4a 63 81 80 00 01 00 01 00 00 00 00 01 34 01 34 [Jc...........4.4]
                10 : 01 38 01 38 07 69 6e 2d 61 64 64 72 04 61 72 70 [.8.8.in-addr.arp]
                20 : 61 00 00 0c 00 01 c0 0c 00 0c 00 01 00 00 54 5f [a.............T_]
                30 : 00 20 13 67 6f 6f 67 6c 65 2d 70 75 62 6c 69 63 [. .google-public]
                40 : 2d 64 6e 73 2d 62 06 67 6f 6f 67 6c 65 03 63 6f [-dns-b.google.co]
                50 : 6d 00 [m.]
         */
        $data = "4a 63 81 80 00 01 00 01 00 00 00 00 01 34 01 34".
                "01 38 01 38 07 69 6e 2d 61 64 64 72 04 61 72 70".
                "61 00 00 0c 00 01 c0 0c 00 0c 00 01 00 00 54 5f".
                "00 20 13 67 6f 6f 67 6c 65 2d 70 75 62 6c 69 63".
                "2d 64 6e 73 2d 62 06 67 6f 6f 67 6c 65 03 63 6f".
                "6d 00";

        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0x4a63);
        $response->header->set('rd', 1);
        $response->header->set('qr', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query('4.4.8.8.in-addr.arpa', Message::TYPE_PTR, Message::CLASS_IN, time());
        $response->answers[] = new Record('4.4.8.8.in-addr.arpa', Message::TYPE_PTR, Message::CLASS_IN, 21599, 'google-public-dns-b.google.com');
        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseWithTXTToBinary()
    {
        /**
        Looking for TXT for www.stackoverflow.com

        HEADER:
                opcode: QUERY, status: OK, id: 35484
                flags: qr rd ra; QUERY: 1, ANSWER: 2, AUTHORITY: 0, ADDITIONAL: 0

        QUESTION SECTION:
                www.stackoverflow.com.               IN         TXT

        ANSWER SECTION:
                www.stackoverflow.com.    54         IN         CNAME       stackoverflow.com
                stackoverflow.com.        299        IN         TXT         "v=spf1 a mx ip4:198.252.206.0/24 ip4:69.59.197.0/26 include:cmail1.com include:_spf.google.com ~all"

        Hexdump:
             0 : 8a 9c 81 80 00 01 00 02 00 00 00 00 03 77 77 77 [.............www]
            10 : 0d 73 74 61 63 6b 6f 76 65 72 66 6c 6f 77 03 63 [.stackoverflow.c]
            20 : 6f 6d 00 00 10 00 01 c0 0c 00 05 00 01 00 00 00 [om..............]
            30 : 36 00 02 c0 10 c0 10 00 10 00 01 00 00 01 2b 00 [6.............+.]
            40 : 64 63 76 3d 73 70 66 31 20 61 20 6d 78 20 69 70 [dcv=spf1 a mx ip]
            50 : 34 3a 31 39 38 2e 32 35 32 2e 32 30 36 2e 30 2f [4:198.252.206.0/]
            60 : 32 34 20 69 70 34 3a 36 39 2e 35 39 2e 31 39 37 [24 ip4:69.59.197]
            70 : 2e 30 2f 32 36 20 69 6e 63 6c 75 64 65 3a 63 6d [.0/26 include:cm]
            80 : 61 69 6c 31 2e 63 6f 6d 20 69 6e 63 6c 75 64 65 [ail1.com include]
            90 : 3a 5f 73 70 66 2e 67 6f 6f 67 6c 65 2e 63 6f 6d [:_spf.google.com]
            A0 : 20 7e 61 6c 6c [ ~all]
         */
        $data = "8a 9c 81 80 00 01 00 02 00 00 00 00 03 77 77 77 0d 73 74 61 63 6b 6f 76 65 72 66 6c 6f 77 03 63".
                "6f 6d 00 00 10 00 01 c0 0c 00 05 00 01 00 00 00 36 00 02 c0 10 c0 10 00 10 00 01 00 00 01 2b 00".
                "64 63 76 3d 73 70 66 31 20 61 20 6d 78 20 69 70 34 3a 31 39 38 2e 32 35 32 2e 32 30 36 2e 30 2f".
                "32 34 20 69 70 34 3a 36 39 2e 35 39 2e 31 39 37 2e 30 2f 32 36 20 69 6e 63 6c 75 64 65 3a 63 6d".
                "61 69 6c 31 2e 63 6f 6d 20 69 6e 63 6c 75 64 65 3a 5f 73 70 66 2e 67 6f 6f 67 6c 65 2e 63 6f 6d".
                "20 7e 61 6c 6c";
        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0x8a9c);
        $response->header->set('rd', 1);
        $response->header->set('qr', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query('www.stackoverflow.com', Message::TYPE_TXT, Message::CLASS_IN, time());
        $response->answers[] = new Record('www.stackoverflow.com', Message::TYPE_CNAME, Message::CLASS_IN, 54, 'stackoverflow.com');
        $response->answers[] = new Record('stackoverflow.com', Message::TYPE_TXT, Message::CLASS_IN, 299, 'v=spf1 a mx ip4:198.252.206.0/24 ip4:69.59.197.0/26 include:cmail1.com include:_spf.google.com ~all');
        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseWithMultipleTXTToBinary()
    {
        /**
        Looking for TXT for www.stackoverflow.com

        HEADER:
                opcode: QUERY, status: OK, id: 35484
                flags: qr rd ra; QUERY: 1, ANSWER: 2, AUTHORITY: 0, ADDITIONAL: 0

        QUESTION SECTION:
                www.stackoverflow.com.               IN         TXT

        ANSWER SECTION:
                www.stackoverflow.com.    54         IN         CNAME       stackoverflow.com
                stackoverflow.com.        299        IN         TXT         "v=spf1 a mx ip4:198.252.206.0/24 ip4:69.59.197.0/26 include:cmail1.com include:_spf.google.com ~all"

        Hexdump:
             0 : 8a 9c 81 80 00 01 00 02 00 00 00 00 03 77 77 77 [.............www]
            10 : 0d 73 74 61 63 6b 6f 76 65 72 66 6c 6f 77 03 63 [.stackoverflow.c]
            20 : 6f 6d 00 00 10 00 01 c0 0c 00 05 00 01 00 00 00 [om..............]
            30 : 36 00 02 c0 10 c0 10 00 10 00 01 00 00 01 2b 00 [6.............+.]
            40 : 64 63 76 3d 73 70 66 31 20 61 20 6d 78 20 69 70 [dcv=spf1 a mx ip]
            50 : 34 3a 31 39 38 2e 32 35 32 2e 32 30 36 2e 30 2f [4:198.252.206.0/]
            60 : 32 34 20 69 70 34 3a 36 39 2e 35 39 2e 31 39 37 [24 ip4:69.59.197]
            70 : 2e 30 2f 32 36 20 69 6e 63 6c 75 64 65 3a 63 6d [.0/26 include:cm]
            80 : 61 69 6c 31 2e 63 6f 6d 20 69 6e 63 6c 75 64 65 [ail1.com include]
            90 : 3a 5f 73 70 66 2e 67 6f 6f 67 6c 65 2e 63 6f 6d [:_spf.google.com]
            A0 : 20 7e 61 6c 6c [ ~all]
         */
        $data = "8a 9c 81 80 00 01 00 02 00 00 00 00 03 77 77 77 0d 73 74 61 63 6b 6f 76 65 72 66 6c 6f 77 03 63".
                "6f 6d 00 00 10 00 01 c0 0c 00 05 00 01 00 00 00 36 00 02 c0 10 c0 10 00 10 00 01 00 00 01 2b 00".
                "64 63 76 3d 73 70 66 31 20 61 20 6d 78 20 69 70 34 3a 31 39 38 2e 32 35 32 2e 32 30 36 2e 30 2f".
                "32 34 20 69 70 34 3a 36 39 2e 35 39 2e 31 39 37 2e 30 2f 32 36 20 69 6e 63 6c 75 64 65 3a 63 6d".
                "61 69 6c 31 2e 63 6f 6d 20 69 6e 63 6c 75 64 65 3a 5f 73 70 66 2e 67 6f 6f 67 6c 65 2e 63 6f 6d".
                "20 7e 61 6c 6c";
        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0x8a9c);
        $response->header->set('rd', 1);
        $response->header->set('qr', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query('www.stackoverflow.com', Message::TYPE_TXT, Message::CLASS_IN, time());
        $response->answers[] = new Record('www.stackoverflow.com', Message::TYPE_CNAME, Message::CLASS_IN, 54, 'stackoverflow.com');
        $response->answers[] = new Record('stackoverflow.com', Message::TYPE_TXT, Message::CLASS_IN, 299, 'v=spf1 a mx ip4:198.252.206.0/24 ip4:69.59.197.0/26 include:cmail1.com include:_spf.google.com ~all');
        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseWithMXToBinary()
    {
        $data = "";
        $data .= "43 fe 81 80 00 01 00 01 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 03 63 6f 6d 00";       // question: igor.com
        $data .= "00 0f 00 01";                         // question: type MX, class IN
        $data .= "c0 0c";                               // answer: offset pointer to igor.com
        $data .= "00 0f 00 01";                         // answer: type MX, class IN
        $data .= "00 00 0e 0f";                         // answer: ttl 3599
        $data .= "00 09";                               // answer: rdlength 9
        $data .= "00 0a";                               // answer: rdata, priority: 10
        $data .= "04 6d 61 69 6c c0 0c";                // answer: rdata, mail.igor.com

        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0x43fe);
        $response->header->set('rd', 1);
        $response->header->set('qr', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query('igor.com', Message::TYPE_MX, Message::CLASS_IN, time());
        $response->answers[] = new Record('igor.com', Message::TYPE_MX, Message::CLASS_IN, 3599, 'mail.igor.com', 10);

        $response->prepare();

        $this->assertTrue($response->header->isResponse());

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseWithMultipleMXToBinary()
    {
        /*
        Hexdump:
         0 : 34 d3 81 80 00 01 00 02 00 00 00 00 04 6d 69 6e [4............min]
        10 : 65 02 70 6b 00 00 0f 00 01 c0 0c 00 0f 00 01 00 [e.pk............]
        20 : 00 54 5f 00 13 00 0a 02 6d 78 08 7a 6f 68 6f 6d [.T_.....mx.zohom]
        30 : 61 69 6c 03 63 6f 6d 00 c0 0c 00 0f 00 01 00 00 [ail.com.........]
        40 : 54 5f 00 08 00 14 03 6d 78 32 c0 2a [T_.....mx2.*]

        HEADER:
                opcode: QUERY, status: OK, id: 13523
                flags: qr rd ra; QUERY: 1, ANSWER: 2, AUTHORITY: 0, ADDITIONAL: 0

        QUESTION SECTION:
                mine.pk.                             IN         MX

        ANSWER SECTION:
                mine.pk.                  21599      IN         MX       10 mx.zohomail.com
                mine.pk.                  21599      IN         MX       20 mx2.zohomail.com
         */

        $data = "";
        $data .= "34 d3 81 80 00 01 00 02 00 00 00 00"; // header, consumed: 12
        $data .= "04 6d 69 6e 65 02 70 6b 00";          // question: mine.pk, consumed: 21
        $data .= "00 0f 00 01";                         // question: type MX, class IN, consumed: 25

        $data .= "c0 0c";                               // answer1: offset pointer to (12) mine.pk, consumed: 27
        $data .= "00 0f 00 01";                         // answer1: type MX, class IN, consumed: 31
        $data .= "00 00 54 5f";                         // answer1: ttl 21599, consumed: 35
        $data .= "00 13";                               // answer1: rdlength 13, consumed: 37
        $data .= "00 0a";                               // answer1: rdata, priority: 10, consumed: 39
        $data .= "02 6d 78 08 7a 6f 68 6f 6d 61 69 6c 03 63 6f 6d 00";                // answer1: rdata, mx.zohomail.com, consumed: 56

        $data .= "c0 0c";                               // answer2: offset pointer to mine.pk, consumed: 58
        $data .= "00 0f 00 01";                         // answer2: type MX, class IN, consumed: 62
        $data .= "00 00 54 5f";                         // answer2: ttl 21599, consumed: 66
        $data .= "00 08";                               // answer2: rdlength 8, consumed: 68
        $data .= "00 14";                               // answer2: rdata, priority: 20, consumed: 70

        $data .= "03 6d 78 32 c0 2a";                   // answer2: rdata, mx2.zohomail.com, consumed: 76
        // 03 6d 78 32
        // c0 2a is pointing to 42nd which is (08 7a 6f 68 6f 6d 61 69 6c 03 63 6f 6d 00 i.e. .zohomailcom)


        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0x34d3);
        $response->header->set('rd', 1);
        $response->header->set('qr', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query('mine.pk', Message::TYPE_MX, Message::CLASS_IN, time());
        $response->answers[] = new Record('mine.pk', Message::TYPE_MX, Message::CLASS_IN, 21599, 'mx.zohomail.com', 10);
        $response->answers[] = new Record('mine.pk', Message::TYPE_MX, Message::CLASS_IN, 21599, 'mx2.zohomail.com', 20);

        $response->prepare();

        $this->assertTrue($response->header->isResponse());

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testResponseWithSOAToBinary()
    {
        /**
            Looking for SOA for root-servers.net

            HEADER:
                    opcode: QUERY, status: OK, id: 28749
                    flags: qr rd ra; QUERY: 1, ANSWER: 1, AUTHORITY: 0, ADDITIONAL: 0

            QUESTION SECTION:
                    root-servers.net.                    IN         SOA

            ANSWER SECTION:
                    root-servers.net.         21599      IN         SOA         a.root-servers.net. nstld.verisign-grs.com. 2014060201 14400 7200 1209600 3600000

            Hexdump:
                 0 : 70 4d 81 80 00 01 00 01 00 00 00 00 0c 72 6f 6f [pM...........roo]
                10 : 74 2d 73 65 72 76 65 72 73 03 6e 65 74 00 00 06 [t-servers.net...]
                20 : 00 01 c0 0c 00 06 00 01 00 00 54 5f 00 30 01 61 [..........T_.0.a]
                30 : c0 0c 05 6e 73 74 6c 64 0c 76 65 72 69 73 69 67 [...nstld.verisig]
                40 : 6e 2d 67 72 73 03 63 6f 6d 00 78 0c 1e a9 00 00 [n-grs.com.x.....]
                50 : 38 40 00 00 1c 20 00 12 75 00 00 36 ee 80 [8@... ..u..6..]
         */

        $data = "";
        $data .= "70 4d 81 80 00 01 00 01 00 00 00 00";                         // header
        $data .= "0c 72 6f 6f 74 2d 73 65 72 76 65 72 73 03 6e 65 74 00";       // question
        $data .= "00 06 00 01";                                                 // Type SOA, Class In

        $data .= "c0 0c";                                                       // answer, NAME pointer
        $data .= "00 06 00 01";                                                 // Type SOA, Class In
        $data .= "00 00 54 5f";                                                 // ttl 21599
        $data .= "00 30";                                                       // rdlengh: 48
        $data .= "01 61 c0 0c 05 6e 73 74 6c 64 0c 76 65 72 69 73 69 67".       // rdata
                 "6e 2d 67 72 73 03 63 6f 6d 00 78 0c 1e a9 00 00" .
                 "38 40 00 00 1c 20 00 12 75 00 00 36 ee 80";
        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0x704d);
        $response->header->set('qr', 1);
        $response->header->set('rd', 1);
        $response->header->set('ra', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query('root-servers.net', Message::TYPE_SOA, Message::CLASS_IN, time());
        $response->answers[] = new Record('root-servers.net', Message::TYPE_SOA, Message::CLASS_IN, 21599, 'a.root-servers.net nstld.verisign-grs.com 2014060201 14400 7200 1209600 3600000');
        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    public function testReponseWithAuthorityAndAdditionalToBinary()
    {
         /**
            Looking for TXT for redhat.com using server: 209.132.186.218

            HEADER:
                    opcode: QUERY, status: OK, id: 43479
                    flags: qr rd aa; QUERY: 1, ANSWER: 5, AUTHORITY: 4, ADDITIONAL: 4

            QUESTION SECTION:
                    redhat.com.                          IN         TXT

            ANSWER SECTION:
                    redhat.com.               600        IN         TXT         "google-site-verification=eedOv5wpQ7prcTMciO-iEy-FZSQlD7Zs202bD3jk_hA"
                    redhat.com.               600        IN         TXT         "google-site-verification=uBxfbhn62kw7M-k-uZZp4epFkTBD5fnmxf2o7MddJTk"
                    redhat.com.               600        IN         TXT         "google-site-verification=xaXCLwbf4dxALe7snEQf9k1DXQdyLO2TPoYEyiG4ziA"
                    redhat.com.               600        IN         TXT         "v=spf1 include:spf-3.redhat.com include:spf-2.redhat.com include:spf-1.redhat.com include:spf-partners.redhat.com in
            clude:spf-partners-ips-1.redhat.com include:spf-partners-ips-2.redhat.com include:_spf.google.com -all"
                    redhat.com.               600        IN         TXT         "google-site-verification=XBXJlVMrIKUcFhWbYG8Fa-BJ2ti3nAHr6vaQpkuY_2c"

            AUTHORITY SECTION:
                    redhat.com.               600        IN         NS          ns3.redhat.com
                    redhat.com.               600        IN         NS          ns1.redhat.com
                    redhat.com.               600        IN         NS          ns4.redhat.com
                    redhat.com.               600        IN         NS          ns2.redhat.com

            ADDITIONAL SECTION:
                    ns1.redhat.com.           600        IN         A           209.132.186.218
                    ns2.redhat.com.           600        IN         A           209.132.183.2
                    ns3.redhat.com.           600        IN         A           209.132.176.100
                    ns4.redhat.com.           600        IN         A           209.132.188.218

            ------------------------------------------
            Query time: 268 ms
            Name Server: 209.132.186.218:53
            Transport: tcp
            Message Size: 721

            Hexdump:
                 0 : a9 d7 85 00 00 01 00 05 00 04 00 04 06 72 65 64 [.............red]
                10 : 68 61 74 03 63 6f 6d 00 00 10 00 01 c0 0c 00 10 [hat.com.........]
                20 : 00 01 00 00 02 58 00 45 44 67 6f 6f 67 6c 65 2d [.....X.EDgoogle-]
                30 : 73 69 74 65 2d 76 65 72 69 66 69 63 61 74 69 6f [site-verificatio]
                40 : 6e 3d 65 65 64 4f 76 35 77 70 51 37 70 72 63 54 [n=eedOv5wpQ7prcT]
                50 : 4d 63 69 4f 2d 69 45 79 2d 46 5a 53 51 6c 44 37 [MciO-iEy-FZSQlD7]
                60 : 5a 73 32 30 32 62 44 33 6a 6b 5f 68 41 c0 0c 00 [Zs202bD3jk_hA...]
                70 : 10 00 01 00 00 02 58 00 45 44 67 6f 6f 67 6c 65 [......X.EDgoogle]
                80 : 2d 73 69 74 65 2d 76 65 72 69 66 69 63 61 74 69 [-site-verificati]
                90 : 6f 6e 3d 75 42 78 66 62 68 6e 36 32 6b 77 37 4d [on=uBxfbhn62kw7M]
                A0 : 2d 6b 2d 75 5a 5a 70 34 65 70 46 6b 54 42 44 35 [-k-uZZp4epFkTBD5]
                B0 : 66 6e 6d 78 66 32 6f 37 4d 64 64 4a 54 6b c0 0c [fnmxf2o7MddJTk..]
                C0 : 00 10 00 01 00 00 02 58 00 45 44 67 6f 6f 67 6c [.......X.EDgoogl]
                D0 : 65 2d 73 69 74 65 2d 76 65 72 69 66 69 63 61 74 [e-site-verificat]
                E0 : 69 6f 6e 3d 78 61 58 43 4c 77 62 66 34 64 78 41 [ion=xaXCLwbf4dxA]
                F0 : 4c 65 37 73 6e 45 51 66 39 6b 31 44 58 51 64 79 [Le7snEQf9k1DXQdy]
               100 : 4c 4f 32 54 50 6f 59 45 79 69 47 34 7a 69 41 c0 [LO2TPoYEyiG4ziA.]
               110 : 0c 00 10 00 01 00 00 02 58 00 db da 76 3d 73 70 [........X...v=sp]
               120 : 66 31 20 69 6e 63 6c 75 64 65 3a 73 70 66 2d 33 [f1 include:spf-3]
               130 : 2e 72 65 64 68 61 74 2e 63 6f 6d 20 69 6e 63 6c [.redhat.com incl]
               140 : 75 64 65 3a 73 70 66 2d 32 2e 72 65 64 68 61 74 [ude:spf-2.redhat]
               150 : 2e 63 6f 6d 20 69 6e 63 6c 75 64 65 3a 73 70 66 [.com include:spf]
               160 : 2d 31 2e 72 65 64 68 61 74 2e 63 6f 6d 20 69 6e [-1.redhat.com in]
               170 : 63 6c 75 64 65 3a 73 70 66 2d 70 61 72 74 6e 65 [clude:spf-partne]
               180 : 72 73 2e 72 65 64 68 61 74 2e 63 6f 6d 20 69 6e [rs.redhat.com in]
               190 : 63 6c 75 64 65 3a 73 70 66 2d 70 61 72 74 6e 65 [clude:spf-partne]
               1A0 : 72 73 2d 69 70 73 2d 31 2e 72 65 64 68 61 74 2e [rs-ips-1.redhat.]
               1B0 : 63 6f 6d 20 69 6e 63 6c 75 64 65 3a 73 70 66 2d [com include:spf-]
               1C0 : 70 61 72 74 6e 65 72 73 2d 69 70 73 2d 32 2e 72 [partners-ips-2.r]
               1D0 : 65 64 68 61 74 2e 63 6f 6d 20 69 6e 63 6c 75 64 [edhat.com includ]
               1E0 : 65 3a 5f 73 70 66 2e 67 6f 6f 67 6c 65 2e 63 6f [e:_spf.google.co]
               1F0 : 6d 20 2d 61 6c 6c c0 0c 00 10 00 01 00 00 02 58 [m -all.........X]
               200 : 00 45 44 67 6f 6f 67 6c 65 2d 73 69 74 65 2d 76 [.EDgoogle-site-v]
               210 : 65 72 69 66 69 63 61 74 69 6f 6e 3d 58 42 58 4a [erification=XBXJ]
               220 : 6c 56 4d 72 49 4b 55 63 46 68 57 62 59 47 38 46 [lVMrIKUcFhWbYG8F]
               230 : 61 2d 42 4a 32 74 69 33 6e 41 48 72 36 76 61 51 [a-BJ2ti3nAHr6vaQ]
               240 : 70 6b 75 59 5f 32 63 c0 0c 00 02 00 01 00 00 02 [pkuY_2c.........]
               250 : 58 00 06 03 6e 73 33 c0 0c c0 0c 00 02 00 01 00 [X...ns3.........]
               260 : 00 02 58 00 06 03 6e 73 31 c0 0c c0 0c 00 02 00 [..X...ns1.......]
               270 : 01 00 00 02 58 00 06 03 6e 73 34 c0 0c c0 0c 00 [....X...ns4.....]
               280 : 02 00 01 00 00 02 58 00 06 03 6e 73 32 c0 0c c2 [......X...ns2...]
               290 : 65 00 01 00 01 00 00 02 58 00 04 d1 84 ba da c2 [e.......X.......]
               2A0 : 89 00 01 00 01 00 00 02 58 00 04 d1 84 b7 02 c2 [........X.......]
               2B0 : 53 00 01 00 01 00 00 02 58 00 04 d1 84 b0 64 c2 [S.......X.....d.]
               2C0 : 77 00 01 00 01 00 00 02 58 00 04 d1 84 bc da [w.......X......]
         */

        $data = "a9 d7 85 00 00 01 00 05 00 04 00 04 06 72 65 64 68 61 74 03 63 6f 6d 00 00 10 00 01 c0 0c 00".
                "10 00 01 00 00 02 58 00 45 44 67 6f 6f 67 6c 65 2d 73 69 74 65 2d 76 65 72 69 66 69 63 61 74".
                "69 6f 6e 3d 65 65 64 4f 76 35 77 70 51 37 70 72 63 54 4d 63 69 4f 2d 69 45 79 2d 46 5a 53 51".
                "6c 44 37 5a 73 32 30 32 62 44 33 6a 6b 5f 68 41 c0 0c 00 10 00 01 00 00 02 58 00 45 44 67 6f".
                "6f 67 6c 65 2d 73 69 74 65 2d 76 65 72 69 66 69 63 61 74 69 6f 6e 3d 75 42 78 66 62 68 6e 36".
                "32 6b 77 37 4d 2d 6b 2d 75 5a 5a 70 34 65 70 46 6b 54 42 44 35 66 6e 6d 78 66 32 6f 37 4d 64".
                "64 4a 54 6b c0 0c 00 10 00 01 00 00 02 58 00 45 44 67 6f 6f 67 6c 65 2d 73 69 74 65 2d 76 65".
                "72 69 66 69 63 61 74 69 6f 6e 3d 78 61 58 43 4c 77 62 66 34 64 78 41 4c 65 37 73 6e 45 51 66".
                "39 6b 31 44 58 51 64 79 4c 4f 32 54 50 6f 59 45 79 69 47 34 7a 69 41 c0 0c 00 10 00 01 00 00".
                "02 58 00 db da 76 3d 73 70 66 31 20 69 6e 63 6c 75 64 65 3a 73 70 66 2d 33 2e 72 65 64 68 61".
                "74 2e 63 6f 6d 20 69 6e 63 6c 75 64 65 3a 73 70 66 2d 32 2e 72 65 64 68 61 74 2e 63 6f 6d 20".
                "69 6e 63 6c 75 64 65 3a 73 70 66 2d 31 2e 72 65 64 68 61 74 2e 63 6f 6d 20 69 6e 63 6c 75 64".
                "65 3a 73 70 66 2d 70 61 72 74 6e 65 72 73 2e 72 65 64 68 61 74 2e 63 6f 6d 20 69 6e 63 6c 75".
                "64 65 3a 73 70 66 2d 70 61 72 74 6e 65 72 73 2d 69 70 73 2d 31 2e 72 65 64 68 61 74 2e 63 6f".
                "6d 20 69 6e 63 6c 75 64 65 3a 73 70 66 2d 70 61 72 74 6e 65 72 73 2d 69 70 73 2d 32 2e 72 65".
                "64 68 61 74 2e 63 6f 6d 20 69 6e 63 6c 75 64 65 3a 5f 73 70 66 2e 67 6f 6f 67 6c 65 2e 63 6f".
                "6d 20 2d 61 6c 6c c0 0c 00 10 00 01 00 00 02 58 00 45 44 67 6f 6f 67 6c 65 2d 73 69 74 65 2d".
                "76 65 72 69 66 69 63 61 74 69 6f 6e 3d 58 42 58 4a 6c 56 4d 72 49 4b 55 63 46 68 57 62 59 47".
                "38 46 61 2d 42 4a 32 74 69 33 6e 41 48 72 36 76 61 51 70 6b 75 59 5f 32 63 c0 0c 00 02 00 01".
                "00 00 02 58 00 06 03 6e 73 33 c0 0c c0 0c 00 02 00 01 00 00 02 58 00 06 03 6e 73 31 c0 0c c0".
                "0c 00 02 00 01 00 00 02 58 00 06 03 6e 73 34 c0 0c c0 0c 00 02 00 01 00 00 02 58 00 06 03 6e".
                "73 32 c0 0c c2 65 00 01 00 01 00 00 02 58 00 04 d1 84 ba da c2 89 00 01 00 01 00 00 02 58 00".
                "04 d1 84 b7 02 c2 53 00 01 00 01 00 00 02 58 00 04 d1 84 b0 64 c2 77 00 01 00 01 00 00 02 58".
                "00 04 d1 84 bc da";

        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0xa9d7);
        $response->header->set('qr', 1);
        $response->header->set('rd', 1);
        $response->header->set('aa', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query('redhat.com', Message::TYPE_TXT, Message::CLASS_IN, time());
        $response->answers[] = new Record('redhat.com', Message::TYPE_TXT, Message::CLASS_IN, 600,
                                          'google-site-verification=eedOv5wpQ7prcTMciO-iEy-FZSQlD7Zs202bD3jk_hA');
        $response->answers[] = new Record('redhat.com', Message::TYPE_TXT, Message::CLASS_IN, 600,
                                          'google-site-verification=uBxfbhn62kw7M-k-uZZp4epFkTBD5fnmxf2o7MddJTk');
        $response->answers[] = new Record('redhat.com', Message::TYPE_TXT, Message::CLASS_IN, 600,
                                          'google-site-verification=xaXCLwbf4dxALe7snEQf9k1DXQdyLO2TPoYEyiG4ziA');
        $response->answers[] = new Record('redhat.com', Message::TYPE_TXT, Message::CLASS_IN, 600,
                                          'v=spf1 include:spf-3.redhat.com include:spf-2.redhat.com include:spf-1'.
                                          '.redhat.com include:spf-partners.redhat.com include:spf-partners-ips-1'.
                                          '.redhat.com include:spf-partners-ips-2.redhat.com include:_spf.google.com -all');
        $response->answers[] = new Record('redhat.com', Message::TYPE_TXT, Message::CLASS_IN, 600,
                                          'google-site-verification=XBXJlVMrIKUcFhWbYG8Fa-BJ2ti3nAHr6vaQpkuY_2c');

        $response->authority[] = new Record('redhat.com', Message::TYPE_NS, Message::CLASS_IN, 600, 'ns3.redhat.com');
        $response->authority[] = new Record('redhat.com', Message::TYPE_NS, Message::CLASS_IN, 600, 'ns1.redhat.com');
        $response->authority[] = new Record('redhat.com', Message::TYPE_NS, Message::CLASS_IN, 600, 'ns4.redhat.com');
        $response->authority[] = new Record('redhat.com', Message::TYPE_NS, Message::CLASS_IN, 600, 'ns2.redhat.com');

        $response->additional[] = new Record('ns1.redhat.com', Message::TYPE_A, Message::CLASS_IN, 600, '209.132.186.218');
        $response->additional[] = new Record('ns2.redhat.com', Message::TYPE_A, Message::CLASS_IN, 600, '209.132.183.2');
        $response->additional[] = new Record('ns3.redhat.com', Message::TYPE_A, Message::CLASS_IN, 600, '209.132.176.100');
        $response->additional[] = new Record('ns4.redhat.com', Message::TYPE_A, Message::CLASS_IN, 600, '209.132.188.218');

        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);

        $data = HumanParser::convertBinaryToHexDump($data);
        $this->assertSame($expected, $data);
    }

    public function testReponseWithAdditionalToBinary()
    {
         /**
            Looking for ANY for yahoo.com using server: 68.180.131.16

            HEADER:
                    opcode: QUERY, status: OK, id: 53098
                    flags: qr rd aa; QUERY: 1, ANSWER: 14, AUTHORITY: 0, ADDITIONAL: 7

            QUESTION SECTION:
                    yahoo.com.                           IN         ANY

            ANSWER SECTION:
                    yahoo.com.                172800     IN         NS          ns3.yahoo.com
                    yahoo.com.                172800     IN         NS          ns4.yahoo.com
                    yahoo.com.                172800     IN         NS          ns2.yahoo.com
                    yahoo.com.                172800     IN         NS          ns5.yahoo.com
                    yahoo.com.                172800     IN         NS          ns1.yahoo.com
                    yahoo.com.                172800     IN         NS          ns6.yahoo.com
                    yahoo.com.                1800       IN         MX       1  mta5.am0.yahoodns.net
                    yahoo.com.                1800       IN         MX       1  mta6.am0.yahoodns.net
                    yahoo.com.                1800       IN         MX       1  mta7.am0.yahoodns.net
                    yahoo.com.                1800       IN         A           98.138.253.109
                    yahoo.com.                1800       IN         A           206.190.36.45
                    yahoo.com.                1800       IN         A           98.139.183.24
                    yahoo.com.                1800       IN         SOA         ns1.yahoo.com. hostmaster.yahoo-inc.com. 2014101102 3600 300 1814400 600
                    yahoo.com.                1800       IN         TXT         "v=spf1 redirect=_spf.mail.yahoo.com"

            ADDITIONAL SECTION:
                    ns1.yahoo.com.            1209600    IN         A           68.180.131.16
                    ns2.yahoo.com.            1209600    IN         A           68.142.255.16
                    ns3.yahoo.com.            1209600    IN         A           203.84.221.53
                    ns4.yahoo.com.            1209600    IN         A           98.138.11.157
                    ns5.yahoo.com.            1209600    IN         A           119.160.247.124
                    ns6.yahoo.com.            172800     IN         A           121.101.144.139
                    ns6.yahoo.com.            1800       IN         AAAA        2406:2000:108:4::1006

            ------------------------------------------
            Query time: 18 ms
            Name Server: 68.180.131.16:53
            Transport: udp
            Message Size: 491

            Hexdump:
                 0 : cf 6a 85 00 00 01 00 0e 00 00 00 07 05 79 61 68 [.j...........yah]
                10 : 6f 6f 03 63 6f 6d 00 00 ff 00 01 c0 0c 00 02 00 [oo.com..........]
                20 : 01 00 02 a3 00 00 06 03 6e 73 33 c0 0c c0 0c 00 [........ns3.....]
                30 : 02 00 01 00 02 a3 00 00 06 03 6e 73 34 c0 0c c0 [..........ns4...]
                40 : 0c 00 02 00 01 00 02 a3 00 00 06 03 6e 73 32 c0 [............ns2.]
                50 : 0c c0 0c 00 02 00 01 00 02 a3 00 00 06 03 6e 73 [..............ns]
                60 : 35 c0 0c c0 0c 00 02 00 01 00 02 a3 00 00 06 03 [5...............]
                70 : 6e 73 31 c0 0c c0 0c 00 02 00 01 00 02 a3 00 00 [ns1.............]
                80 : 06 03 6e 73 36 c0 0c c0 0c 00 0f 00 01 00 00 07 [..ns6...........]
                90 : 08 00 19 00 01 04 6d 74 61 35 03 61 6d 30 08 79 [......mta5.am0.y]
                A0 : 61 68 6f 6f 64 6e 73 03 6e 65 74 00 c0 0c 00 0f [ahoodns.net.....]
                B0 : 00 01 00 00 07 08 00 09 00 01 04 6d 74 61 36 c0 [...........mta6.]
                C0 : 9a c0 0c 00 0f 00 01 00 00 07 08 00 09 00 01 04 [................]
                D0 : 6d 74 61 37 c0 9a c0 0c 00 01 00 01 00 00 07 08 [mta7............]
                E0 : 00 04 62 8a fd 6d c0 0c 00 01 00 01 00 00 07 08 [..b..m..........]
                F0 : 00 04 ce be 24 2d c0 0c 00 01 00 01 00 00 07 08 [....$-..........]
               100 : 00 04 62 8b b7 18 c0 0c 00 06 00 01 00 00 07 08 [..b.............]
               110 : 00 2d c0 6f 0a 68 6f 73 74 6d 61 73 74 65 72 09 [.-.o.hostmaster.]
               120 : 79 61 68 6f 6f 2d 69 6e 63 c0 12 78 0c be 6e 00 [yahoo-inc..x..n.]
               130 : 00 0e 10 00 00 01 2c 00 1b af 80 00 00 02 58 c0 [......,.......X.]
               140 : 0c 00 10 00 01 00 00 07 08 00 24 23 76 3d 73 70 [..........$#v=sp]
               150 : 66 31 20 72 65 64 69 72 65 63 74 3d 5f 73 70 66 [f1 redirect=_spf]
               160 : 2e 6d 61 69 6c 2e 79 61 68 6f 6f 2e 63 6f 6d c0 [.mail.yahoo.com.]
               170 : 6f 00 01 00 01 00 12 75 00 00 04 44 b4 83 10 c0 [o......u...D....]
               180 : 4b 00 01 00 01 00 12 75 00 00 04 44 8e ff 10 c0 [K......u...D....]
               190 : 27 00 01 00 01 00 12 75 00 00 04 cb 54 dd 35 c0 ['......u....T.5.]
               1A0 : 39 00 01 00 01 00 12 75 00 00 04 62 8a 0b 9d c0 [9......u...b....]
               1B0 : 5d 00 01 00 01 00 12 75 00 00 04 77 a0 f7 7c c0 []......u...w..|.]
               1C0 : 81 00 01 00 01 00 02 a3 00 00 04 79 65 90 8b c0 [...........ye...]
               1D0 : 81 00 1c 00 01 00 00 07 08 00 10 24 06 20 00 01 [...........$. ..]
               1E0 : 08 00 04 00 00 00 00 00 00 10 06 [...........]
        */

        $data = "cf 6a 85 00 00 01 00 0e 00 00 00 07 05 79 61 68 6f 6f 03 63 6f 6d 00 00 ff 00 01 c0 0c 00 02 00 01 00".
                "02 a3 00 00 06 03 6e 73 33 c0 0c c0 0c 00 02 00 01 00 02 a3 00 00 06 03 6e 73 34 c0 0c c0 0c 00 02 00".
                "01 00 02 a3 00 00 06 03 6e 73 32 c0 0c c0 0c 00 02 00 01 00 02 a3 00 00 06 03 6e 73 35 c0 0c c0 0c 00".
                "02 00 01 00 02 a3 00 00 06 03 6e 73 31 c0 0c c0 0c 00 02 00 01 00 02 a3 00 00 06 03 6e 73 36 c0 0c c0".
                "0c 00 0f 00 01 00 00 07 08 00 19 00 01 04 6d 74 61 35 03 61 6d 30 08 79 61 68 6f 6f 64 6e 73 03 6e 65".
                "74 00 c0 0c 00 0f 00 01 00 00 07 08 00 09 00 01 04 6d 74 61 36 c0 9a c0 0c 00 0f 00 01 00 00 07 08 00".
                "09 00 01 04 6d 74 61 37 c0 9a c0 0c 00 01 00 01 00 00 07 08 00 04 62 8a fd 6d c0 0c 00 01 00 01 00 00".
                "07 08 00 04 ce be 24 2d c0 0c 00 01 00 01 00 00 07 08 00 04 62 8b b7 18 c0 0c 00 06 00 01 00 00 07 08".
                "00 2d c0 6f 0a 68 6f 73 74 6d 61 73 74 65 72 09 79 61 68 6f 6f 2d 69 6e 63 c0 12 78 0c be 6e 00 00 0e".
                "10 00 00 01 2c 00 1b af 80 00 00 02 58 c0 0c 00 10 00 01 00 00 07 08 00 24 23 76 3d 73 70 66 31 20 72".
                "65 64 69 72 65 63 74 3d 5f 73 70 66 2e 6d 61 69 6c 2e 79 61 68 6f 6f 2e 63 6f 6d c0 6f 00 01 00 01 00".
                "12 75 00 00 04 44 b4 83 10 c0 4b 00 01 00 01 00 12 75 00 00 04 44 8e ff 10 c0 27 00 01 00 01 00 12 75".
                "00 00 04 cb 54 dd 35 c0 39 00 01 00 01 00 12 75 00 00 04 62 8a 0b 9d c0 5d 00 01 00 01 00 12 75 00 00".
                "04 77 a0 f7 7c c0 81 00 01 00 01 00 02 a3 00 00 04 79 65 90 8b c0 81 00 1c 00 01 00 00 07 08 00 10 24".
                "06 20 00 01 08 00 04 00 00 00 00 00 00 10 06";

        $expected = HumanParser::formatHexDump(str_replace(' ', '', $data), 2);

        $response = new Message();
        $response->header->set('id', 0xcf6a);
        $response->header->set('qr', 1);
        $response->header->set('rd', 1);
        $response->header->set('aa', 1);
        $response->header->set('opcode', Message::OPCODE_QUERY);

        $response->questions[] = new Query('yahoo.com', Message::TYPE_ANY, Message::CLASS_IN, time());
        $response->answers[] = new Record('yahoo.com', Message::TYPE_NS, Message::CLASS_IN, 172800, 'ns3.yahoo.com');
        $response->answers[] = new Record('yahoo.com', Message::TYPE_NS, Message::CLASS_IN, 172800, 'ns4.yahoo.com');
        $response->answers[] = new Record('yahoo.com', Message::TYPE_NS, Message::CLASS_IN, 172800, 'ns2.yahoo.com');
        $response->answers[] = new Record('yahoo.com', Message::TYPE_NS, Message::CLASS_IN, 172800, 'ns5.yahoo.com');
        $response->answers[] = new Record('yahoo.com', Message::TYPE_NS, Message::CLASS_IN, 172800, 'ns1.yahoo.com');
        $response->answers[] = new Record('yahoo.com', Message::TYPE_NS, Message::CLASS_IN, 172800, 'ns6.yahoo.com');
        $response->answers[] = new Record('yahoo.com', Message::TYPE_MX, Message::CLASS_IN, 1800, 'mta5.am0.yahoodns.net', 1);
        $response->answers[] = new Record('yahoo.com', Message::TYPE_MX, Message::CLASS_IN, 1800, 'mta6.am0.yahoodns.net', 1);
        $response->answers[] = new Record('yahoo.com', Message::TYPE_MX, Message::CLASS_IN, 1800, 'mta7.am0.yahoodns.net', 1);
        $response->answers[] = new Record('yahoo.com', Message::TYPE_A, Message::CLASS_IN, 1800, '98.138.253.109');
        $response->answers[] = new Record('yahoo.com', Message::TYPE_A, Message::CLASS_IN, 1800, '206.190.36.45');
        $response->answers[] = new Record('yahoo.com', Message::TYPE_A, Message::CLASS_IN, 1800, '98.139.183.24');
        $response->answers[] = new Record('yahoo.com', Message::TYPE_SOA, Message::CLASS_IN, 1800,
                                         'ns1.yahoo.com hostmaster.yahoo-inc.com 2014101102 3600 300 1814400 600');
        $response->answers[] = new Record('yahoo.com', Message::TYPE_TXT, Message::CLASS_IN, 1800,
                                         'v=spf1 redirect=_spf.mail.yahoo.com');

        $response->additional[] = new Record('ns1.yahoo.com', Message::TYPE_A, Message::CLASS_IN, 1209600, '68.180.131.16');
        $response->additional[] = new Record('ns2.yahoo.com', Message::TYPE_A, Message::CLASS_IN, 1209600, '68.142.255.16');
        $response->additional[] = new Record('ns3.yahoo.com', Message::TYPE_A, Message::CLASS_IN, 1209600, '203.84.221.53');
        $response->additional[] = new Record('ns4.yahoo.com', Message::TYPE_A, Message::CLASS_IN, 1209600, '98.138.11.157');
        $response->additional[] = new Record('ns5.yahoo.com', Message::TYPE_A, Message::CLASS_IN, 1209600, '119.160.247.124');
        $response->additional[] = new Record('ns6.yahoo.com', Message::TYPE_A, Message::CLASS_IN, 172800, '121.101.144.139');
        $response->additional[] = new Record('ns6.yahoo.com', Message::TYPE_AAAA, Message::CLASS_IN, 1800, '2406:2000:108:4::1006');

        $response->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($response);
        $data = HumanParser::convertBinaryToHexDump($data);
        $this->assertSame($expected, $data);
    }
}