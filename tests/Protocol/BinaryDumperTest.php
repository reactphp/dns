<?php

namespace React\Tests\Dns\Protocol;

use React\Dns\Protocol\BinaryDumper;
use React\Dns\Model\Message;
use React\Dns\Query\Query;

class BinaryDumperTest extends \PHPUnit_Framework_TestCase
{
    public function testRequestToBinary()
    {
        $data = "";
        $data .= "72 62 01 00 00 01 00 00 00 00 00 00"; // header
        $data .= "04 69 67 6f 72 02 69 6f 00";          // question: igor.io
        $data .= "00 01 00 01";                         // question: type A, class IN

        $expected = $this->formatHexDump(str_replace(' ', '', $data), 2);

        $request = new Message();
        $request->header->set('id', 0x7262);
        $request->header->set('rd', 1);

        $request->questions[] = new Query(
            'igor.io',
            Message::TYPE_A,
            Message::CLASS_IN,
            NULL
        );

        $request->prepare();

        $dumper = new BinaryDumper();
        $data = $dumper->toBinary($request);
        $data = $this->convertBinaryToHexDump($data);

        $this->assertSame($expected, $data);
    }

    private function convertBinaryToHexDump($input)
    {
        return $this->formatHexDump(implode('', unpack('H*', $input)));
    }

    private function formatHexDump($input)
    {
        return implode(' ', str_split($input, 2));
    }
}
