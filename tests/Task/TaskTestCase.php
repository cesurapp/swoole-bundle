<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Task\TaskBroker;
use Cesurapp\SwooleBundle\Task\TaskFrame;
use Cesurapp\SwooleBundle\Task\TaskSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

use function Swoole\Coroutine\run;

/**
 * A temp directory short enough for a unix socket path, and the means to run a broker in this
 * process: everything runs in coroutines, inside run().
 */
abstract class TaskTestCase extends TestCase
{
    protected string $dir;

    /** @var list<\Closure> run after the test's coroutine, even when it failed */
    private array $cleanups = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/swt'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file) || 'sock' === pathinfo($file, PATHINFO_EXTENSION)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    protected function path(string $name): string
    {
        return $this->dir.'/'.$name;
    }

    protected function settings(int $concurrency = 1000, int $maxMemory = 0, int $lifetime = 600, int $logRotate = 10000): TaskSettings
    {
        return new TaskSettings($this->path('b.sock'), $this->path('queue.log'), $concurrency, $maxMemory, $lifetime, 30, $logRotate);
    }

    /**
     * Runs $test in a coroutine and rethrows what it threw, once whatever it started is stopped.
     */
    protected function coroutine(\Closure $test): void
    {
        $error = null;
        run(function () use ($test, &$error) {
            try {
                $test();
            } catch (\Throwable $exception) {
                $error = $exception;
            } finally {
                foreach (array_reverse($this->cleanups) as $cleanup) {
                    $cleanup();
                }
                $this->cleanups = [];
            }
        });

        if (null !== $error) {
            throw $error;
        }
    }

    protected function defer(\Closure $cleanup): void
    {
        $this->cleanups[] = $cleanup;
    }

    protected function startBroker(?TaskSettings $settings = null): TaskBroker
    {
        $settings ??= $this->settings();
        $broker = new TaskBroker($settings, new NullLogger());
        $this->defer(static fn () => $broker->stop());

        $started = microtime(true);
        Coroutine::create(static fn () => $broker->run());
        // Listening: a socket, not a stale file of that name.
        while (!(file_exists($settings->socket) && 'socket' === filetype($settings->socket)) && microtime(true) - $started < 3) {
            Coroutine::sleep(0.01);
        }

        return $broker;
    }

    /**
     * An executor's side of a connection, by hand: it says hello with $credit.
     */
    protected function connect(int $credit, ?TaskSettings $settings = null): Socket
    {
        $socket = new Socket(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $socket->setProtocol(TaskFrame::PROTOCOL);
        if (!$socket->connect(($settings ?? $this->settings())->socket, 0, 1)) {
            throw new \RuntimeException('Cannot connect to the broker: '.$socket->errMsg);
        }

        $socket->sendAll(TaskFrame::encode(TaskFrame::HELLO, pack('N', $credit)));
        $this->defer(static fn () => $socket->close());

        return $socket;
    }

    /**
     * The next frame on $socket within $timeout seconds, as its type and body.
     *
     * @return array{string, string}|null
     */
    protected function next(Socket $socket, float $timeout = 2.0): ?array
    {
        $frame = $socket->recvPacket($timeout);

        return is_string($frame) && '' !== $frame ? TaskFrame::decode($frame) : null;
    }

    protected function waitFor(\Closure $condition, float $timeout = 3.0): bool
    {
        $started = microtime(true);
        while (!$condition()) {
            if (microtime(true) - $started > $timeout) {
                return false;
            }
            Coroutine::sleep(0.01);
        }

        return true;
    }

    /**
     * @return array{class: string, payload: string}
     */
    protected function request(string $class): array
    {
        return ['class' => $class, 'payload' => serialize(null)];
    }
}
