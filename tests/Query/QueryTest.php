<?php

namespace React\Tests\Dns\Model;

use React\Dns\Model\Message;
use React\Dns\Query\Query;

class QueryTest extends \PHPUnit_Framework_TestCase
{
    public function testGetCode()
    {
        $query = new Query('domain.com', Message::TYPE_NS, Message::CLASS_IN, time());
        $this->assertSame('NS', $query->getCode());
    }
}