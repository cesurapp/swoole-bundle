<?php

namespace Cesurapp\SwooleBundle\Tests\Runtime;

use Cesurapp\SwooleBundle\Runtime\SwooleProcess;
use PHPUnit\Framework\TestCase;

class SwooleProcessTest extends TestCase
{
    public function testTheTaskBrokerFilesAreInVar(): void
    {
        $this->assertSame('/app/var/task-broker.sock', SwooleProcess::taskSocket('/app'));
        $this->assertSame('/app/var/durable', SwooleProcess::durableDir('/app/'));
        $this->assertSame('/app/var/durable/queue.log', SwooleProcess::taskLog('/app/'));
    }

    /** A unix socket path has a length limit: a long one goes to the temp directory, the same each time. */
    public function testALongSocketPathGoesToTheTempDirectory(): void
    {
        $root = '/'.str_repeat('a', 100);
        $path = SwooleProcess::taskSocket($root);

        $this->assertStringStartsWith(sys_get_temp_dir().'/swoole-task-', $path);
        $this->assertLessThanOrEqual(104, strlen($path));
        $this->assertSame($path, SwooleProcess::taskSocket($root));
        $this->assertNotSame($path, SwooleProcess::taskSocket($root.'b'));
    }
}
