<?php

namespace Cesurapp\SwooleBundle\Task;

use Swoole\Client;
use Swoole\Coroutine;

/**
 * Hands tasks to the broker (TaskBroker): connects to its socket, writes the job and goes, without
 * waiting for a reply. Dispatching never holds up the caller, an HTTP request least of all.
 */
class TaskBrokerClient
{
    private const float CONNECT_TIMEOUT = 1.0;

    public function __construct(private readonly TaskSettings $settings)
    {
    }

    /**
     * False when the broker could not be reached (it is restarting, or no server runs): nothing was
     * handed over.
     */
    public function send(array $request): bool
    {
        $frame = TaskFrame::encode(TaskFrame::JOB, random_bytes(TaskFrame::ID_LENGTH).$this->serialize($request));
        $path = $this->settings->socket;
        if (!file_exists($path)) {
            return false;
        }

        // The coroutine client yields while it waits; the plain one blocks (a console command, a test).
        $client = Coroutine::getCid() > 0 ? new Coroutine\Client(SWOOLE_SOCK_UNIX_STREAM) : new Client(SWOOLE_SOCK_UNIX_STREAM);
        if (!@$client->connect($path, 0, self::CONNECT_TIMEOUT)) {
            return false;
        }

        $sent = @$client->send($frame);
        $client->close();

        return strlen($frame) === $sent;
    }

    /**
     * Writes the task straight to queue.log, for when the broker cannot be reached: the broker
     * takes it from there once it is back. False when the file cannot be written either.
     */
    public function append(array $request): bool
    {
        return TaskLog::append($this->settings->log, random_bytes(TaskFrame::ID_LENGTH), $this->serialize($request));
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
