<?php

namespace Cesurapp\SwooleBundle\Tests\Runtime;

use Cesurapp\SwooleBundle\Cron\CronWorker;
use Cesurapp\SwooleBundle\Runtime\SwooleRunner;
use Cesurapp\SwooleBundle\Task\FailedTaskCron;
use Cesurapp\SwooleBundle\Tests\KernelOnlyServer;
use Cesurapp\SwooleBundle\Tests\KernelTaskOnly;
use PHPUnit\Framework\TestCase;

class SwooleRunnerTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = SwooleRunner::$config;
        $_ENV['APP_ENV'] ??= 'test';
    }

    protected function tearDown(): void
    {
        SwooleRunner::$config = $this->config;
    }

    public function testWorkerOffInBundleStaysOff(): void
    {
        // task_worker: false, with SERVER_WORKER_TASK left at its default (on)
        new SwooleRunner(new KernelOnlyServer('test', true), ['env_var_name' => 'APP_ENV', 'debug' => false]);

        $this->assertFalse(SwooleRunner::$config['worker']['task']);
        $this->assertSame(0, SwooleRunner::$config['http']['settings']['task_worker_num']);
        $this->assertTrue(SwooleRunner::$config['worker']['cron']);
    }

    public function testTaskWorkerKeepsSchedulerForFailedTasks(): void
    {
        $kernel = new KernelTaskOnly('test', true);
        new SwooleRunner($kernel, ['env_var_name' => 'APP_ENV', 'debug' => false]);

        $this->assertTrue(SwooleRunner::$config['worker']['task']);
        $this->assertTrue(SwooleRunner::$config['worker']['cron']);

        // The scheduler runs FailedTaskCron alone: the app's crons are off.
        $kernel->boot();
        $this->assertSame([FailedTaskCron::class], array_keys(iterator_to_array($kernel->getContainer()->get(CronWorker::class)->getAll())));
    }
}
