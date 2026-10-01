<?php

namespace Cesurapp\SwooleBundle\Task;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;

/**
 * Runs tasks: one of `worker_num` server-managed processes (TaskServer), each running up to
 * `concurrency` tasks at once in coroutines. It takes them from the broker (TaskBroker) and runs
 * them through TaskWorker, as the Swoole task workers did.
 *
 * Swoole's max_wait_time does not reach these processes (they are never reloaded and have no
 * max_request), so a task may run for as long as it needs. An executor guards itself instead:
 *  - it starts afresh after `lifetime` seconds (up to a fifth earlier, so the executors do not all
 *    go at once), and once a task leaves it holding more than `max_memory`: it stops taking tasks,
 *    lets the running ones finish and exits, and the manager starts a new one;
 *  - a kernel alarm kills it `lifetime` × 1.2 seconds after its start, wherever it is stuck — in a
 *    PHP loop or in a blocking call — so a hung task cannot hold it forever;
 *  - on a server stop it drains the same way, with at most `shutdown_grace` seconds left on the
 *    alarm.
 */
class TaskExecutor
{
    private ?TaskLink $link = null;

    private int $running = 0;

    private bool $draining = false;

    private ?bool $proc = null;

    public function __construct(private readonly TaskWorker $worker, private readonly LoggerInterface $logger, private readonly TaskSettings $settings)
    {
    }

    /**
     * Runs until drained, then ends the process. Runs in a coroutine.
     */
    public function run(): void
    {
        $this->guard((int) ceil($this->settings->lifetime * 1.2));
        $this->signals();
        $this->checkMemoryLimit();

        $lifetime = $this->settings->lifetime * (1 - mt_rand(0, 200) / 1000);
        $timer = Timer::after(max(1, (int) ($lifetime * 1000)), fn () => $this->drain(sprintf('its lifetime of %d seconds is over', $lifetime)));

        // A brake on a crash loop: the manager restarts an executor at once.
        Coroutine::sleep($this->delay());

        $unreachable = false;
        while (!$this->draining) {
            $socket = $this->connect();
            if (null === $socket) {
                if (!$unreachable) {
                    $this->logger->warning('Task executor cannot reach the broker, retrying every second');
                    $unreachable = true;
                }

                Coroutine::sleep(1);

                continue;
            }

            $unreachable = false;
            $this->serve($socket);
        }

        // Nothing more comes in: let the running tasks finish.
        while ($this->running > 0) {
            Coroutine::sleep(0.05);
        }

        Timer::clear($timer);
        $this->exit();
    }

    /**
     * SIGTERM, the server stops: drains, with at most shutdown_grace seconds before the alarm.
     */
    public function shutdown(): void
    {
        $this->guard($this->settings->shutdownGrace, true);
        $this->drain('the server stops');
    }

    /**
     * Arms the kernel alarm. SIGALRM with its default action ends the process wherever it is; PHP's
     * own handler would only end a running PHP loop.
     *
     * @param bool $sooner only bring an armed alarm forward, never put it off
     */
    protected function guard(int $seconds, bool $sooner = false): void
    {
        pcntl_signal(SIGALRM, SIG_DFL);
        $left = pcntl_alarm(max(1, $seconds));
        if ($sooner && $left > 0 && $left < $seconds) {
            pcntl_alarm($left);
        }
    }

    protected function signals(): void
    {
        Process::signal(SIGTERM, fn () => $this->shutdown());
    }

    /** Seconds to wait before the first connection. */
    protected function delay(): float
    {
        return mt_rand(500, 2000) / 1000;
    }

    /**
     * Bytes the process holds: its resident set where the system tells it (Linux), as the database
     * drivers, curl and Swoole allocate outside PHP's own memory manager.
     */
    protected function memory(): int
    {
        if (false !== ($this->proc ??= is_readable('/proc/self/status'))) {
            $status = (string) @file_get_contents('/proc/self/status');
            if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $match)) {
                return (int) $match[1] * 1024;
            }
        }

        return memory_get_usage(true);
    }

    /**
     * Ends the process; the manager starts a new one.
     */
    protected function exit(): void
    {
        // A task's coroutine may still wait on something: end without Swoole reporting a deadlock.
        Coroutine::set(['enable_deadlock_check' => false]);
        Timer::clearAll();
        Event::exit();
    }

    private function connect(): ?Socket
    {
        $socket = new Socket(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($socket->connect($this->settings->socket, 0, 1.0)) {
            return $socket;
        }

        $socket->close();

        return null;
    }

    /**
     * One connection to the broker: runs what it sends until it acknowledges a drain or goes.
     */
    private function serve(Socket $socket): void
    {
        $link = $this->link = new TaskLink($socket);
        $link->send(TaskFrame::encode(TaskFrame::HELLO, pack('N', max(0, $this->settings->concurrency - $this->running))));

        // Began to drain while connecting.
        if ($this->draining) {
            $link->send(TaskFrame::encode(TaskFrame::DRAIN));
        }

        while (null !== ($frame = $link->receive())) {
            [$type, $body] = $frame;
            if (TaskFrame::TASK === $type) {
                ++$this->running;
                Coroutine::create(fn () => $this->execute($body));
            } elseif (TaskFrame::DRAIN === $type) {
                break;
            }
        }

        $this->link = null;
        $link->close();
    }

    private function execute(string $body): void
    {
        try {
            $request = @unserialize($body, ['allowed_classes' => false]);
            if (!is_array($request)) {
                throw new \UnexpectedValueException(sprintf('Task request could not be unserialized (%d bytes).', strlen($body)));
            }

            $this->worker->handle($request);
        } catch (\Throwable $exception) {
            $this->logger->critical('Task executor failed: '.$exception->getMessage(), ['exception' => $exception]);
        } finally {
            --$this->running;
        }

        if ($this->draining) {
            return;
        }

        $max = $this->settings->maxMemory;
        if ($max > 0 && ($memory = $this->memory()) > $max) {
            $this->drain(sprintf('it holds %d MB, max_memory is %d MB', $memory >> 20, $max >> 20));

            return;
        }

        $this->link?->send(TaskFrame::encode(TaskFrame::READY, pack('N', 1)));
    }

    /**
     * Takes no more tasks: tells the broker, which acknowledges after the last task it sent.
     */
    private function drain(string $reason): void
    {
        if ($this->draining) {
            return;
        }

        $this->draining = true;
        $this->logger->info(sprintf('Task executor draining, %s (%d running)', $reason, $this->running));
        $this->link?->send(TaskFrame::encode(TaskFrame::DRAIN));
    }

    private function checkMemoryLimit(): void
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($this->settings->maxMemory > 0 && $limit > 0 && $limit < 2 * $this->settings->maxMemory) {
            $this->logger->warning(sprintf('Task executor: memory_limit (%d MB) is below twice max_memory (%d MB); a task may hit it before the executor starts afresh.', $limit >> 20, $this->settings->maxMemory >> 20));
        }
    }
}
