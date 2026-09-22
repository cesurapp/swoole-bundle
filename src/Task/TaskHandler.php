<?php

namespace Cesurapp\SwooleBundle\Task;

/**
 * Hands a task to the Swoole task workers — or runs it in place when it cannot be handed over.
 *
 * Swoole refuses Server::task() inside a task worker, and it returns false instead of queueing
 * when the call is refused (a process outside the server's own set, a server that is not
 * running). The refusal used to be ignored, so the task was silently lost; a task dispatched from
 * within another task (logging an HTTP call a task made, say) never ran. Now such a task runs
 * inline, in the caller's process, through the same TaskWorker::handle() the pool uses — failures
 * still land in the failed-task store and are retried by FailedTaskCron.
 */
readonly class TaskHandler
{
    /**
     * @param bool $sync run every task inline (test environment, task_sync_mode)
     */
    public function __construct(private ?TaskWorker $worker = null, private bool $sync = true)
    {
    }

    public function dispatch(TaskInterface|string $task, mixed $payload = null): void
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

    private function inline(array $request): void
    {
        if (!$this->worker) {
            throw new \RuntimeException(sprintf('Task "%s" could not be queued and no task worker is available to run it.', $request['class']));
        }

        $this->worker->handle($request);
    }
}
