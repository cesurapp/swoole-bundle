<?php

namespace Cesurapp\SwooleBundle\Task;

use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

class TaskWorker
{
    /**
     * @param list<int> $retry seconds before each retry (`task_retry`)
     */
    public function __construct(
        private readonly ServiceLocator $locator,
        private readonly LoggerInterface $logger,
        private readonly FailedTaskRepository $failedTaskRepo,
        private readonly array $retry = [600],
    ) {
    }

    /**
     * Runs a task. A request with an `id` is a stored one (a durable task, or a retry): its row
     * goes on success and records the failure otherwise, fenced by the request's `attempt`.
     */
    public function handle(array $taskRequest): void
    {
        $id = $taskRequest['id'] ?? null;

        // \Exception değil \Throwable: görevin attığı bir TypeError/Error yakalanmazsa
        // task worker'ı 255 ile ölüyor, ardından Swoole "No idle task worker is
        // available" deyip kuyruğu tamamen durduruyor. Tek bozuk görev bütün
        // altyapıyı indirmemeli.
        try {
            $task = $this->getTask($taskRequest);
            $task($this->decodePayload($taskRequest));
        } catch (\Throwable $exception) {
            // A request without an id is the task's first run.
            $attempt = null === $id ? 1 : (int) ($taskRequest['attempt'] ?? 0);
            $retryAt = $this->retryAt($attempt);

            $this->store($taskRequest, fn () => null === $id
                ? $this->failedTaskRepo->createTask($taskRequest, $exception, $retryAt)
                : $this->failedTaskRepo->fail($id, $attempt, $exception, $retryAt));
            $this->logger->critical('Failed Task: '.$taskRequest['class'].' Exception: '.$exception->getMessage(), $taskRequest);

            return;
        }

        if (null !== $id) {
            $this->store($taskRequest, fn () => $this->failedTaskRepo->complete($id));
        }
        $this->logger->info('Success Task: '.$taskRequest['class'], $taskRequest);
    }

    /**
     * When the task may run again after its $attempt-th run failed: `task_retry` holds one delay
     * per retry. Null once they are used up — the row then rests in the failed list.
     */
    private function retryAt(int $attempt): ?\DateTimeImmutable
    {
        $delay = $this->retry[$attempt - 1] ?? null;

        return null === $delay ? null : new \DateTimeImmutable("+$delay seconds");
    }

    /**
     * A store write never escapes: this runs in the task worker's own callback. When it fails, a
     * stored task keeps its lease and FailedTaskCron runs it again once that ends (at-least-once);
     * an unstored task's failure is left to this log line.
     */
    private function store(array $taskRequest, \Closure $write): void
    {
        try {
            $write();
        } catch (\Throwable $exception) {
            $this->logger->critical('Task store write failed: '.($taskRequest['class'] ?? '?').' Exception: '.$exception->getMessage(), $taskRequest);
        }
    }

    /**
     * Payload'ı çöz; bozuksa göreve hiç girmeden anlaşılır bir hatayla dur.
     *
     * unserialize() başarısızlıkta uyarı basıp false dönüyor. O false göreve geçerse
     * hata, görev gövdesinin derinlerinde "Cannot access offset of type string on
     * string" gibi alakasız bir tip hatasına dönüşüyor ve asıl sebep — bozuk kayıt —
     * kayboluyor.
     *
     * @param array{class: ?string, payload: mixed} $taskRequest
     */
    private function decodePayload(array $taskRequest): mixed
    {
        $payload = $taskRequest['payload'];
        if (!is_string($payload)) {
            throw new \UnexpectedValueException(sprintf('Task payload must be a serialized string, %s given.', get_debug_type($payload)));
        }

        // serialize(false) da 'b:0;' üretiyor: geçerli bir payload, hata değil.
        $data = @unserialize($payload);
        if (false === $data && 'b:0;' !== $payload) {
            throw new \UnexpectedValueException(sprintf('Task payload could not be unserialized (%d bytes); the stored record is corrupt.', strlen($payload)));
        }

        return $data;
    }

    /**
     * @param array{class: ?string, payload: mixed} $taskRequest $taskRequest
     */
    public function getTask(array $taskRequest): TaskInterface
    {
        if (!isset($taskRequest['class'], $taskRequest['payload']) || !$this->locator->has($taskRequest['class'])) {
            throw new TaskNotFoundException();
        }

        return $this->locator->get($taskRequest['class']);
    }

    /**
     * Get All Tasks.
     */
    public function getAll(): \Traversable
    {
        foreach ($this->locator->getProvidedServices() as $id => $val) {
            yield $this->locator->get($id);
        }

        return null;
    }
}
