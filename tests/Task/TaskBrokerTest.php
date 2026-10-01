<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Task\TaskBrokerClient;
use Cesurapp\SwooleBundle\Task\TaskFrame;
use Cesurapp\SwooleBundle\Task\TaskLog;
use Psr\Log\NullLogger;
use Swoole\Coroutine;

/**
 * The broker in this process, with executors played by hand over its socket.
 *
 * Each send() is a connection of its own, read in a coroutine of its own: tasks sent one after the
 * other may reach the broker in either order.
 */
class TaskBrokerTest extends TaskTestCase
{
    public function testTasksGoToTheExecutorWithinItsCredit(): void
    {
        $frames = [];

        $this->coroutine(function () use (&$frames) {
            $this->startBroker();
            $executor = $this->connect(2);
            $client = new TaskBrokerClient($this->settings());
            foreach (['a', 'b', 'c'] as $class) {
                $this->assertTrue($client->send($this->request($class)));
            }

            $frames[] = $this->next($executor);
            $frames[] = $this->next($executor);
            $frames[] = $this->next($executor, 0.3); // no credit left
            $executor->sendAll(TaskFrame::encode(TaskFrame::READY, pack('N', 1)));
            $frames[] = $this->next($executor);
        });

        $this->assertNull($frames[2], 'no task past the credit');
        $this->assertEqualsCanonicalizing(
            [[TaskFrame::TASK, serialize($this->request('a'))], [TaskFrame::TASK, serialize($this->request('b'))], [TaskFrame::TASK, serialize($this->request('c'))]],
            [$frames[0], $frames[1], $frames[3]],
        );
        $this->assertSame([], $this->pending(), 'a task handed over is marked in queue.log');
    }

    /** No executor yet: the tasks wait in queue.log, and a broker started later deals them out. */
    public function testWaitingTasksSurviveARestart(): void
    {
        $frames = [];

        $this->coroutine(function () {
            $broker = $this->startBroker();
            $client = new TaskBrokerClient($this->settings());
            $client->send($this->request('a'));
            $client->send($this->request('b'));
            Coroutine::sleep(0.05);
            $broker->stop();
        });
        $this->assertCount(2, $this->pending());

        $this->coroutine(function () use (&$frames) {
            $this->startBroker();
            $executor = $this->connect(5);
            $frames[] = $this->next($executor);
            $frames[] = $this->next($executor);
        });

        $this->assertEqualsCanonicalizing([[TaskFrame::TASK, serialize($this->request('a'))], [TaskFrame::TASK, serialize($this->request('b'))]], $frames);
        $this->assertSame([], $this->pending());
    }

    /**
     * A draining executor gets its acknowledgement after the tasks already on their way, and
     * nothing after it, whatever credit it sends.
     */
    public function testADrainIsAcknowledgedAfterTheLastTask(): void
    {
        $frames = [];

        $this->coroutine(function () use (&$frames) {
            $this->startBroker();
            $executor = $this->connect(2);
            $client = new TaskBrokerClient($this->settings());
            $client->send($this->request('a'));
            $client->send($this->request('b'));
            Coroutine::sleep(0.05);
            $executor->sendAll(TaskFrame::encode(TaskFrame::DRAIN).TaskFrame::encode(TaskFrame::READY, pack('N', 5)));
            $client->send($this->request('c'));

            while (null !== ($frame = $this->next($executor, 0.3))) {
                $frames[] = $frame[0];
            }
        });

        $this->assertSame([TaskFrame::TASK, TaskFrame::TASK, TaskFrame::DRAIN], $frames);
        $this->assertSame([serialize($this->request('c'))], array_values($this->pending()));
    }

    /** An executor that went away holds no credit: the next one gets the task. */
    public function testAGoneExecutorGetsNothing(): void
    {
        $frame = null;

        $this->coroutine(function () use (&$frame) {
            $this->startBroker();
            $this->connect(5)->close();
            Coroutine::sleep(0.05);
            new TaskBrokerClient($this->settings())->send($this->request('a'));
            $frame = $this->next($this->connect(1));
        });

        $this->assertSame([TaskFrame::TASK, serialize($this->request('a'))], $frame);
    }

    /**
     * An executor that says nothing for a while — frozen, or held up by a blocking call — gets no
     * tasks until it answers: what it was sent would be lost with it.
     */
    public function testAQuietExecutorGetsNoTasksUntilItAnswers(): void
    {
        $frames = [];

        $this->coroutine(function () use (&$frames) {
            $this->startBroker(impatient: true);
            $executor = $this->connect(5);
            Coroutine::sleep(2.5);

            new TaskBrokerClient($this->settings())->send($this->request('a'));
            $frames[] = $this->next($executor, 0.5);
            $executor->sendAll(TaskFrame::encode(TaskFrame::PING));
            $frames[] = $this->next($executor);
        });

        $this->assertSame([null, [TaskFrame::TASK, serialize($this->request('a'))]], $frames);
    }

    /** A producer that could not reach the broker appended to queue.log: picked up within a second. */
    public function testAProducersAppendIsDealtOut(): void
    {
        $frame = null;

        $this->coroutine(function () use (&$frame) {
            $this->startBroker();
            $executor = $this->connect(1);
            TaskLog::append($this->path('queue.log'), random_bytes(TaskFrame::ID_LENGTH), serialize($this->request('a')));
            $frame = $this->next($executor);
        });

        $this->assertSame([TaskFrame::TASK, serialize($this->request('a'))], $frame);
    }

    /** A socket file left by a broker that died is replaced. */
    public function testAStaleSocketFileIsReplaced(): void
    {
        touch($this->path('b.sock'));
        $sent = false;

        $this->coroutine(function () use (&$sent) {
            $this->startBroker();
            $sent = new TaskBrokerClient($this->settings())->send($this->request('a'));
        });

        $this->assertTrue($sent);
        $this->assertFileDoesNotExist($this->path('b.sock'), 'the socket goes with the broker');
    }

    /**
     * @return array<string, string>
     */
    private function pending(): array
    {
        $log = new TaskLog($this->path('queue.log'), 10000, new NullLogger(), static fn () => null);
        $pending = $log->open();
        $log->close();

        return $pending;
    }
}
