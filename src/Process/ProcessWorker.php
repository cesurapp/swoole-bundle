<?php

namespace Cesurapp\SwooleBundle\Process;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class ProcessWorker
{
    /** Seconds between two checks of the held lock. */
    private const int LOCK_CHECK = 10;

    /**
     * Seconds the lock lasts past its last refresh, with a store whose locks expire (Redis, a
     * database table). The refresh comes every LOCK_CHECK seconds, but only when the job lets the
     * process breathe: a job that holds it up longer (a long pdo_pgsql query, say) lets the lock
     * expire, and another instance's copy starts the job beside this one. A PostgreSQL advisory
     * lock never expires; it drops with its connection.
     */
    private int $lockTimeout = 60;

    /** @var array<SharedLockInterface> */
    private array $locks = [];

    public function __construct(private readonly ServiceLocator $locator, private readonly LoggerInterface $logger, private readonly LockFactory $lockFactory)
    {
    }

    /**
     * Takes the job's lock if it is free, without waiting.
     */
    public function lockAcquire(string $processClass): bool
    {
        $lock = $this->lockFactory->createLock('process_server_'.$processClass, $this->lockTimeout);
        if (!$lock->acquire()) {
            return false;
        }

        $this->locks[$processClass] = $lock;

        return true;
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
     * Lets the held locks go, so the next copy (after a restart, or on another instance) takes over
     * at once instead of waiting for them to expire.
     */
    public function lockRelease(): void
    {
        foreach ($this->locks as $processClass => $lock) {
            try {
                $lock->release();
            } catch (\Throwable $exception) {
                $this->logger->warning(sprintf('Process lock release failed: %s, exception: %s', $processClass, $exception->getMessage()));
            }
        }

        $this->locks = [];
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
        for ($attempts = 0; !$this->lockAcquire($processClass); ++$attempts) {
            if (0 === $attempts % 12) {
                $this->logger->info('Process on standby, lock held elsewhere: '.$processClass);
            }

            Coroutine::sleep(5);
        }

        // Refresh Lock
        go(fn () => Timer::tick(self::LOCK_CHECK * 1000, fn () => $this->lockRefresh($processPid)));

        // A stop (the server's, or a lost lock's) lets the lock go before the process ends.
        Process::signal(SIGTERM, fn () => $this->stop());

        // Taken over from another copy, which may not have let go but lost its database session (a
        // restart, say) and runs on until its next check: give it the time to notice and stop.
        if ($attempts > 0) {
            $this->logger->info(sprintf('Process took the lock over, starting in %d seconds: %s', self::LOCK_CHECK + 5, $processClass));
            Coroutine::sleep(self::LOCK_CHECK + 5);
        }

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
     * Ends the process on SIGTERM, with its locks released.
     */
    private function stop(): void
    {
        $this->lockRelease();

        // The job's coroutine is still asleep: end the loop without Swoole reporting a deadlock.
        Coroutine::set(['enable_deadlock_check' => false]);
        Timer::clearAll();
        Event::exit();
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
