<?php

namespace Cesurapp\SwooleBundle\Task;

use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;

/**
 * Hands a task to the task broker, which deals it out to the task executors (TaskBroker) — or runs
 * it in place in sync mode.
 *
 * Handing over never waits: the broker takes the job and the caller goes on. When the broker cannot
 * be reached (it is restarting, say), the task is appended to the broker's queue.log instead, where
 * the broker picks it up; running it here would hold up an HTTP request.
 *
 * The broker's queue survives a restart, but a task that dies with its executor is gone. A durable
 * dispatch writes the task to the store first and hands its id along; the row goes when the task
 * succeeds, and FailedTaskCron runs it again if it fails or its executor dies (see FailedTask). When
 * it cannot be handed over the row simply waits for the cron.
 */
readonly class TaskHandler
{
    /**
     * @param bool $sync run every task inline (test environment, task_sync_mode)
     */
    public function __construct(private ?TaskWorker $worker = null, private bool $sync = true, private ?FailedTaskRepository $store = null, private ?TaskBrokerClient $broker = null)
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

        if (!$this->broker()->send($request) && !$this->broker()->append($request)) {
            throw new \RuntimeException(sprintf('Task "%s" could not be queued: the task broker cannot be reached and its queue.log cannot be written.', $request['class']));
        }
    }

    private function durable(array $request): void
    {
        if (!$this->store) {
            throw new \LogicException(sprintf('Task "%s" was dispatched durable, but there is no failed-task store to keep it in.', $request['class']));
        }

        // Inside an open transaction the row is invisible to a worker until it commits: the worker
        // would find nothing to delete and the task would run again later. The row waits for the cron.
        if ($this->store->inTransaction()) {
            $this->store->insert($request);

            return;
        }

        $request['attempt'] = 1;
        $request['id'] = $this->store->insert($request, 1, new \DateTimeImmutable());

        if (!$this->broker()->send($request)) {
            $this->store->release($request['id'], 1);
        }
    }

    private function broker(): TaskBrokerClient
    {
        return $this->broker ?? throw new \LogicException('No task broker client to queue tasks with.');
    }
}
