<?php

namespace React\Tests\Dns\Resolver;

use React\Dns\Resolver\Resolver;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Promise;
use React\Tests\Dns\TestCase;
use React\Dns\RecordNotFoundException;

class ResolverTest extends TestCase
{
    /** @test */
    public function resolveShouldQueryARecords()
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->isInstanceOf('React\Dns\Query\Query'))
            ->will($this->returnCallback(function ($nameserver, $query) {
                $response = new Message();
                $response->header->set('qr', 1);
                $response->questions[] = new Record($query->name, $query->type, $query->class);
                $response->answers[] = new Record($query->name, $query->type, $query->class, 3600, '178.79.169.131');

                return Promise\resolve($response);
            }));

        $resolver = new Resolver('8.8.8.8:53', $executor);
        $resolver->resolve('igor.io')->then($this->expectCallableOnceWith('178.79.169.131'));
    }

    /** @test */
    public function resolveAllShouldQueryGivenRecords()
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->isInstanceOf('React\Dns\Query\Query'))
            ->will($this->returnCallback(function ($nameserver, $query) {
                $response = new Message();
                $response->header->set('qr', 1);
                $response->questions[] = new Record($query->name, $query->type, $query->class);
                $response->answers[] = new Record($query->name, $query->type, $query->class, 3600, '::1');

                return Promise\resolve($response);
            }));

        $resolver = new Resolver('8.8.8.8:53', $executor);
        $resolver->resolveAll('reactphp.org', Message::TYPE_AAAA)->then($this->expectCallableOnceWith(array('::1')));
    }

    /** @test */
    public function resolveAllShouldIgnoreRecordsWithOtherTypes()
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->isInstanceOf('React\Dns\Query\Query'))
            ->will($this->returnCallback(function ($nameserver, $query) {
                $response = new Message();
                $response->header->set('qr', 1);
                $response->questions[] = new Record($query->name, $query->type, $query->class);
                $response->answers[] = new Record($query->name, Message::TYPE_TXT, $query->class, 3600, array('ignored'));
                $response->answers[] = new Record($query->name, $query->type, $query->class, 3600, '::1');

                return Promise\resolve($response);
            }));

        $resolver = new Resolver('8.8.8.8:53', $executor);
        $resolver->resolveAll('reactphp.org', Message::TYPE_AAAA)->then($this->expectCallableOnceWith(array('::1')));
    }

    /** @test */
    public function resolveAllShouldReturnMultipleValuesForAlias()
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->isInstanceOf('React\Dns\Query\Query'))
            ->will($this->returnCallback(function ($nameserver, $query) {
                $response = new Message();
                $response->header->set('qr', 1);
                $response->questions[] = new Record($query->name, $query->type, $query->class);
                $response->answers[] = new Record($query->name, Message::TYPE_CNAME, $query->class, 3600, 'example.com');
                $response->answers[] = new Record('example.com', $query->type, $query->class, 3600, '::1');
                $response->answers[] = new Record('example.com', $query->type, $query->class, 3600, '::2');
                $response->prepare();

                return Promise\resolve($response);
            }));

        $resolver = new Resolver('8.8.8.8:53', $executor);
        $resolver->resolveAll('reactphp.org', Message::TYPE_AAAA)->then(
            $this->expectCallableOnceWith($this->equalTo(array('::1', '::2')))
        );
    }

    /** @test */
    public function resolveShouldQueryARecordsAndIgnoreCase()
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->isInstanceOf('React\Dns\Query\Query'))
            ->will($this->returnCallback(function ($nameserver, $query) {
                $response = new Message();
                $response->header->set('qr', 1);
                $response->questions[] = new Record('Blog.wyrihaximus.net', $query->type, $query->class);
                $response->answers[] = new Record('Blog.wyrihaximus.net', $query->type, $query->class, 3600, '178.79.169.131');

                return Promise\resolve($response);
            }));

        $resolver = new Resolver('8.8.8.8:53', $executor);
        $resolver->resolve('blog.wyrihaximus.net')->then($this->expectCallableOnceWith('178.79.169.131'));
    }

    /** @test */
    public function resolveShouldFilterByName()
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->isInstanceOf('React\Dns\Query\Query'))
            ->will($this->returnCallback(function ($nameserver, $query) {
                $response = new Message();
                $response->header->set('qr', 1);
                $response->questions[] = new Record($query->name, $query->type, $query->class);
                $response->answers[] = new Record('foo.bar', $query->type, $query->class, 3600, '178.79.169.131');

                return Promise\resolve($response);
            }));

        $errback = $this->expectCallableOnceWith($this->isInstanceOf('React\Dns\RecordNotFoundException'));

        $resolver = new Resolver('8.8.8.8:53', $executor);
        $resolver->resolve('igor.io')->then($this->expectCallableNever(), $errback);
    }

    /**
     * @test
     */
    public function resolveWithNoAnswersShouldCallErrbackIfGiven()
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->isInstanceOf('React\Dns\Query\Query'))
            ->will($this->returnCallback(function ($nameserver, $query) {
                $response = new Message();
                $response->header->set('qr', 1);
                $response->questions[] = new Record($query->name, $query->type, $query->class);

                return Promise\resolve($response);
            }));

        $errback = $this->expectCallableOnceWith($this->callback(function ($param) {
            return ($param instanceof RecordNotFoundException && $param->getCode() === 0 && $param->getMessage() === 'DNS query for igor.io did not return a valid answer (NOERROR / NODATA)');
        }));

        $resolver = new Resolver('8.8.8.8:53', $executor);
        $resolver->resolve('igor.io')->then($this->expectCallableNever(), $errback);
    }

    public function provideRcodeErrors()
    {
        return array(
            array(
                Message::RCODE_FORMAT_ERROR,
                'DNS query for example.com returned an error response (Format Error)',
            ),
            array(
                Message::RCODE_SERVER_FAILURE,
                'DNS query for example.com returned an error response (Server Failure)',
            ),
            array(
                Message::RCODE_NAME_ERROR,
                'DNS query for example.com returned an error response (Non-Existent Domain / NXDOMAIN)'
            ),
            array(
                Message::RCODE_NOT_IMPLEMENTED,
                'DNS query for example.com returned an error response (Not Implemented)'
            ),
            array(
                Message::RCODE_REFUSED,
                'DNS query for example.com returned an error response (Refused)'
            ),
            array(
                99,
                'DNS query for example.com returned an error response (Unknown error response code 99)'
            )
        );
    }

    /**
     * @test
     * @dataProvider provideRcodeErrors
     */
    public function resolveWithRcodeErrorShouldCallErrbackIfGiven($code, $expectedMessage)
    {
        $executor = $this->createExecutorMock();
        $executor
            ->expects($this->once())
            ->method('query')
            ->with($this->anything(), $this->isInstanceOf('React\Dns\Query\Query'))
            ->will($this->returnCallback(function ($nameserver, $query) use ($code) {
                $response = new Message();
                $response->header->set('qr', 1);
                $response->header->set('rcode', $code);
                $response->questions[] = new Record($query->name, $query->type, $query->class);

                return Promise\resolve($response);
            }));

        $errback = $this->expectCallableOnceWith($this->callback(function ($param) use ($code, $expectedMessage) {
            return ($param instanceof RecordNotFoundException && $param->getCode() === $code && $param->getMessage() === $expectedMessage);
        }));

        $resolver = new Resolver('8.8.8.8:53', $executor);
        $resolver->resolve('example.com')->then($this->expectCallableNever(), $errback);
    }

    public function testLegacyExtractAddress()
    {
        $executor = $this->createExecutorMock();
        $resolver = new Resolver('8.8.8.8:53', $executor);

        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $response = Message::createResponseWithAnswersForQuery($query, array(
            new Record('reactphp.org', Message::TYPE_A, Message::CLASS_IN, 3600, '1.2.3.4')
        ));

        $ret = $resolver->extractAddress($query, $response);
        $this->assertEquals('1.2.3.4', $ret);
    }

    private function createExecutorMock()
    {
        return $this->getMockBuilder('React\Dns\Query\ExecutorInterface')->getMock();
    }
}
