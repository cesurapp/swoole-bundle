<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Task\TaskBrokerClient;
use Cesurapp\SwooleBundle\Task\TaskFrame;
use Cesurapp\SwooleBundle\Task\TaskLog;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

/**
 * The client against a stand-in broker (listener()) that counts its connections and collects the
 * frames: a process keeps one connection and writes every task on it.
 */
class TaskBrokerClientTest extends TaskTestCase
{
    public function testWithoutABrokerNothingIsSent(): void
    {
        $this->assertFalse(new TaskBrokerClient($this->settings())->send($this->request('a')));
    }

    /** The socket of a broker that died: refused quietly. */
    public function testAStaleSocketRefusesQuietly(): void
    {
        $server = stream_socket_server('unix://'.$this->path('b.sock'));
        $this->assertIsResource($server);
        fclose($server);
        $this->assertFileExists($this->path('b.sock'));

        $this->assertFalse(new TaskBrokerClient($this->settings())->send($this->request('a')));
    }

    /** Coroutines of one process share its connection: their frames arrive whole, never mixed. */
    public function testOneConnectionCarriesEveryTaskFromEveryCoroutine(): void
    {
        $result = null;

        $this->coroutine(function () use (&$result) {
            $broker = $this->listener();
            $client = new TaskBrokerClient($this->settings());
            $wg = new Coroutine\WaitGroup();
            for ($c = 0; $c < 10; ++$c) {
                $wg->add();
                Coroutine::create(function () use ($client, $c, $wg) {
                    for ($i = 0; $i < 10; ++$i) {
                        // Larger than the socket buffer: a write waits half way, and others try theirs.
                        $client->send(['class' => "$c-$i", 'payload' => str_repeat('x', 100000)]);
                    }
                    $wg->done();
                });
            }
            $wg->wait();
            $this->waitFor(fn () => 100 === count($broker->frames));

            $classes = array_map(static fn (array $frame) => unserialize(substr($frame[1], TaskFrame::ID_LENGTH))['class'] ?? null, $broker->frames);
            $result = [$broker->connections, array_unique(array_column($broker->frames, 0)), count(array_unique($classes))];
        });

        $this->assertSame([1, [TaskFrame::JOB], 100], $result);
    }

    /** The broker restarted: the next task goes on a new connection. */
    public function testADeadConnectionIsReplaced(): void
    {
        $result = null;

        $this->coroutine(function () use (&$result) {
            $broker = $this->listener();
            $client = new TaskBrokerClient($this->settings());
            $client->send($this->request('a'));
            $this->waitFor(fn () => 1 === count($broker->frames));

            $broker->peers[0]->close();
            Coroutine::sleep(0.05);
            $sent = $client->send($this->request('b'));
            $this->waitFor(fn () => 2 === count($broker->frames));

            $result = [$sent, $broker->connections, count($broker->frames)];
        });

        $this->assertSame([true, 2, 2], $result);
    }

    /**
     * A broker that does not read holds a task up for half a second at most; then the task is
     * refused (the caller appends it to queue.log), and so are the next ones for a second.
     */
    public function testABrokerThatDoesNotReadIsGivenUpOn(): void
    {
        $result = null;

        $this->coroutine(function () use (&$result) {
            $this->listener(read: false);
            $client = new TaskBrokerClient($this->settings());

            $started = microtime(true);
            $first = $client->send(['class' => 'a', 'payload' => str_repeat('x', 4 * 1024 * 1024)]);
            $waited = microtime(true) - $started;

            $started = microtime(true);
            $next = $client->send($this->request('b'));
            $result = [$first, $waited, $next, microtime(true) - $started];
        });

        [$first, $waited, $next, $nextWaited] = $result;
        $this->assertFalse($first);
        $this->assertEqualsWithDelta(0.5, $waited, 0.3);
        $this->assertFalse($next);
        $this->assertLessThan(0.1, $nextWaited);
    }

    /** A forked process (a cron run) must not write on its parent's connection: it opens its own. */
    public function testAForkedProcessOpensItsOwnConnection(): void
    {
        $connections = null;

        $this->coroutine(function () use (&$connections) {
            $broker = $this->listener();
            $client = new TaskBrokerClient($this->settings());
            $client->send($this->request('a'));
            (fn () => $this->pid = -1)->call($client); // as a forked child sees it
            $client->send($this->request('b'));
            $this->waitFor(fn () => 2 === count($broker->frames));

            $connections = $broker->connections;
        });

        $this->assertSame(2, $connections);
    }

    /** Outside a coroutine (a console command): one connection as well, and every frame whole. */
    public function testOutsideACoroutineOneConnectionCarriesEveryTask(): void
    {
        $server = stream_socket_server('unix://'.$this->path('b.sock'));
        $this->assertIsResource($server);
        $client = new TaskBrokerClient($this->settings());

        $this->assertTrue($client->send($this->request('a')));
        $this->assertTrue($client->send($this->request('b')));
        $connection = stream_socket_accept($server, 1);
        $this->assertIsResource($connection);
        $frames = [self::frame($connection), self::frame($connection)];
        $another = @stream_socket_accept($server, 0.1);
        fclose($connection);
        fclose($server);

        $this->assertSame([TaskFrame::JOB, TaskFrame::JOB], array_column($frames, 0));
        $this->assertSame(serialize($this->request('a')), substr($frames[0][1], TaskFrame::ID_LENGTH));
        $this->assertSame(serialize($this->request('b')), substr($frames[1][1], TaskFrame::ID_LENGTH));
        $this->assertFalse($another, 'no second connection');
    }

    public function testAppendWritesToTheQueueLog(): void
    {
        $this->assertTrue(new TaskBrokerClient($this->settings())->append($this->request('a')));

        $log = new TaskLog($this->path('queue.log'), 10000, new NullLogger(), static fn () => null);
        $this->assertSame([serialize($this->request('a'))], array_values($log->open()));
        $log->close();
    }

    public function testATaskTooLargeToQueueFailsLoudly(): void
    {
        $this->expectException(\LengthException::class);
        new TaskBrokerClient($this->settings())->send(['class' => 'a', 'payload' => str_repeat('x', TaskFrame::MAX_REQUEST)]);
    }

    /**
     * A stand-in broker on the client's socket.
     *
     * @param bool $read false: it accepts, but never reads
     */
    private function listener(bool $read = true): object
    {
        $state = new class () {
            public int $connections = 0;

            /** @var list<array{string, string}> */
            public array $frames = [];

            /** @var list<Socket> */
            public array $peers = [];
        };

        $server = new Socket(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $server->bind($this->path('b.sock'));
        $server->listen(64);
        $this->defer(static function () use ($server, $state) {
            $server->close();
            foreach ($state->peers as $peer) {
                $peer->close();
            }
        });

        Coroutine::create(static function () use ($server, $state, $read) {
            while (false !== ($peer = $server->accept(-1))) {
                ++$state->connections;
                $state->peers[] = $peer;
                if ($read) {
                    Coroutine::create(static function () use ($peer, $state) {
                        $peer->setProtocol(TaskFrame::PROTOCOL);
                        while (is_string($frame = $peer->recvPacket(-1)) && '' !== $frame) {
                            $state->frames[] = TaskFrame::decode($frame);
                        }
                    });
                }
            }
        });

        return $state;
    }

    /**
     * @param resource $stream
     *
     * @return array{string, string}
     */
    private static function frame($stream): array
    {
        $header = (string) fread($stream, 4);
        $length = (int) unpack('N', $header)[1];
        $body = '';
        while (strlen($body) < $length) {
            $body .= (string) fread($stream, $length - strlen($body));
        }

        return TaskFrame::decode($header.$body);
    }
}
