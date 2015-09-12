<?php

namespace React\Tests\Dns\Model;

use React\Dns\Model\Message;
use React\Dns\Model\Record;

class RecordTest extends \PHPUnit_Framework_TestCase
{
    public function testGetCode()
    {
        $record = new Record('domain.com', Message::TYPE_A, Message::CLASS_IN, 124, '7.7.7.7');
        $this->assertSame('A', $record->getCode());
    }
}