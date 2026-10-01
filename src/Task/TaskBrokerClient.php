<?php

namespace Cesurapp\SwooleBundle\Task;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

/**
 * Hands tasks to the broker (TaskBroker) without waiting for a reply: dispatching never holds up
 * the caller, an HTTP request least of all.
 *
 * Each process keeps one connection to the broker, opened by its first task and used for all the
 * others: an HTTP worker's lives as long as the worker. A connection per task had the broker accept
 * and close one for each, and a loop dispatching thousands outran it: its backlog overflowed, and
 * Swoole keeps a closed coroutine socket open until the coroutine yields, so descriptors piled up.
 *
 * The coroutines of a process take turns on the connection, so frames never interleave. A
 * connection that died (the broker restarted) is replaced before the next write. A broker that does
 * not read gets WRITE_TIMEOUT seconds; then the connection is dropped and send() says no, and the
 * caller appends the task to queue.log instead. A broker that could not be reached is left alone
 * for RETRY_AFTER seconds.
 */
class TaskBrokerClient
{
    private const float CONNECT_TIMEOUT = 1.0;

    /** Seconds a write may wait on a broker that does not read. */
    private const float WRITE_TIMEOUT = 0.5;

    /** Seconds to leave a broker alone that could not be reached or did not read. */
    private const float RETRY_AFTER = 1.0;

    /** The connection inside a coroutine. */
    private ?Socket $socket = null;

    /** @var resource|null the connection outside a coroutine (a console command) */
    private $stream;

    /** One coroutine writes at a time. */
    private ?Channel $turn = null;

    /** The process the connections belong to: a forked child opens its own. */
    private int $pid = 0;

    /** No connection attempt before this time. */
    private float $retryAt = 0.0;

    public function __construct(private readonly TaskSettings $settings)
    {
    }

    /**
     * False when the broker could not be reached (it is restarting, or no server runs) or did not
     * read in time: nothing was handed over.
     */
    public function send(array $request): bool
    {
        $frame = TaskFrame::encode(TaskFrame::JOB, random_bytes(TaskFrame::ID_LENGTH).$this->serialize($request));

        // Inherited through a fork: the parent writes on those, the child opens its own.
        if (getmypid() !== $this->pid) {
            $this->socket = $this->stream = $this->turn = null;
            $this->pid = (int) getmypid();
        }

        return Coroutine::getCid() > 0 ? $this->sendInCoroutine($frame) : $this->sendBlocking($frame);
    }

    /**
     * Writes the task straight to queue.log, for when the broker cannot be reached: the broker
     * takes it from there once it is back. False when the file cannot be written either.
     */
    public function append(array $request): bool
    {
        return TaskLog::append($this->settings->log, random_bytes(TaskFrame::ID_LENGTH), $this->serialize($request));
    }

    private function sendInCoroutine(string $frame): bool
    {
        $turn = $this->turn ??= new Channel(1);
        if (!$turn->push(true, self::WRITE_TIMEOUT)) {
            return false; // the coroutines before this one wait on a broker that does not read
        }

        try {
            $socket = $this->socket();
            if (null !== $socket && strlen($frame) === $socket->sendAll($frame, self::WRITE_TIMEOUT)) {
                return true;
            }

            // Gone, or not reading: a frame cut off half way goes with the connection.
            if (null !== $socket) {
                $socket->close();
                $this->socket = null;
                $this->retryAt = microtime(true) + self::RETRY_AFTER;
            }

            return false;
        } finally {
            $turn->pop();
        }
    }

    /**
     * The open connection, or a new one when there is none or it died.
     */
    private function socket(): ?Socket
    {
        if (null !== $this->socket && $this->socket->checkLiveness()) {
            return $this->socket;
        }

        $this->socket?->close();
        $this->socket = null;
        if (!$this->reachable()) {
            return null;
        }

        $socket = new Socket(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if (!$socket->connect($this->settings->socket, 0, self::CONNECT_TIMEOUT)) {
            $socket->close();
            $this->retryAt = microtime(true) + self::RETRY_AFTER;

            return null;
        }

        return $this->socket = $socket;
    }

    private function sendBlocking(string $frame): bool
    {
        $stream = $this->stream();
        if (null !== $stream && $this->writeAll($stream, $frame)) {
            return true;
        }

        if (null !== $stream) {
            fclose($stream);
            $this->stream = null;
            $this->retryAt = microtime(true) + self::RETRY_AFTER;
        }

        return false;
    }

    /**
     * The open connection, or a new one when there is none or it died.
     *
     * @return resource|null
     */
    private function stream()
    {
        if (null !== $this->stream && !feof($this->stream)) {
            return $this->stream;
        }

        if (null !== $this->stream) {
            fclose($this->stream);
            $this->stream = null;
        }

        if (!$this->reachable()) {
            return null;
        }

        $stream = @stream_socket_client('unix://'.$this->settings->socket, $code, $message, self::CONNECT_TIMEOUT);
        if (false === $stream) {
            $this->retryAt = microtime(true) + self::RETRY_AFTER;

            return null;
        }

        // A blocking write ignores the stream's timeout: it would wait on a broker that does not read.
        stream_set_blocking($stream, false);

        return $this->stream = $stream;
    }

    /**
     * Writes the whole frame, waiting at most WRITE_TIMEOUT for room.
     *
     * @param resource $stream
     */
    private function writeAll($stream, string $frame): bool
    {
        $deadline = microtime(true) + self::WRITE_TIMEOUT;
        while (true) {
            $written = @fwrite($stream, $frame);
            if (false === $written) {
                return false;
            }

            $frame = substr($frame, $written);
            if ('' === $frame) {
                return true;
            }

            $left = $deadline - microtime(true);
            $read = $except = null;
            $write = [$stream];
            if ($left <= 0 || 1 !== @stream_select($read, $write, $except, 0, (int) ($left * 1000000))) {
                return false;
            }
        }
    }

    /** The broker's socket is there, and it was not given up on a moment ago. */
    private function reachable(): bool
    {
        return microtime(true) >= $this->retryAt && file_exists($this->settings->socket);
    }

    private function serialize(array $request): string
    {
        $data = serialize($request);
        if (strlen($data) > TaskFrame::MAX_REQUEST) {
            throw new \LengthException(sprintf('Task "%s" is too large to queue: %d bytes, %d at most.', $request['class'] ?? '?', strlen($data), TaskFrame::MAX_REQUEST));
        }

        return $data;
    }
}
