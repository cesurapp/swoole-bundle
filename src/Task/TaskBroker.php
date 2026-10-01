<?php

namespace Cesurapp\SwooleBundle\Task;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\Timer;

/**
 * Takes the tasks from everything that dispatches them and deals them out to the task executors.
 * It is one server-managed process (TaskServer) that only moves bytes, so a producer never waits
 * on it: it connects, writes its job and goes.
 *
 * Each job goes to queue.log as it arrives, and is marked there once an executor has it (TaskLog):
 * a crash, a restart or a server stop loses nothing that was waiting, the next start picks it up.
 * What happens to a task after that is not the broker's business: a failure lands in the
 * failed-task store as it always did, and a task that dies with its executor counts as done.
 *
 * Executors pull: each says how many tasks it runs at once, and asks for one more as each one
 * finishes. One that drains says so (TaskFrame::DRAIN) and gets an acknowledgement after the last
 * task sent to it, so nothing marked as handed over is left unread on its socket.
 */
class TaskBroker
{
    /** Seconds a new connection has to send its first frame, and a producer its next one. */
    private const float READ_TIMEOUT = 5.0;

    /** Seconds between two looks at queue.log for the producers' own appends. */
    private const int POLL_INTERVAL = 1;

    private TaskQueue $queue;

    private ?TaskLog $log = null;

    private ?Socket $server = null;

    /** @var array<int, TaskLink> executor links by key */
    private array $links = [];

    /** @var array<int, Socket> every open connection, closed on stop */
    private array $connections = [];

    private ?int $timer = null;

    private bool $stopping = false;

    public function __construct(private readonly TaskSettings $settings, private readonly LoggerInterface $logger)
    {
        $this->queue = new TaskQueue();
    }

    /**
     * Serves until stop(). Runs in a coroutine.
     */
    public function run(): void
    {
        try {
            $log = new TaskLog($this->settings->log, $this->settings->logRotate, $this->logger, fn (string $id, string $request) => $this->queue->push($id, $request));
            foreach ($log->open() as $id => $request) {
                $this->queue->push($id, $request);
            }

            $this->log = $log;
            $server = $this->server = $this->listen();
        } catch (\Throwable $exception) {
            $this->logger->critical('Task broker cannot start: '.$exception->getMessage(), ['exception' => $exception]);
            Coroutine::sleep(1); // the manager restarts the process at once: not in a tight loop

            throw $exception;
        }

        // Stopped before or while starting: file I/O may yield, with Swoole's file hook on.
        if ($this->stopping) {
            $this->close();

            return;
        }

        if ($this->queue->pending() > 0) {
            $this->logger->info(sprintf('Task broker started with %d waiting tasks from queue.log', $this->queue->pending()));
        }

        $this->timer = Timer::tick(self::POLL_INTERVAL * 1000, function (): void {
            $this->log?->poll();
            $this->pump();
        });

        while (true) {
            $socket = $server->accept(-1);
            if (false !== $socket) {
                Coroutine::create(fn () => $this->serve($socket));

                continue;
            }

            // Closed by stop(); otherwise out of file descriptors, say, for a moment.
            if ($this->stopping) {
                return;
            }

            $this->logger->warning('Task broker accept failed: '.$server->errMsg);
            Coroutine::sleep(0.1);
        }
    }

    /**
     * Stops taking and dealing out tasks. What is still waiting is in queue.log already.
     */
    public function stop(): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;
        $this->close();
    }

    /**
     * Lets go of whatever is open: the timer, the socket and its file, the connections, the log.
     */
    private function close(): void
    {
        if (null !== $this->timer) {
            Timer::clear($this->timer);
            $this->timer = null;
        }

        $this->server?->close();
        if (file_exists($this->settings->socket)) {
            unlink($this->settings->socket);
        }

        foreach ($this->connections as $socket) {
            $socket->close();
        }

        if (null !== $this->log) {
            $waiting = count($this->log->pending());
            $this->log->close();
            $this->log = null;
            if ($waiting > 0) {
                $this->logger->info(sprintf('Task broker stopped with %d waiting tasks, kept in queue.log', $waiting));
            }
        }
    }

    private function listen(): Socket
    {
        $path = $this->settings->socket;
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0777, true);
        }

        // Left by a broker that died: nobody listens on it.
        if (file_exists($path)) {
            unlink($path);
        }

        $socket = new Socket(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if (!$socket->bind($path) || !$socket->listen(512)) {
            throw new \RuntimeException(sprintf('Task broker cannot listen on %s: %s', $path, $socket->errMsg));
        }

        return $socket;
    }

    private function serve(Socket $socket): void
    {
        $key = spl_object_id($socket);
        $this->connections[$key] = $socket;
        $socket->setProtocol(TaskFrame::PROTOCOL);

        try {
            $frame = $socket->recvPacket(self::READ_TIMEOUT);
            if (!is_string($frame) || '' === $frame) {
                return;
            }

            [$type, $body] = TaskFrame::decode($frame);
            match ($type) {
                TaskFrame::JOB => $this->produce($socket, $body),
                TaskFrame::HELLO => $this->attach($socket, TaskFrame::count($body)),
                default => $this->logger->warning(sprintf('Task broker got an unknown frame "%s", connection closed', $type)),
            };
        } finally {
            unset($this->connections[$key]);
            $socket->close();
        }
    }

    /**
     * A producer's connection: one job, usually, then the end.
     */
    private function produce(Socket $socket, string $body): void
    {
        do {
            $this->take($body);
            $frame = $socket->recvPacket(self::READ_TIMEOUT);
            [$type, $body] = is_string($frame) && '' !== $frame ? TaskFrame::decode($frame) : ['', ''];
        } while (TaskFrame::JOB === $type);
    }

    private function take(string $body): void
    {
        $id = substr($body, 0, TaskFrame::ID_LENGTH);
        $request = substr($body, TaskFrame::ID_LENGTH);
        if (TaskFrame::ID_LENGTH !== strlen($id) || '' === $request || null === $this->log) {
            return;
        }

        if ($this->log->add($id, $request)) {
            $this->queue->push($id, $request);
            $this->pump();
        }
    }

    /**
     * An executor's connection: credit in, tasks out, until it drains or goes.
     */
    private function attach(Socket $socket, int $credit): void
    {
        $link = new TaskLink(
            $socket,
            fn (array $job) => $this->log?->complete($job[0]),
            fn (array $jobs) => $this->requeue($jobs),
        );
        $key = spl_object_id($link);
        $this->links[$key] = $link;
        $this->queue->credit($key, $credit);
        $this->pump();

        try {
            while (null !== ($frame = $link->receive())) {
                [$type, $body] = $frame;
                if (TaskFrame::READY === $type) {
                    $this->queue->credit($key, TaskFrame::count($body));
                    $this->pump();
                } elseif (TaskFrame::DRAIN === $type) {
                    $this->queue->revoke($key);
                    $link->send(TaskFrame::encode(TaskFrame::DRAIN));
                }
            }
        } finally {
            $this->queue->close($key);
            unset($this->links[$key]);

            // The tasks it never got come back through requeue().
            $link->close();
            $this->pump();
        }
    }

    /**
     * Jobs whose frames did not reach their executor: first in line again, in their order.
     *
     * @param list<array{string, string}> $jobs
     */
    private function requeue(array $jobs): void
    {
        foreach (array_reverse($jobs) as [$id, $request]) {
            $this->queue->unshift($id, $request);
        }
    }

    /**
     * Deals out the waiting jobs while the executors have room.
     */
    private function pump(): void
    {
        if ($this->stopping) {
            return;
        }

        while (null !== ($assigned = $this->queue->assign())) {
            [$key, $id, $request] = $assigned;
            $link = $this->links[$key] ?? null;
            if (null === $link || !$link->send(TaskFrame::encode(TaskFrame::TASK, $request), [$id, $request])) {
                $this->queue->unshift($id, $request);
                $this->queue->close($key);
            }
        }
    }
}
