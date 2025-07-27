<?php

namespace React\Tests\Dns\Resolver;

use PHPUnit\Framework\MockObject\MockObject;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Resolver\Resolver;
use React\Tests\Dns\TestCase;
use function React\Promise\resolve;

class ResolveAliasesTest extends TestCase
{
    /**
     * @param array<string> $expectedAnswers
     * @param array<Record> $answers
     *
     * @dataProvider provideAliasedAnswers
     */
    public function testResolveAliases(array $expectedAnswers, array $answers, string $name): void
    {
        $message = new Message();
        foreach ($answers as $answer) {
            $message->answers[] = $answer;
        }

        $executor = $this->createExecutorMock();
        $executor->expects($this->once())->method('query')->willReturn(resolve($message));

        $resolver = new Resolver($executor);

        $answers = $resolver->resolveAll($name, Message::TYPE_A);

        $answers->then($this->expectCallableOnceWith($expectedAnswers), null);
    }

    /**
     * @return iterable<array{array<string>, array<Record>, string}>
     */
    public function provideAliasedAnswers(): iterable
    {
        yield [
            ['178.79.169.131'],
            [
                new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'),
            ],
            'igor.io',
        ];
        yield [
            ['178.79.169.131', '178.79.169.132', '178.79.169.133'],
            [
                new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'),
                new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.132'),
                new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.133'),
            ],
            'igor.io',
        ];
        yield [
            ['178.79.169.131'],
            [
                new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'),
                new Record('foo.igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'),
                new Record('bar.igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'),
            ],
            'igor.io',
        ];
        yield [
            ['178.79.169.131'],
            [
                new Record('igor.io', Message::TYPE_CNAME, Message::CLASS_IN, 3600, 'foo.igor.io'),
                new Record('foo.igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'),
            ],
            'igor.io',
        ];
        yield [
            ['178.79.169.131'],
            [
                new Record('igor.io', Message::TYPE_CNAME, Message::CLASS_IN, 3600, 'foo.igor.io'),
                new Record('foo.igor.io', Message::TYPE_CNAME, Message::CLASS_IN, 3600, 'bar.igor.io'),
                new Record('bar.igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'),
            ],
            'igor.io',
        ];
        yield [
            ['178.79.169.131', '178.79.169.132', '178.79.169.133'],
            [
                new Record('igor.io', Message::TYPE_CNAME, Message::CLASS_IN, 3600, 'foo.igor.io'),
                new Record('foo.igor.io', Message::TYPE_CNAME, Message::CLASS_IN, 3600, 'bar.igor.io'),
                new Record('bar.igor.io', Message::TYPE_CNAME, Message::CLASS_IN, 3600, 'baz.igor.io'),
                new Record('bar.igor.io', Message::TYPE_CNAME, Message::CLASS_IN, 3600, 'qux.igor.io'),
                new Record('baz.igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131'),
                new Record('baz.igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.132'),
                new Record('qux.igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.133'),
            ],
            'igor.io',
        ];
    }

    /**
     * @return ExecutorInterface&MockObject
     */
    private function createExecutorMock()
    {
        return $this->createMock(ExecutorInterface::class);
    }
}
