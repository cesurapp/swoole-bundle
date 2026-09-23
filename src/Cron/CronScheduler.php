<?php

namespace Cesurapp\SwooleBundle\Cron;

use Psr\Log\LoggerInterface;
use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;

/**
 * Starts the cron jobs on time, each run in a process of its own.
 *
 * The scheduler is a single server-managed process (CronServer) that only keeps time: when a job
 * is due it forks a child for that run and goes back to waiting. A job that blocks — a long
 * pdo_pgsql query, say — holds up nothing but its own child, so the other jobs still start on
 * time. A child lives as long as its run and opens its own connections.
 *
 * The scheduler runs outside a coroutine, as Swoole refuses to fork inside one; each child runs its
 * job in a coroutine like any worker. The scheduler must not open a connection (database, Redis,
 * lock store): a fork would hand it to the child as well.
 */
class CronScheduler
{
    /** Seconds a run has to end after SIGTERM, before SIGKILL. */
    private const int KILL_GRACE = 10;

    /** @var array<string, AbstractCronJob>|null the enabled jobs by class, read on the first tick */
    private ?array $jobs = null;

    /** @var array<string, \DateTimeImmutable> each job's next planned run */
    private array $plans = [];

    /** @var array<int, array{class: string, started: float, timeout: int, stopped: ?float}> runs in progress by pid */
    private array $runs = [];

    private bool $stopping = false;

    public function __construct(private readonly CronWorker $worker, private readonly LoggerInterface $logger)
    {
    }

    /**
     * Keeps time until the process is told to stop, then stops the runs in progress: a run left
     * behind would go on with the old code after a deploy, holding the server's ports.
     */
    public function run(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stopping = true);
        pcntl_signal(SIGINT, fn () => $this->stopping = true);

        while (!$this->stopping) {
            $this->tick();
            usleep((int) ($this->pause() * 1000000));
        }

        $this->stopRuns();
    }

    /**
     * Collects the finished runs, stops the overdue ones and starts the jobs that are due.
     *
     * A job's next run is planned from now, not from the run it replaces: a scheduler that fell
     * behind runs a job once, not once for every minute it missed. A job whose previous run is still
     * going is not started again; that minute passes.
     */
    public function tick(?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $jobs = $this->jobs ??= $this->jobs($now);

        $this->reap();
        $this->stopOverdue();

        foreach ($this->plans as $class => $at) {
            if ($at > $now) {
                continue;
            }

            $this->plans[$class] = $this->worker->nextRunDate($jobs[$class], $now);
            if (in_array($class, array_column($this->runs, 'class'), true)) {
                $this->logger->debug('Cron Job Skipped, still running: '.$class);

                continue;
            }

            $pid = $this->spawn($class, $at);
            if (null !== $pid) {
                $this->runs[$pid] = ['class' => $class, 'started' => microtime(true), 'timeout' => $jobs[$class]->TIMEOUT, 'stopped' => null];
            }
        }
    }

    /**
     * Forks the process for one run and returns its pid, null when there is nothing to wait for.
     */
    protected function spawn(string $class, \DateTimeImmutable $slot): ?int
    {
        $process = new Process(function () use ($class, $slot): void {
            // The fork copied the scheduler's signal handlers; a stop signal must end the run.
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);

            $this->worker->execute($class, $slot);

            // A job may leave a timer or a coroutine behind: the run is over all the same.
            Timer::clearAll();
            Event::exit();
        }, false, 0, true);

        $pid = $process->start();
        if (false === $pid) {
            $this->logger->error(sprintf('Cron Job Failed: %s, exception: its process could not be started', $class));

            return null;
        }

        return $pid;
    }

    /**
     * The enabled jobs, each planned from the current minute: a job due now runs at start.
     *
     * @return array<string, AbstractCronJob>
     */
    private function jobs(\DateTimeImmutable $now): array
    {
        $jobs = [];
        foreach ($this->worker->getAll() as $class => $cron) {
            if ($cron?->ENABLE) {
                $jobs[$class] = $cron;
                $this->plans[$class] = $this->worker->nextRunDate($cron, $now, true);
            }
        }

        return $jobs;
    }

    /**
     * Seconds to sleep: until just past the next 10-second mark. Jobs start only on the minute; the
     * marks in between collect the ended runs and stop the overdue ones. A stop signal cuts the
     * sleep short.
     */
    private function pause(): float
    {
        return 10.05 - fmod(microtime(true), 10);
    }

    /**
     * Collects the ended runs. A crashed one is logged here, as it never got to log itself.
     */
    private function reap(): void
    {
        while ($status = Process::wait(false)) {
            $run = $this->runs[$status['pid']] ?? null;
            unset($this->runs[$status['pid']]);

            if (null !== $run && null === $run['stopped'] && (0 !== $status['code'] || 0 !== $status['signal'])) {
                $this->logger->error(sprintf('Cron Job Failed: %s, exception: its process ended with code %d, signal %d', $run['class'], $status['code'], $status['signal']));
            }
        }
    }

    /**
     * Stops the runs past their job's TIMEOUT: SIGTERM, then SIGKILL once the grace is over.
     */
    private function stopOverdue(): void
    {
        $now = microtime(true);
        foreach ($this->runs as $pid => $run) {
            if (null !== $run['stopped']) {
                if ($now - $run['stopped'] > self::KILL_GRACE) {
                    Process::kill($pid, SIGKILL);
                }

                continue;
            }

            if ($now - $run['started'] > $run['timeout']) {
                $this->logger->error(sprintf('Cron Job Timeout: %s, stopped after %d seconds', $run['class'], $run['timeout']));
                Process::kill($pid, SIGTERM);
                $this->runs[$pid]['stopped'] = $now;
            }
        }
    }

    private function stopRuns(): void
    {
        foreach (array_keys($this->runs) as $pid) {
            Process::kill($pid, SIGTERM);
            $this->runs[$pid]['stopped'] = microtime(true);
        }

        $deadline = microtime(true) + self::KILL_GRACE;
        while ($this->runs && microtime(true) < $deadline) {
            $this->reap();
            usleep(100000);
        }

        foreach (array_keys($this->runs) as $pid) {
            Process::kill($pid, SIGKILL);
        }
    }
}
