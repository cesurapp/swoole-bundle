<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;
use Cesurapp\SwooleBundle\Task\TaskBrokerClient;
use Cesurapp\SwooleBundle\Task\TaskHandler;
use Cesurapp\SwooleBundle\Task\TaskWorker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Where a dispatched task goes: to the broker when it takes it, to its queue.log when it cannot be
 * reached — never dropped, never run in the caller.
 */
class TaskHandlerTest extends TestCase
{
    public function testSyncModeRunsInline(): void
    {
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->once())->method('handle')->with(['class' => 'AcmeTask', 'payload' => serialize('data')]);

        new TaskHandler($worker, sync: true)->dispatch('AcmeTask', 'data');
    }

    public function testQueuedTaskDoesNotRunInline(): void
    {
        $broker = $this->broker();
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->never())->method('handle');

        new TaskHandler($worker, sync: false, broker: $broker)->dispatch('AcmeTask', 'data');

        $this->assertSame([['class' => 'AcmeTask', 'payload' => serialize('data')]], $broker->queued);
    }

    /** The broker cannot be reached: the task goes to its queue.log instead of running in the caller. */
    public function testRefusedTaskGoesToTheQueueLog(): void
    {
        $broker = $this->broker(accepts: false);
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->never())->method('handle');

        new TaskHandler($worker, sync: false, broker: $broker)->dispatch('AcmeTask', 'data');

        $this->assertSame([['class' => 'AcmeTask', 'payload' => serialize('data')]], $broker->appended);
    }

    public function testRefusedTaskThatCannotBeWrittenFailsLoudly(): void
    {

        $this->expectException(\RuntimeException::class);
        new TaskHandler(null, sync: false, broker: $this->broker(accepts: false, appends: false))->dispatch('AcmeTask', 'data');
    }

    /** Sync mode has no queue to survive: the task runs on the spot and nothing is stored. */
    public function testSyncModeRunsADurableTaskInlineWithoutARow(): void
    {
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->once())->method('handle')->with(['class' => 'AcmeTask', 'payload' => serialize('data')]);
        $store = $this->createMock(FailedTaskRepository::class);
        $store->expects($this->never())->method('insert');

        new TaskHandler($worker, sync: true, store: $store)->dispatch('AcmeTask', 'data', durable: true);
    }

    /** The row is written before the broker gets the task, and the task carries its id and attempt. */
    public function testDurableTaskIsStoredThenQueuedWithItsId(): void
    {
        $broker = $this->broker();
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->never())->method('handle');
        $store = $this->store();
        $store->expects($this->once())->method('insert')
            ->with(['class' => 'AcmeTask', 'payload' => serialize('data'), 'attempt' => 1], 1, $this->isInstanceOf(\DateTimeImmutable::class))
            ->willReturn('row-1');
        $store->expects($this->never())->method('release');

        new TaskHandler($worker, sync: false, store: $store, broker: $broker)->dispatch('AcmeTask', 'data', durable: true);

        $this->assertSame([['class' => 'AcmeTask', 'payload' => serialize('data'), 'attempt' => 1, 'id' => 'row-1']], $broker->queued);
    }

    /** A durable task never runs inline in the caller: refused, it gives the attempt back and waits. */
    public function testRefusedDurableTaskWaitsForTheCron(): void
    {
        $broker = $this->broker(accepts: false);
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->never())->method('handle');
        $store = $this->store();
        $store->method('insert')->willReturn('row-1');
        $store->expects($this->once())->method('release')->with('row-1', 1);

        new TaskHandler($worker, sync: false, store: $store, broker: $broker)->dispatch('AcmeTask', 'data', durable: true);

        $this->assertSame([], $broker->appended, 'the store keeps it, not queue.log');
    }

    /**
     * Inside an open transaction a worker could not see the row until it commits, so it is left
     * waiting instead of handed over — and a rollback takes it along.
     */
    public function testDurableTaskInATransactionWaitsForTheCron(): void
    {
        $broker = $this->broker();
        $store = $this->store(inTransaction: true);
        $store->expects($this->once())->method('insert')->with(['class' => 'AcmeTask', 'payload' => serialize('data')]);

        new TaskHandler($this->createStub(TaskWorker::class), sync: false, store: $store, broker: $broker)->dispatch('AcmeTask', 'data', durable: true);

        $this->assertSame([], $broker->queued);
    }

    public function testDurableTaskWithoutAStoreFailsLoudly(): void
    {

        $this->expectException(\LogicException::class);
        new TaskHandler($this->createStub(TaskWorker::class), sync: false, broker: $this->broker())->dispatch('AcmeTask', 'data', durable: true);
    }

    private function store(bool $inTransaction = false): FailedTaskRepository&MockObject
    {
        $store = $this->createMock(FailedTaskRepository::class);
        $store->method('inTransaction')->willReturn($inTransaction);

        return $store;
    }

    /**
     * A broker client that records what it is given.
     */
    private function broker(bool $accepts = true, bool $appends = true): TaskBrokerClient
    {
        return new class ($accepts, $appends) extends TaskBrokerClient {
            public array $queued = [];
            public array $appended = [];

            public function __construct(private readonly bool $accepts, private readonly bool $appends)
            {
            }

            public function send(array $request): bool
            {
                if ($this->accepts) {
                    $this->queued[] = $request;
                }

                return $this->accepts;
            }

            public function append(array $request): bool
            {
                if ($this->appends) {
                    $this->appended[] = $request;
                }

                return $this->appends;
            }
        };
    }
}
