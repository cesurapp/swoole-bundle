<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Task\TaskQueue;
use PHPUnit\Framework\TestCase;

class TaskQueueTest extends TestCase
{
    public function testNothingGoesOutWithoutALink(): void
    {
        $queue = new TaskQueue();
        $queue->push('a', 'A');

        $this->assertNull($queue->assign());
        $this->assertSame(1, $queue->pending());
    }

    public function testJobsGoOutInOrderWithinTheCredit(): void
    {
        $queue = new TaskQueue();
        $queue->credit(1, 2);
        $queue->push('a', 'A');
        $queue->push('b', 'B');
        $queue->push('c', 'C');

        $this->assertSame([1, 'a', 'A'], $queue->assign());
        $this->assertSame([1, 'b', 'B'], $queue->assign());
        $this->assertNull($queue->assign());

        $queue->credit(1, 1);
        $this->assertSame([1, 'c', 'C'], $queue->assign());
        $this->assertSame(0, $queue->pending());
    }

    public function testTheLinkWithTheMostRoomGetsTheJob(): void
    {
        $queue = new TaskQueue();
        $queue->credit(1, 1);
        $queue->credit(2, 3);
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $queue->push($id, $id);
        }

        $links = [];
        while ($assigned = $queue->assign()) {
            $links[] = $assigned[0];
        }

        $this->assertSame([2, 2, 1, 2], $links);
    }

    public function testADrainingLinkGetsNoMore(): void
    {
        $queue = new TaskQueue();
        $queue->credit(1, 5);
        $queue->revoke(1);
        $queue->credit(1, 5);
        $queue->push('a', 'A');

        $this->assertNull($queue->assign());
    }

    public function testAClosedLinkGetsNoMore(): void
    {
        $queue = new TaskQueue();
        $queue->credit(1, 5);
        $queue->close(1);
        $queue->push('a', 'A');

        $this->assertNull($queue->assign());
    }

    /** A paused link keeps its credit but gets nothing, however much room it has, until resumed. */
    public function testAPausedLinkGetsNothingUntilResumed(): void
    {
        $queue = new TaskQueue();
        $queue->credit(1, 5);
        $queue->credit(2, 1);
        $queue->push('a', 'A');
        $queue->push('b', 'B');

        $this->assertTrue($queue->pause(1));
        $this->assertFalse($queue->pause(1));
        $this->assertSame([2, 'a', 'A'], $queue->assign());
        $this->assertNull($queue->assign());

        $this->assertTrue($queue->resume(1));
        $this->assertFalse($queue->resume(1));
        $this->assertSame([1, 'b', 'B'], $queue->assign());
    }

    public function testAJobPutBackGoesFirst(): void
    {
        $queue = new TaskQueue();
        $queue->credit(1, 2);
        $queue->push('a', 'A');
        $queue->unshift('b', 'B');

        $this->assertSame('b', $queue->assign()[1] ?? null);
        $this->assertSame('a', $queue->assign()[1] ?? null);
    }
}
