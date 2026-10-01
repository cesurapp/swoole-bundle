<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Task\TaskFrame;
use Cesurapp\SwooleBundle\Task\TaskLink;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

class TaskLinkTest extends TaskTestCase
{
    /** Frames queued from anywhere arrive whole and in order; close() writes them out first. */
    public function testFramesArriveWholeAndInOrderBeforeTheClose(): void
    {
        $big = str_repeat('x', 3 * 1024 * 1024);
        $received = [];
        $sent = [];

        $this->coroutine(function () use ($big, &$received, &$sent) {
            [$near, $far] = $this->pair();
            $reader = new TaskLink($far);
            $done = new Coroutine\Channel(1);
            Coroutine::create(function () use ($reader, $done, &$received) {
                while (null !== ($frame = $reader->receive())) {
                    $received[] = $frame;
                }
                $reader->close();
                $done->push(true);
            });

            $writer = new TaskLink($near, function (mixed $job) use (&$sent) {
                $sent[] = $job;
            });
            $writer->send(TaskFrame::encode(TaskFrame::TASK, $big), 'big');
            Coroutine::create(fn () => $writer->send(TaskFrame::encode(TaskFrame::READY, pack('N', 7))));
            $writer->send(TaskFrame::encode(TaskFrame::DRAIN));
            $writer->close();
            $done->pop(5);
        });

        $this->assertSame([[TaskFrame::TASK, $big], [TaskFrame::READY, pack('N', 7)], [TaskFrame::DRAIN, '']], $received);
        $this->assertSame(['big'], $sent);
    }

    /**
     * A link whose socket is gone loses no job: each one is either given back when the link closes
     * or refused by send() once the failure is known.
     */
    public function testJobsThatDidNotGoComeBack(): void
    {
        $unsent = [];
        $refused = [];

        $this->coroutine(function () use (&$unsent, &$refused) {
            [$near] = $this->pair();
            $link = new TaskLink($near, null, function (array $jobs) use (&$unsent) {
                $unsent = $jobs;
            });
            $link->abort();
            foreach (['one', 'two', 'three'] as $job) {
                if (!$link->send(TaskFrame::encode(TaskFrame::TASK, $job), $job)) {
                    $refused[] = $job;
                }
                Coroutine::sleep(0.01);
            }
            $link->close();
        });

        $this->assertSame(['one', 'two', 'three'], [...$unsent, ...$refused]);
        $this->assertContains('three', $refused);
    }

    /**
     * Two connected sockets over a listener (a socketpair's close does not reach its peer).
     *
     * @return array{Socket, Socket}
     */
    private function pair(): array
    {
        $server = new Socket(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $server->bind($this->path('l.sock'));
        $server->listen(8);
        $near = new Socket(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $near->connect($this->path('l.sock'));
        $far = $server->accept(1);
        $server->close();
        $this->assertInstanceOf(Socket::class, $far);

        return [$near, $far];
    }
}
