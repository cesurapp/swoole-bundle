<?php

namespace Cesurapp\SwooleBundle\Task;

use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;

/**
 * Hands a task to the Swoole task workers — or runs it in place when it cannot be handed over.
 *
 * Swoole refuses Server::task() inside a task worker, and it returns false instead of queueing
 * when the call is refused (a process outside the server's own set, a server that is not
 * running). The refusal used to be ignored, so the task was silently lost; a task dispatched from
 * within another task (logging an HTTP call a task made, say) never ran. Now such a task runs
 * inline, in the caller's process, through the same TaskWorker::handle() the pool uses — failures
 * still land in the failed-task store and are retried by FailedTaskCron.
 *
 * Swoole's queue lives in memory: a task still queued or running when the server stops is gone.
 * A durable dispatch writes the task to the store first and hands its id along; the row goes when
 * the task succeeds, and FailedTaskCron runs it again if it fails or its worker dies (see
 * FailedTask). It never runs inline outside a task worker — a long task must not take over an
 * HTTP worker or a process — so when it cannot be handed over the row simply waits for the cron.
 */
readonly class TaskHandler
{
    /**
     * @param bool $sync run every task inline (test environment, task_sync_mode)
     */
    public function __construct(private ?TaskWorker $worker = null, private bool $sync = true, private ?FailedTaskRepository $store = null)
    {
    }

    /**
     * @param bool $durable survive a restart: stored until it succeeds, at-least-once
     */
    public function dispatch(TaskInterface|string $task, mixed $payload = null, bool $durable = false): void
    {
        $request = [
            'class' => is_string($task) ? $task : get_class($task),
            'payload' => serialize($payload),
        ];

        // Test|Sync Mode
        if ($this->sync && $this->worker) {
            $this->worker->handle($request);

            return;
        }

        if ($durable) {
            $this->durable($request);

            return;
        }

        if (!isset($GLOBALS['httpServer'])) {
            throw new \RuntimeException('HTTP Server not found!');
        }

        $server = $GLOBALS['httpServer'];

        // A task worker cannot hand a task to the pool: Swoole refuses task() there.
        if ($server->taskworker) {
            $this->inline($request);

            return;
        }

        if (false === $server->task($request)) {
            $this->inline($request);
        }
    }

    private function durable(array $request): void
    {
        if (!$this->store) {
            throw new \LogicException(sprintf('Task "%s" was dispatched durable, but there is no failed-task store to keep it in.', $request['class']));
        }

        // Outside a server (a console command) nobody can take the task now, and inside an open
        // transaction the row is invisible to a worker until it commits: the worker would find
        // nothing to delete and the task would run again later. The row waits for the cron.
        $server = $GLOBALS['httpServer'] ?? null;
        if (!$server || $this->store->inTransaction()) {
            $this->store->insert($request);

            return;
        }

        $request['attempt'] = 1;
        $request['id'] = $this->store->insert($request, 1, new \DateTimeImmutable());

        if ($server->taskworker) {
            $this->inline($request);

            return;
        }

        if (false === $server->task($request)) {
            $this->store->release($request['id'], 1);
        }
    }

    private function inline(array $request): void
    {
        if (!$this->worker) {
            throw new \RuntimeException(sprintf('Task "%s" could not be queued and no task worker is available to run it.', $request['class']));
        }

        $this->worker->handle($request);
    }
}
