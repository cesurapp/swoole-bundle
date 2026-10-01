<?php

namespace Cesurapp\SwooleBundle\Task;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

/**
 * One end of a broker–executor connection. A socket takes one writer at a time, so frames go
 * through an outbox that a single coroutine writes out in order, and any coroutine may queue one.
 *
 * A frame may carry a job: $sent gets it once the frame is written, $unsent gets the jobs whose
 * frames never made it (the peer went away), in order, when the link closes.
 */
final class TaskLink
{
    private Channel $outbox;

    private Channel $flushed;

    private bool $open = true;

    private bool $broken = false;

    /**
     * @param (\Closure(mixed): void)|null       $sent
     * @param (\Closure(list<mixed>): void)|null $unsent
     */
    public function __construct(private readonly Socket $socket, private readonly ?\Closure $sent = null, private readonly ?\Closure $unsent = null)
    {
        $socket->setProtocol(TaskFrame::PROTOCOL);
        $this->outbox = new Channel(TaskQueue::MAX_CREDIT + 16);
        $this->flushed = new Channel(1);
        Coroutine::create(fn () => $this->write());
    }

    /**
     * Queues a frame. False when the link is closed or broken: the frame, and its job, did not go.
     */
    public function send(string $frame, mixed $job = null): bool
    {
        if (!$this->open || $this->broken) {
            return false;
        }

        return $this->outbox->push([$frame, $job]);
    }

    /**
     * The next whole frame, as its type and body; null once the peer is gone.
     *
     * @return array{string, string}|null
     */
    public function receive(): ?array
    {
        $frame = $this->socket->recvPacket(-1);

        return is_string($frame) && '' !== $frame ? TaskFrame::decode($frame) : null;
    }

    /**
     * Writes out what is queued, then closes the socket.
     */
    public function close(): void
    {
        if (!$this->open) {
            return;
        }

        $this->open = false;
        $this->outbox->close();
        $this->flushed->pop();
        $this->socket->close();
    }

    /**
     * Closes the socket at once: what is queued fails, and so does a read or a write in progress.
     */
    public function abort(): void
    {
        $this->socket->close();
    }

    private function write(): void
    {
        $unsent = [];

        // A closed outbox still hands out what it holds; false once it is empty.
        while (false !== ($item = $this->outbox->pop())) {
            [$frame, $job] = $item;
            if (!$this->broken && strlen($frame) === $this->socket->sendAll($frame)) {
                if (null !== $job && null !== $this->sent) {
                    ($this->sent)($job);
                }

                continue;
            }

            $this->broken = true;
            if (null !== $job) {
                $unsent[] = $job;
            }
        }

        if ([] !== $unsent && null !== $this->unsent) {
            ($this->unsent)($unsent);
        }

        $this->flushed->push(true);
    }
}
