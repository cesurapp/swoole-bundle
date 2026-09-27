<?php

namespace Cesurapp\SwooleBundle\Process;

use Swoole\Coroutine\Channel;

abstract class AbstractProcessJob implements ProcessInterface
{
    /**
     * Process is Enable|Disable.
     */
    public bool $ENABLE = true;

    /**
     * Restart process after completion.
     */
    public bool $RESTART = false;

    /**
     * Sleep duration (in seconds) before restarting the process.
     * Only used when RESTART is true.
     */
    public int $RESTART_DELAY = 5;

    /**
     * Seconds a stop (SIGTERM: the server shutting down, a deploy) gives the job to wind down: it
     * sees isStopping(), finishes the piece of work in hand and returns from __invoke(); past this the
     * process ends anyway. 0 ends it at once, whatever the job is doing — for a job with nothing to
     * finish. Keep it under the container's stop grace period.
     */
    public int $STOP_TIMEOUT = 0;

    private bool $stopping = false;

    private ?Channel $wakeup = null;

    /**
     * Whether the process is stopping: finish the work in hand, start nothing new, return.
     */
    public function isStopping(): bool
    {
        return $this->stopping;
    }

    /**
     * Asks the job to wind down — ProcessWorker calls it on SIGTERM when STOP_TIMEOUT allows. A
     * pause() in progress ends at once.
     */
    public function stop(): void
    {
        $this->stopping = true;

        if ($this->wakeup?->isEmpty()) {
            $this->wakeup->push(true);
        }
    }

    /**
     * Coroutine::sleep() that a stop cuts short — the wait between two rounds of a loop.
     *
     * @return bool false when the process is stopping: leave the loop
     */
    protected function pause(float $seconds): bool
    {
        if ($this->stopping) {
            return false;
        }

        // Made on first use: the constructor runs in the master process, outside any coroutine.
        $this->wakeup ??= new Channel(1);
        $this->wakeup->pop($seconds);

        // Read again: stop() may have run while this coroutine waited.
        return !$this->isStopping();
    }
}
