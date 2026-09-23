<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;
use Cesurapp\SwooleBundle\Task\TaskHandler;
use Cesurapp\SwooleBundle\Task\TaskWorker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Where a dispatched task runs: queued to the pool when Swoole takes it, inline when it cannot —
 * never dropped.
 */
class TaskHandlerTest extends TestCase
{
    private mixed $previousServer = null;

    protected function setUp(): void
    {
        $this->previousServer = $GLOBALS['httpServer'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->previousServer) {
            unset($GLOBALS['httpServer']);
        } else {
            $GLOBALS['httpServer'] = $this->previousServer;
        }
    }

    public function testSyncModeRunsInline(): void
    {
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->once())->method('handle')->with(['class' => 'AcmeTask', 'payload' => serialize('data')]);

        new TaskHandler($worker, sync: true)->dispatch('AcmeTask', 'data');
    }

    public function testQueuedTaskDoesNotRunInline(): void
    {
        $server = $this->server(taskWorker: false, accepts: true);
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->never())->method('handle');

        new TaskHandler($worker, sync: false)->dispatch('AcmeTask', 'data');

        $this->assertSame([['class' => 'AcmeTask', 'payload' => serialize('data')]], $server->queued);
    }

    /** Swoole refuses task() inside a task worker; the task runs there instead of being lost. */
    public function testInsideATaskWorkerRunsInline(): void
    {
        $server = $this->server(taskWorker: true, accepts: true);
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->once())->method('handle');

        new TaskHandler($worker, sync: false)->dispatch('AcmeTask', 'data');

        $this->assertSame([], $server->queued, 'task() must not even be attempted in a task worker');
    }

    public function testRefusedTaskRunsInline(): void
    {
        $this->server(taskWorker: false, accepts: false);
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->once())->method('handle');

        new TaskHandler($worker, sync: false)->dispatch('AcmeTask', 'data');
    }

    public function testRefusedTaskWithoutWorkerFailsLoudly(): void
    {
        $this->server(taskWorker: false, accepts: false);

        $this->expectException(\RuntimeException::class);
        new TaskHandler(null, sync: false)->dispatch('AcmeTask', 'data');
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

    /**
     * The row is written before the pool gets the task, and the task carries its id and attempt.
     * Swoole numbers tasks from 0, so the first one queued comes back as 0 — still accepted.
     */
    public function testDurableTaskIsStoredThenQueuedWithItsId(): void
    {
        $server = $this->server(taskWorker: false, accepts: true);
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->never())->method('handle');
        $store = $this->store();
        $store->expects($this->once())->method('insert')
            ->with(['class' => 'AcmeTask', 'payload' => serialize('data'), 'attempt' => 1], 1, $this->isInstanceOf(\DateTimeImmutable::class))
            ->willReturn('row-1');
        $store->expects($this->never())->method('release');

        new TaskHandler($worker, sync: false, store: $store)->dispatch('AcmeTask', 'data', durable: true);

        $this->assertSame([['class' => 'AcmeTask', 'payload' => serialize('data'), 'attempt' => 1, 'id' => 'row-1']], $server->queued);
    }

    /** A durable task never runs inline in the caller: refused, it gives the attempt back and waits. */
    public function testRefusedDurableTaskWaitsForTheCron(): void
    {
        $this->server(taskWorker: false, accepts: false);
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->never())->method('handle');
        $store = $this->store();
        $store->method('insert')->willReturn('row-1');
        $store->expects($this->once())->method('release')->with('row-1', 1);

        new TaskHandler($worker, sync: false, store: $store)->dispatch('AcmeTask', 'data', durable: true);
    }

    public function testDurableTaskInsideATaskWorkerRunsInlineWithItsId(): void
    {
        $server = $this->server(taskWorker: true, accepts: true);
        $worker = $this->createMock(TaskWorker::class);
        $worker->expects($this->once())->method('handle')->with(['class' => 'AcmeTask', 'payload' => serialize('data'), 'attempt' => 1, 'id' => 'row-1']);
        $store = $this->store();
        $store->expects($this->once())->method('insert')->willReturn('row-1');

        new TaskHandler($worker, sync: false, store: $store)->dispatch('AcmeTask', 'data', durable: true);

        $this->assertSame([], $server->queued);
    }

    /**
     * Inside an open transaction a worker could not see the row until it commits, so it is left
     * waiting instead of handed over — and a rollback takes it along.
     */
    public function testDurableTaskInATransactionWaitsForTheCron(): void
    {
        $server = $this->server(taskWorker: false, accepts: true);
        $store = $this->store(inTransaction: true);
        $store->expects($this->once())->method('insert')->with(['class' => 'AcmeTask', 'payload' => serialize('data')]);

        new TaskHandler($this->createStub(TaskWorker::class), sync: false, store: $store)->dispatch('AcmeTask', 'data', durable: true);

        $this->assertSame([], $server->queued);
    }

    /** Outside a server (a console command) a durable task is stored for the cron instead of failing. */
    public function testDurableTaskWithoutAServerWaitsForTheCron(): void
    {
        unset($GLOBALS['httpServer']);
        $store = $this->store();
        $store->expects($this->once())->method('insert')->with(['class' => 'AcmeTask', 'payload' => serialize('data')]);

        new TaskHandler($this->createStub(TaskWorker::class), sync: false, store: $store)->dispatch('AcmeTask', 'data', durable: true);
    }

    public function testDurableTaskWithoutAStoreFailsLoudly(): void
    {
        $this->server(taskWorker: false, accepts: true);

        $this->expectException(\LogicException::class);
        new TaskHandler($this->createStub(TaskWorker::class), sync: false)->dispatch('AcmeTask', 'data', durable: true);
    }

    private function store(bool $inTransaction = false): FailedTaskRepository&MockObject
    {
        $store = $this->createMock(FailedTaskRepository::class);
        $store->method('inTransaction')->willReturn($inTransaction);

        return $store;
    }

    private function server(bool $taskWorker, bool $accepts): object
    {
        return $GLOBALS['httpServer'] = new class ($taskWorker, $accepts) {
            public array $queued = [];

            public function __construct(public bool $taskworker, private readonly bool $accepts)
            {
            }

            public function task(array $request): int|false
            {
                if (!$this->accepts) {
                    return false;
                }
                $this->queued[] = $request;

                return count($this->queued) - 1;
            }
        };
    }
}
