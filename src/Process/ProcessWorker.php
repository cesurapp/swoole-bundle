<?php

namespace Cesurapp\SwooleBundle\Process;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Process;
use Swoole\Timer;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class ProcessWorker
{
    private int $lockTimeout = 15;

    /** @var array<SharedLockInterface> */
    private array $locks = [];

    public function __construct(private readonly ServiceLocator $locator, private readonly LoggerInterface $logger, private readonly LockFactory $lockFactory)
    {
    }

    public function lockAcquire(string $processClass): bool
    {
        $lock = $this->lockFactory->createLock('process_server_'.$processClass, $this->lockTimeout);
        $retryTimeout = $this->lockTimeout + 5;
        while ($retryTimeout > 0) {
            if ($lock->acquire()) {
                $this->locks[$processClass] = $lock;

                return true;
            }

            $retryTimeout -= 5;
            Coroutine::sleep(5);
        }

        return false;
    }

    public function lockRefresh(): void
    {
        foreach ($this->locks as $lock) {
            $lock->refresh($this->lockTimeout);
        }
    }

    /**
     * Run a specific process by class name.
     */
    public function run(int $processPid, string $processClass): void
    {
        // Wait a random startup time 0.5-2
        Coroutine::sleep(mt_rand(500, 2000) / 1000);

        // Get Lock
        if (!$this->lockAcquire($processClass)) {
            Process::kill($processPid);

            return;
        }

        // Refresh Lock
        go(fn () => Timer::tick(($this->lockTimeout - 5) * 1000, [$this, 'lockRefresh']));

        // Run Process
        /** @var AbstractProcessJob $process */
        $process = $this->locator->get($processClass);
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
                Coroutine::sleep($process->RESTART_DELAY);
            }
        } while ($process->RESTART);

        $this->logger->info('Process stopped: '.$processClass);
        Process::kill($processPid);
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
