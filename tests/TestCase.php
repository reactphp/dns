<?php

namespace React\Tests\Dns;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return MockObject&(callable(): void)&\stdClass
     */
    protected function expectCallableOnce(): MockObject
    {
        /** @var MockObject&(callable(): void)&\stdClass $mock */
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke');

        return $mock;
    }

    /**
     * @param mixed $value
     * @return MockObject&(callable(): void)&\stdClass
     */
    protected function expectCallableOnceWith($value): MockObject
    {
        /** @var MockObject&(callable(): void)&\stdClass $mock */
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with($value);

        return $mock;
    }

    /**
     * @return MockObject&(callable(): void)&\stdClass
     */
    protected function expectCallableNever(): MockObject
    {
        /** @var MockObject&(callable(): void)&\stdClass $mock */
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->never())
            ->method('__invoke');

        return $mock;
    }

    /**
     * @return MockObject&\stdClass
     */
    protected function createCallableMock(): MockObject
    {
        $builder = $this->getMockBuilder(\stdClass::class);
        if (method_exists($builder, 'addMethods')) {
            // PHPUnit 9+
            return $builder->addMethods(['__invoke'])->getMock();
        } else {
            // legacy PHPUnit
            return $builder->setMethods(['__invoke'])->getMock();
        }
    }
}
