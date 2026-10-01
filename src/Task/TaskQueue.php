<?php

namespace Cesurapp\SwooleBundle\Task;

/**
 * The broker's waiting jobs and its executors' room for more, without any I/O: a FIFO of jobs and
 * a credit per executor link. A link's credit is how many more tasks it takes before it says it is
 * ready again; a job goes to the link with the most, so the executors share the load. A paused link
 * keeps its credit but gets nothing until it is resumed.
 */
final class TaskQueue
{
    /** The most credit a link holds: bounds what can wait in its outbox (TaskLink). */
    public const int MAX_CREDIT = 65536;

    /** @var \SplDoublyLinkedList<array{string, string}> id and serialized request */
    private \SplDoublyLinkedList $jobs;

    /** @var array<int, int> credit by link, for the links that take work */
    private array $credits = [];

    /** @var array<int, true> links that drain: they get no more work, whatever they say */
    private array $revoked = [];

    /** @var array<int, true> links that went quiet: no work until they answer again */
    private array $paused = [];

    public function __construct()
    {
        $this->jobs = new \SplDoublyLinkedList();
    }

    public function push(string $id, string $request): void
    {
        $this->jobs->push([$id, $request]);
    }

    /** Puts a job back at the head, as the next one out. */
    public function unshift(string $id, string $request): void
    {
        $this->jobs->unshift([$id, $request]);
    }

    public function credit(int $link, int $count): void
    {
        if (isset($this->revoked[$link])) {
            return;
        }

        $this->credits[$link] = min(self::MAX_CREDIT, ($this->credits[$link] ?? 0) + max(0, $count));
    }

    /** The link drains: it gets no more work. */
    public function revoke(int $link): void
    {
        unset($this->credits[$link]);
        $this->revoked[$link] = true;
    }

    /** The link is gone. */
    public function close(int $link): void
    {
        unset($this->credits[$link], $this->revoked[$link], $this->paused[$link]);
    }

    /** The link went quiet: it keeps its credit but gets no work. False when it already was paused. */
    public function pause(int $link): bool
    {
        if (isset($this->paused[$link])) {
            return false;
        }

        $this->paused[$link] = true;

        return true;
    }

    /** The link answers again. False when it was not paused. */
    public function resume(int $link): bool
    {
        if (!isset($this->paused[$link])) {
            return false;
        }

        unset($this->paused[$link]);

        return true;
    }

    /**
     * The next job and the link it goes to, its credit taken; null when nothing waits or no link
     * has room.
     *
     * @return array{int, string, string}|null link, id and serialized request
     */
    public function assign(): ?array
    {
        if ($this->jobs->isEmpty()) {
            return null;
        }

        $link = null;
        $credit = 0;
        foreach ($this->credits as $candidate => $room) {
            if ($room > $credit && !isset($this->paused[$candidate])) {
                $link = $candidate;
                $credit = $room;
            }
        }

        if (null === $link) {
            return null;
        }

        --$this->credits[$link];
        [$id, $request] = $this->jobs->shift();

        return [$link, $id, $request];
    }

    public function pending(): int
    {
        return $this->jobs->count();
    }
}
