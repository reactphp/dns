<?php

namespace Query;

use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\FallbackExecutor;
use React\Dns\Query\Query;
use React\Dns\Query\SearchingExecutor;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Tests\Dns\TestCase;
use function React\Promise\reject;
use function React\Promise\resolve;

class SearchingExecutorTest extends TestCase
{
    public function testQueryWillStripOffEndingPeriodAndNotTryToSearch()
    {
        $fqdnQuery = new Query('reactphp.org.', Message::TYPE_A, Message::CLASS_IN);
        $normalizedQuery = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())->method('query')->with($normalizedQuery)->willReturn(resolve(new Message()));

        $seeker = new SearchingExecutor($executor, 5, 'svc');

        $promise = $seeker->query($fqdnQuery);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableOnceWith($this->isInstanceOf(Message::class)), $this->expectCallableNever());
    }

    public function testQueryWillAttemptToSearchAndReturnOnFirstResponseWithTypeAndCLassMatchingRecords()
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $searchInSvcQuery = new Query('reactphp.org.svc', Message::TYPE_A, Message::CLASS_IN);

        $message = new Message();
        $message->answers[] = new Record($searchInSvcQuery->name, $searchInSvcQuery->type, $searchInSvcQuery->class, 13, '127.0.0.1');

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())->method('query')->with($searchInSvcQuery)->willReturn(resolve($message));

        $seeker = new SearchingExecutor($executor, 5, 'svc');

        $promise = $seeker->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableOnceWith($this->identicalTo($message)), $this->expectCallableNever());
    }

    public function testQueryWillAttemptToSearchAndReturnOnTheSecondResponseWithTypeAndCLassMatchingRecordsBecauseTheFirstIsADifferentType()
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $searchInSvcQuery = new Query('reactphp.org.svc', Message::TYPE_A, Message::CLASS_IN);
        $searchInSvcClusterQuery = new Query('reactphp.org.svc.cluster', Message::TYPE_A, Message::CLASS_IN);

        $messageSvc = new Message();
        $messageSvc->answers[] = new Record($searchInSvcQuery->name, Message::TYPE_AAAA, $searchInSvcQuery->class, 13, '::1');

        $messageSvcCluster = new Message();
        $messageSvcCluster->answers[] = new Record($searchInSvcClusterQuery->name, $searchInSvcClusterQuery->type, $searchInSvcClusterQuery->class, 13, '127.0.0.1');

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->at(0))->method('query')->with($this->equalTo($searchInSvcQuery))->willReturn(resolve($messageSvc));
        $executor->expects($this->at(1))->method('query')->with($this->equalTo($searchInSvcClusterQuery))->willReturn(resolve($messageSvcCluster));

        $seeker = new SearchingExecutor($executor, 5, 'svc', 'svc.cluster');

        $promise = $seeker->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableOnceWith($this->identicalTo($messageSvcCluster)), $this->expectCallableNever());
    }

    public function testQueryWillAttemptToSearchAndReturnOnTheThirdResponseWithTypeAndCLassMatchingRecordsBecauseTheFirstIsADifferentTypeAndTheSecondIsEmpty()
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $searchInSvcQuery = new Query('reactphp.org.svc', Message::TYPE_A, Message::CLASS_IN);
        $searchInSvcClusterQuery = new Query('reactphp.org.svc.cluster', Message::TYPE_A, Message::CLASS_IN);
        $searchInSvcClusterLocalQuery = new Query('reactphp.org.svc.cluster.local', Message::TYPE_A, Message::CLASS_IN);

        $messageSvc = new Message();
        $messageSvc->answers[] = new Record($searchInSvcQuery->name, Message::TYPE_AAAA, $searchInSvcQuery->class, 13, '::1');

        $messageSvcCluster = new Message();

        $messageSvcClusterLocal = new Message();
        $messageSvcClusterLocal->answers[] = new Record($searchInSvcClusterLocalQuery->name, $searchInSvcClusterLocalQuery->type, $searchInSvcClusterLocalQuery->class, 13, '127.0.0.1');

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->at(0))->method('query')->with($this->equalTo($searchInSvcQuery))->willReturn(resolve($messageSvc));
        $executor->expects($this->at(1))->method('query')->with($this->equalTo($searchInSvcClusterQuery))->willReturn(resolve($messageSvcCluster));
        $executor->expects($this->at(2))->method('query')->with($this->equalTo($searchInSvcClusterLocalQuery))->willReturn(resolve($messageSvcClusterLocal));

        $seeker = new SearchingExecutor($executor, 5, 'svc', 'svc.cluster', 'svc.cluster.local');

        $promise = $seeker->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableOnceWith($this->identicalTo($messageSvcClusterLocal)), $this->expectCallableNever());
    }

    public function testQueryWillAttemptToSearchAndReturnOnTheOrignalDomainAsTheSearchesDidntMatchAnyRecordsUpstream()
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $searchInSvcQuery = new Query('reactphp.org.svc', Message::TYPE_A, Message::CLASS_IN);
        $searchInSvcClusterQuery = new Query('reactphp.org.svc.cluster', Message::TYPE_A, Message::CLASS_IN);
        $searchInSvcClusterLocalQuery = new Query('reactphp.org.svc.cluster.local', Message::TYPE_A, Message::CLASS_IN);

        $message = new Message();
        $message->answers[] = new Record($query->name, $query->type, $query->class, 13, '::1');

        $messageSvc = new Message();
        $messageSvc->answers[] = new Record($searchInSvcQuery->name, Message::TYPE_AAAA, $searchInSvcQuery->class, 13, '::1');

        $messageSvcCluster = new Message();

        $messageSvcClusterLocal = new Message();

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->at(0))->method('query')->with($this->equalTo($searchInSvcQuery))->willReturn(resolve($messageSvc));
        $executor->expects($this->at(1))->method('query')->with($this->equalTo($searchInSvcClusterQuery))->willReturn(resolve($messageSvcCluster));
        $executor->expects($this->at(2))->method('query')->with($this->equalTo($searchInSvcClusterLocalQuery))->willReturn(resolve($messageSvcClusterLocal));
        $executor->expects($this->at(3))->method('query')->with($this->equalTo($query))->willReturn(resolve($message));

        $seeker = new SearchingExecutor($executor, 5, 'svc', 'svc.cluster', 'svc.cluster.local');

        $promise = $seeker->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableOnceWith($this->identicalTo($message)), $this->expectCallableNever());
    }

    public function testQueryWillAttemptToSearchAndReturnOnTheFirstResponseAndNeverTriesToQueryTheOtherDomainsOnTheList()
    {
        $query = new Query('reactphp.org', Message::TYPE_A, Message::CLASS_IN);
        $searchInSvcQuery = new Query('reactphp.org.svc', Message::TYPE_A, Message::CLASS_IN);

        $messageSvc = new Message();
        $messageSvc->answers[] = new Record($searchInSvcQuery->name, $searchInSvcQuery->type, $searchInSvcQuery->class, 13, '::1');

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())->method('query')->with($this->equalTo($searchInSvcQuery))->willReturn(resolve($messageSvc));

        $seeker = new SearchingExecutor($executor, 5, 'svc', 'svc.cluster', 'svc.cluster.local');

        $promise = $seeker->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableOnceWith($this->identicalTo($messageSvc)), $this->expectCallableNever());
    }

    public function testWhenDotsInQueryEqualThenMakeAbsoluteQuery()
    {
        $query = new Query('www.reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $message = new Message();
        $message->answers[] = new Record($query->name, $query->type, $query->class, 13, '::1');

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())->method('query')->with($query)->willReturn(resolve($message));

        $seeker = new SearchingExecutor($executor, 2, 'svc');

        $promise = $seeker->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableOnceWith($this->isInstanceOf(Message::class)), $this->expectCallableNever());
    }

    public function testWhenDotsInQueryGreaterThanThenMakeAbsoluteQuery()
    {
        $query = new Query('www.reactphp.org', Message::TYPE_A, Message::CLASS_IN);

        $message = new Message();
        $message->answers[] = new Record($query->name, $query->type, $query->class, 13, '::1');

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->expects($this->once())->method('query')->with($query)->willReturn(resolve($message));

        $seeker = new SearchingExecutor($executor, 1, 'svc');

        $promise = $seeker->query($query);

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableOnceWith($this->isInstanceOf(Message::class)), $this->expectCallableNever());
    }
}
