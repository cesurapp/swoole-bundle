<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Task\TaskBrokerClient;
use Cesurapp\SwooleBundle\Task\TaskFrame;
use Cesurapp\SwooleBundle\Task\TaskLog;
use Psr\Log\NullLogger;

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

    /** Outside a coroutine (a console command): the job goes all the same, as one frame. */
    public function testTheJobGoesAsOneFrame(): void
    {
        $server = stream_socket_server('unix://'.$this->path('b.sock'));
        $this->assertIsResource($server);

        $this->assertTrue(new TaskBrokerClient($this->settings())->send($this->request('a')));
        $connection = stream_socket_accept($server, 1);
        $this->assertIsResource($connection);
        [$type, $body] = TaskFrame::decode((string) stream_get_contents($connection));
        fclose($connection);
        fclose($server);

        $this->assertSame(TaskFrame::JOB, $type);
        $this->assertSame(serialize($this->request('a')), substr($body, TaskFrame::ID_LENGTH));
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
}
