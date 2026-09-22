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

    /**
     * Keeps the held locks alive. A lock that cannot be refreshed is gone — its database connection
     * dropped with a restart, say — and another instance may already hold it, so this copy stops:
     * the server restarts the process, and the new one queues for the lock again. Letting the
     * exception escape the timer used to end the process just the same, with nothing to restart it.
     */
    public function lockRefresh(?int $processPid = null): void
    {
        foreach ($this->locks as $processClass => $lock) {
            try {
                $lock->refresh($this->lockTimeout);
            } catch (\Throwable $exception) {
                $this->logger->error(sprintf('Process lock lost: %s, exception: %s', $processClass, $exception->getMessage()));
                Process::kill($processPid ?? getmypid());

                return;
            }
        }
    }

    /**
     * Run a specific process by class name.
     *
     * The process is server-managed (Server::addProcess): the manager restarts it whenever it
     * exits. So it never exits on purpose — it waits for the lock, runs the job, and parks.
     */
    public function run(int $processPid, string $processClass): void
    {
        // Wait a random startup time 0.5-2
        Coroutine::sleep(mt_rand(500, 2000) / 1000);

        // Standby until the lock is ours. Another instance runs the job while it holds the lock;
        // waiting here makes this copy the failover that takes over once that lock is released.
        while (!$this->lockAcquire($processClass)) {
            $this->logger->info('Process on standby, lock held elsewhere: '.$processClass);
        }

        // Refresh Lock
        go(fn () => Timer::tick(($this->lockTimeout - 5) * 1000, fn () => $this->lockRefresh($processPid)));

        // Run Process
        /** @var AbstractProcessJob $process */
        $process = $this->locator->get($processClass);
        do {
            try {
                $this->logger->info('Process started: '.$processClass);
                $process();
                $this->logger->info('Process finished: '.$processClass);
            } catch (\Throwable $exception) {
                $this->logger->error(sprintf('Process failed: %s, exception: %s', $processClass, $exception->getMessage()));
            }

            if ($process->RESTART) {
                $this->logger->info(sprintf('Process will restart in %d seconds: %s', $process->RESTART_DELAY, $processClass));
                Coroutine::sleep($process->RESTART_DELAY);
            }
        } while ($process->RESTART);

        // Done for good (RESTART=false). Park instead of exiting: an exit would have the manager
        // restart the process and run the job again. The lock stays held, so no other instance
        // runs it either.
        $this->logger->info('Process stopped: '.$processClass);
        while (true) { // @phpstan-ignore-line
            Coroutine::sleep(3600);
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
