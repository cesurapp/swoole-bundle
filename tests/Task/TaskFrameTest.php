<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Task\TaskFrame;
use PHPUnit\Framework\TestCase;

class TaskFrameTest extends TestCase
{
    public function testTheLengthCountsTheTypeAndTheBody(): void
    {
        $frame = TaskFrame::encode(TaskFrame::TASK, 'body');

        $this->assertSame(4 + 1 + 4, strlen($frame));
        $this->assertSame(5, unpack('N', $frame)[1]);
        $this->assertSame([TaskFrame::TASK, 'body'], TaskFrame::decode($frame));
    }

    public function testAFrameWithoutABody(): void
    {
        $this->assertSame([TaskFrame::DRAIN, ''], TaskFrame::decode(TaskFrame::encode(TaskFrame::DRAIN)));
    }

    public function testCount(): void
    {
        $this->assertSame(1000, TaskFrame::count(pack('N', 1000)));
        $this->assertSame(0, TaskFrame::count('ab'));
    }
}
