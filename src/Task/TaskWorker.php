<?php

namespace Cesurapp\SwooleBundle\Task;

use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

class TaskWorker
{
    public function __construct(private readonly ServiceLocator $locator, private readonly LoggerInterface $logger, private readonly FailedTaskRepository $failedTaskRepo)
    {
    }

    public function handle(array $taskRequest): void
    {
        // \Exception değil \Throwable: görevin attığı bir TypeError/Error yakalanmazsa
        // task worker'ı 255 ile ölüyor, ardından Swoole "No idle task worker is
        // available" deyip kuyruğu tamamen durduruyor. Tek bozuk görev bütün
        // altyapıyı indirmemeli.
        try {
            $task = $this->getTask($taskRequest);
            $task($this->decodePayload($taskRequest));

            $this->logger->info('Success Task: '.$taskRequest['class'], $taskRequest);
        } catch (\Throwable $exception) {
            $this->failedTaskRepo->createTask($taskRequest, $exception);
            $this->logger->critical('Failed Task: '.$taskRequest['class'].' Exception: '.$exception->getMessage(), $taskRequest);
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
