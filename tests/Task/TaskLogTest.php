<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Task\TaskFrame;
use Cesurapp\SwooleBundle\Task\TaskLog;
use Psr\Log\NullLogger;

class TaskLogTest extends TaskTestCase
{
    /** @var array<string, string> */
    private array $arrived = [];

    public function testPendingJobsSurviveAReopen(): void
    {
        $log = $this->log();
        $log->open();
        $log->add($this->id('a'), 'A');
        $log->add($this->id('b'), 'B');
        $log->complete($this->id('a'));
        $log->close();

        $this->assertSame([$this->id('b') => 'B'], $this->log()->open());
    }

    public function testAJobSentTwiceIsAddedOnce(): void
    {
        $log = $this->log();
        $log->open();

        $this->assertTrue($log->add($this->id('a'), 'A'));
        $this->assertFalse($log->add($this->id('a'), 'A'));
    }

    /** A crash in the middle of a write: the torn record goes, and the file is whole again. */
    public function testATornLastRecordIsDropped(): void
    {
        $log = $this->log();
        $log->open();
        $log->add($this->id('a'), 'A');
        $log->close();
        file_put_contents($this->path('queue.log'), substr(TaskFrame::encode(TaskFrame::ADDED, $this->id('b').'B'), 0, 10), FILE_APPEND);

        $log = $this->log();
        $this->assertSame([$this->id('a') => 'A'], $log->open());
        $log->add($this->id('c'), 'C');
        $log->close();

        $this->assertSame([$this->id('a') => 'A', $this->id('c') => 'C'], $this->log()->open());
    }

    /** Every log_rotate records the file is rewritten with only the jobs still waiting. */
    public function testARewriteKeepsOnlyThePendingJobs(): void
    {
        $log = $this->log(rotate: 4);
        $log->open();
        $log->add($this->id('a'), 'A');
        $log->add($this->id('b'), 'B');
        $log->add($this->id('c'), 'C');
        $log->complete($this->id('a')); // the fourth record: rewritten with b and c
        $log->complete($this->id('b'));

        $this->assertSame(
            TaskFrame::encode(TaskFrame::ADDED, $this->id('b').'B').TaskFrame::encode(TaskFrame::ADDED, $this->id('c').'C').TaskFrame::encode(TaskFrame::COMPLETED, $this->id('b')),
            file_get_contents($this->path('queue.log')),
        );
        $log->close();
        $this->assertSame([$this->id('c') => 'C'], $this->log()->open());
    }

    /** A producer that could not reach the broker appends itself; the broker takes it once. */
    public function testAProducersAppendIsTakenIn(): void
    {
        $log = $this->log();
        $log->open();
        $log->add($this->id('a'), 'A');
        $this->assertTrue(TaskLog::append($this->path('queue.log'), $this->id('x'), 'X'));
        $log->add($this->id('b'), 'B');

        $log->poll();
        $log->poll();

        $this->assertSame([$this->id('x') => 'X'], $this->arrived);
        $this->assertSame([$this->id('a') => 'A', $this->id('b') => 'B', $this->id('x') => 'X'], $log->pending());
    }

    public function testAnAppendAfterARewriteIsTakenIn(): void
    {
        $log = $this->log(rotate: 2);
        $log->open();
        $log->add($this->id('a'), 'A');
        $log->complete($this->id('a')); // rewritten

        TaskLog::append($this->path('queue.log'), $this->id('x'), 'X');
        $log->poll();

        $this->assertSame([$this->id('x') => 'X'], $this->arrived);
    }

    /** Appended before the broker starts: there on its first read. */
    public function testAnAppendWithoutABrokerIsThereOnOpen(): void
    {
        TaskLog::append($this->path('queue.log'), $this->id('x'), 'X');

        $this->assertSame([$this->id('x') => 'X'], $this->log()->open());
    }

    public function testAnAppendThatCannotBeWrittenFails(): void
    {
        $this->assertFalse(TaskLog::append($this->path('missing/queue.log'), $this->id('x'), 'X'));
    }

    private function log(int $rotate = 10000): TaskLog
    {
        return new TaskLog($this->path('queue.log'), $rotate, new NullLogger(), function (string $id, string $request): void {
            $this->arrived[$id] = $request;
        });
    }

    private function id(string $name): string
    {
        return str_pad($name, TaskFrame::ID_LENGTH, '.');
    }
}
