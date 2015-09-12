<?php

namespace React\Tests\Dns\Protocol;

use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\HumanParser;
use React\Dns\Query\Query;

class HumansParserTest extends \PHPUnit_Framework_TestCase
{
    public function testHuman2Type()
    {
        $arrCases = array(
            Message::TYPE_A => 'A',
            Message::TYPE_ANY => 'ANY',
            Message::TYPE_MX => 'MX',
            Message::TYPE_CNAME => 'cname',
            Message::TYPE_NS => 'ns',
            Message::TYPE_NS => 'Ns',
            Message::TYPE_SOA => 'sOa',
            Message::TYPE_PTR => 'PTr',
            Message::TYPE_AAAA => 'AAAA',
            Message::TYPE_TXT => 'TXT'
        );

        foreach ($arrCases as $expected => $case) {
            $actual = HumanParser::human2Type($case);
            $this->assertSame($expected, $actual);
        }
    }

    public function testType2Human()
    {
        $arrCases = array(
            Message::TYPE_A => 'A',
            Message::TYPE_ANY => 'ANY',
            Message::TYPE_MX => 'MX',
            Message::TYPE_CNAME => 'CNAME',
            Message::TYPE_NS => 'NS',
            Message::TYPE_SOA => 'SOA',
            Message::TYPE_PTR => 'PTR',
            Message::TYPE_TXT => 'TXT',
            Message::TYPE_AAAA => 'AAAA'
        );

        foreach ($arrCases as $case => $expected) {
            $actual = HumanParser::type2Human($case);
            $this->assertSame($expected, $actual);
        }
    }

    public function testHuman2Rcode()
    {
        $arrCases = array(
            Message::RCODE_OK => 'OK',
            Message::RCODE_FORMAT_ERROR => 'FORMAT_ERROR',
            Message::RCODE_NAME_ERROR => 'NAME_ERROR',
            Message::RCODE_NOT_IMPLEMENTED => 'NOT_IMPLEMENTED',
            Message::RCODE_REFUSED => 'REFUSED',
            Message::RCODE_SERVER_FAILURE => 'SERVER_FAILURE'
        );

        foreach ($arrCases as $expected => $case) {
            $actual = HumanParser::human2Rcode($case);
            $this->assertSame($expected, $actual);
        }
    }

    public function testRcode2Human()
    {
        $arrCases = array(
            Message::RCODE_OK => 'OK',
            Message::RCODE_FORMAT_ERROR => 'FORMAT_ERROR',
            Message::RCODE_NAME_ERROR => 'NAME_ERROR',
            Message::RCODE_NOT_IMPLEMENTED => 'NOT_IMPLEMENTED',
            Message::RCODE_REFUSED => 'REFUSED',
            Message::RCODE_SERVER_FAILURE => 'SERVER_FAILURE'
        );

        foreach ($arrCases as $case => $expected) {
            $actual = HumanParser::rcode2Human($case);
            $this->assertSame($expected, $actual);
        }
    }

    public function testExplainHeaderFlagsBinary()
    {
        $request = new Message();
        $request->header->set('id', 0x7262);
        $request->header->set('qr', 1);
        $request->header->set('opcode', Message::OPCODE_STATUS);
        $request->header->set('aa', 1);
        $request->header->set('tc', 1);
        $request->header->set('rd', 1);
        $request->header->set('ra', 1);
        $request->header->set('z', 0);
        $request->header->set('rcode', Message::OPCODE_IQUERY);

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($request);

        list($fields) = array_values(unpack('n', substr($data, 2, 2)));

        $explain = HumanParser::explainHeaderFlagsBinary($fields);

        preg_match('/QR:\s?(\d).+
                     Opcode:\s(\d{4}).+
                     AA:\s(\d).+
                     TC:\s(\d).+
                     RD:\s(\d).+
                     RA:\s(\d).+
                     Z:\s(\d{3}).+
                     RCODE:\s(\d{4})/xms', $explain, $arrMatches);

        $this->assertEquals($request->header->attributes['qr'], $arrMatches[1]);
        $this->assertEquals($request->header->attributes['opcode'], bindec($arrMatches[2]));
        $this->assertEquals($request->header->attributes['aa'], $arrMatches[3]);
        $this->assertEquals($request->header->attributes['tc'], $arrMatches[4]);
        $this->assertEquals($request->header->attributes['rd'], $arrMatches[5]);
        $this->assertEquals($request->header->attributes['ra'], $arrMatches[6]);
        $this->assertEquals($request->header->attributes['z'], bindec($arrMatches[7]));
        $this->assertEquals($request->header->attributes['rcode'], bindec($arrMatches[8]));
    }

    public function testExplainHeaderFlagsBinary2()
    {
        $request = new Message();
        $request->header->set('id', 0x7262);
        $request->header->set('qr', 0);
        $request->header->set('tc', 1);
        $request->header->set('rd', 1);
        $request->header->set('z', 0);

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($request);

        list($fields) = array_values(unpack('n', substr($data, 2, 2)));

        $explain = HumanParser::explainHeaderFlagsBinary($fields);

        preg_match('/QR:\s?(\d).+
                     Opcode:\s(\d{4}).+
                     AA:\s(\d).+
                     TC:\s(\d).+
                     RD:\s(\d).+
                     RA:\s(\d).+
                     Z:\s(\d{3}).+
                     RCODE:\s(\d{4})/xms', $explain, $arrMatches);

        $this->assertEquals($request->header->attributes['qr'], $arrMatches[1]);
        $this->assertEquals($request->header->attributes['opcode'], bindec($arrMatches[2]));
        $this->assertEquals($request->header->attributes['aa'], $arrMatches[3]);
        $this->assertEquals($request->header->attributes['tc'], $arrMatches[4]);
        $this->assertEquals($request->header->attributes['rd'], $arrMatches[5]);
        $this->assertEquals($request->header->attributes['ra'], $arrMatches[6]);
        $this->assertEquals($request->header->attributes['z'], bindec($arrMatches[7]));
        $this->assertEquals($request->header->attributes['rcode'], bindec($arrMatches[8]));
    }

    public function testQueryExplain()
    {
        $query = new Query('www.domain.com', Message::TYPE_A, Message::CLASS_IN, time());
        $explain = $query->explain();

        $this->assertRegExp('/www\.domain\.com\..+IN.+A/', $explain);
    }

    public function testQueryExplain2()
    {
        $query = new Query('domain.com', Message::TYPE_PTR, Message::CLASS_IN, time());
        $explain = $query->explain();

        $this->assertRegExp('/domain\.com\..+IN.+PTR/', $explain);
    }

    public function testRecordExplain()
    {
        $record = new Record('domain.com', Message::TYPE_A, Message::CLASS_IN, 450, '192.168.1.1');
        $explain = $record->explain();

        $this->assertRegExp('/domain\.com\..+450.+IN.+A.+192\.168\.1\.1/', $explain);
    }

    public function testRecordExplain2()
    {
        $record = new Record('domain.com', Message::TYPE_TXT, Message::CLASS_IN, 1981, 'v=spf1 include:_spf.google.com ~all');
        $explain = $record->explain();

        $this->assertRegExp('/domain\.com\..+1981.+IN.+TXT.+"v=spf1 include:_spf\.google\.com ~all"/', $explain);
    }

    public function testRecordExplain3()
    {
        $record = new Record('domain.com', Message::TYPE_MX, Message::CLASS_IN, 10981, 'mail.domain.com', 10);
        $explain = $record->explain();

        $this->assertRegExp('/domain\.com\..+10981.+IN.+MX.+10.+mail\.domain\.com/', $explain);
    }

    public function testMessageExplain()
    {
        $message = new Message();
        $message->header->set('id', 0x7262);
        $message->header->set('qr', 1);
        $message->header->set('rd', 1);
        $message->header->set('ra', 1);
        $message->header->set('qdCount', 1);
        $message->header->set('anCount', 5);
        $message->header->set('nsCount', 1);
        $message->header->set('arCount', 2);

        $explain = $message->explain();

        $match = false;
        if (preg_match('/flags:.+qr.+rd.+ra.+QUERY:.+1.+ANSWER:.+5.+AUTHORITY:.+1.+ADDITIONAL:.+2/', $explain, $arrMatches)) {
            $match = true;
        }

        $this->assertTrue($match);
    }
}