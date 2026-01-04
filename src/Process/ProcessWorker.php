<?php

namespace Cesurapp\SwooleBundle\Process;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;

readonly class ProcessWorker
{
    public function __construct(private ServiceLocator $locator, private LoggerInterface $logger, private LockFactory $lockFactory)
    {
    }

    /**
     * Run a specific process by class name.
     */
    public function run(string $processClass): void
    {
        if (!$this->locator->has($processClass)) {
            $this->logger->error('Process not found: '.$processClass);

            return;
        }

        /** @var AbstractProcessJob $process */
        $process = $this->locator->get($processClass);
        if (!$process->ENABLE) {
            return;
        }

        $lock = $this->lockFactory->createLock('process_server_'.$processClass, 3600);
        if (!$lock->acquire()) {
            return;
        }

        try {
            do {
                try {
                    $this->logger->info('Process started: '.$processClass);
                    $process();
                    $this->logger->info('Process finished: '.$processClass);
                } catch (\Exception $exception) {
                    $this->logger->error(sprintf('Process failed: %s, exception: %s', $processClass, $exception->getMessage()));
                }

                if ($process->RESTART) {
                    $this->logger->info(sprintf('Process will restart in %d seconds: %s', $process->RESTART_DELAY, $processClass));
                    sleep($process->RESTART_DELAY);
                }
            } while ($process->RESTART);
        } finally {
            $lock->release();
        }
    }

    /**
     * Get a process instance.
     */
    public function get(string $class): ?AbstractProcessJob
    {
        if ($this->locator->has($class)) {
            return $this->locator->get($class);
        }

        return null;
    }

    /**
     * Get all registered processes.
     */
    public function getAll(): \Traversable
    {
        foreach ($this->locator->getProvidedServices() as $processClass => $value) {
            yield $this->get($processClass);
        }

        return null;
    }
}
