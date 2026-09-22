<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Task\TaskHandler;
use Cesurapp\SwooleBundle\Task\TaskWorker;
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

                return count($this->queued);
            }
        };
    }
}
