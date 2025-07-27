<?php

namespace React\Tests\Dns\Resolver;

use PHPUnit\Framework\MockObject\MockObject;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\RecordNotFoundException;
use React\Dns\Resolver\Resolver;
use React\Promise\PromiseInterface;
use React\Tests\Dns\TestCase;
use function React\Promise\resolve;

class ResolverTest extends TestCase
{
    /** @test */
    public function resolveShouldQueryARecords(): void
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(function ($query) {
                $response = new Message();
                $response->qr = true;
                $response->questions[] = new Query($query->name, $query->type, $query->class);
                $response->answers[] = new Record($query->name, $query->type, $query->class, 3600, '178.79.169.131');

                return resolve($response);
            }));

        $resolver = new Resolver($executor);
        $resolver->resolve('igor.io')->then($this->expectCallableOnceWith('178.79.169.131'));
    }

    /** @test */
    public function resolveAllShouldQueryGivenRecords(): void
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(function ($query) {
                $response = new Message();
                $response->qr = true;
                $response->questions[] = new Query($query->name, $query->type, $query->class);
                $response->answers[] = new Record($query->name, $query->type, $query->class, 3600, '::1');

                return resolve($response);
            }));

        $resolver = new Resolver($executor);
        $resolver->resolveAll('reactphp.org', Message::TYPE_AAAA)->then($this->expectCallableOnceWith(['::1']));
    }

    /** @test */
    public function resolveAllShouldIgnoreRecordsWithOtherTypes(): void
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(function ($query) {
                $response = new Message();
                $response->qr = true;
                $response->questions[] = new Query($query->name, $query->type, $query->class);
                $response->answers[] = new Record($query->name, Message::TYPE_TXT, $query->class, 3600, ['ignored']);
                $response->answers[] = new Record($query->name, $query->type, $query->class, 3600, '::1');

                return resolve($response);
            }));

        $resolver = new Resolver($executor);
        $resolver->resolveAll('reactphp.org', Message::TYPE_AAAA)->then($this->expectCallableOnceWith(['::1']));
    }

    /** @test */
    public function resolveAllShouldReturnMultipleValuesForAlias(): void
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(function ($query) {
                $response = new Message();
                $response->qr = true;
                $response->questions[] = new Query($query->name, $query->type, $query->class);
                $response->answers[] = new Record($query->name, Message::TYPE_CNAME, $query->class, 3600, 'example.com');
                $response->answers[] = new Record('example.com', $query->type, $query->class, 3600, '::1');
                $response->answers[] = new Record('example.com', $query->type, $query->class, 3600, '::2');

                return resolve($response);
            }));

        $resolver = new Resolver($executor);
        $resolver->resolveAll('reactphp.org', Message::TYPE_AAAA)->then(
            $this->expectCallableOnceWith($this->equalTo(['::1', '::2']))
        );
    }

    /** @test */
    public function resolveShouldQueryARecordsAndIgnoreCase(): void
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(function ($query) {
                $response = new Message();
                $response->qr = true;
                $response->questions[] = new Query('Blog.wyrihaximus.net', $query->type, $query->class);
                $response->answers[] = new Record('Blog.wyrihaximus.net', $query->type, $query->class, 3600, '178.79.169.131');

                return resolve($response);
            }));

        $resolver = new Resolver($executor);
        $resolver->resolve('blog.wyrihaximus.net')->then($this->expectCallableOnceWith('178.79.169.131'));
    }

    /** @test */
    public function resolveShouldFilterByName(): void
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(function ($query) {
                $response = new Message();
                $response->qr = true;
                $response->questions[] = new Query($query->name, $query->type, $query->class);
                $response->answers[] = new Record('foo.bar', $query->type, $query->class, 3600, '178.79.169.131');

                return resolve($response);
            }));

        $errback = $this->expectCallableOnceWith($this->isInstanceOf(RecordNotFoundException::class));

        $resolver = new Resolver($executor);
        $resolver->resolve('igor.io')->then($this->expectCallableNever(), $errback);
    }

    /**
     * @test
     */
    public function resolveWithNoAnswersShouldCallErrbackIfGiven(): void
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(function ($query) {
                $response = new Message();
                $response->qr = true;
                $response->questions[] = new Query($query->name, $query->type, $query->class);

                return resolve($response);
            }));

        $errback = $this->expectCallableOnceWith($this->callback(function ($param) {
            return ($param instanceof RecordNotFoundException && $param->getCode() === 0 && $param->getMessage() === 'DNS query for igor.io (A) did not return a valid answer (NOERROR / NODATA)');
        }));

        $resolver = new Resolver($executor);
        $resolver->resolve('igor.io')->then($this->expectCallableNever(), $errback);
    }

    /**
     * @return iterable<array{int, string}>
     */
    public function provideRcodeErrors(): iterable
    {
        yield  [
            Message::RCODE_FORMAT_ERROR,
            'DNS query for example.com (A) returned an error response (Format Error)',
        ];
        yield [
            Message::RCODE_SERVER_FAILURE,
            'DNS query for example.com (A) returned an error response (Server Failure)',
        ];
        yield [
            Message::RCODE_NAME_ERROR,
            'DNS query for example.com (A) returned an error response (Non-Existent Domain / NXDOMAIN)'
        ];
        yield [
            Message::RCODE_NOT_IMPLEMENTED,
            'DNS query for example.com (A) returned an error response (Not Implemented)'
        ];
        yield [
            Message::RCODE_REFUSED,
            'DNS query for example.com (A) returned an error response (Refused)'
        ];
        yield [
            99,
            'DNS query for example.com (A) returned an error response (Unknown error response code 99)'
        ];
    }

    /**
     * @test
     * @dataProvider provideRcodeErrors
     */
    public function resolveWithRcodeErrorShouldCallErrbackIfGiven(int $code, string $expectedMessage): void
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->isInstanceOf(Query::class))
            ->will($this->returnCallback(static function (Query $query) use ($code): PromiseInterface {
                $response = new Message();
                $response->qr = true;
                $response->rcode = $code;
                $response->questions[] = new Query($query->name, $query->type, $query->class);

                return resolve($response);
            }));

        $errback = $this->expectCallableOnceWith($this->callback(function ($param) use ($code, $expectedMessage): bool {
            return ($param instanceof RecordNotFoundException && $param->getCode() === $code && $param->getMessage() === $expectedMessage);
        }));

        $resolver = new Resolver($executor);
        $resolver->resolve('example.com')->then($this->expectCallableNever(), $errback);
    }

    /**
     * @return ExecutorInterface&MockObject
     */
    private function createExecutorMock()
    {
        return $this->createMock(ExecutorInterface::class);
    }
}
