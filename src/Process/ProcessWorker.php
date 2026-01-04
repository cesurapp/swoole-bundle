<?php

namespace Cesurapp\SwooleBundle\Process;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

readonly class ProcessWorker
{
    public function __construct(private ServiceLocator $locator, private LoggerInterface $logger)
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
            $this->logger->info('Process is disabled: '.$processClass);

            return;
        }

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
