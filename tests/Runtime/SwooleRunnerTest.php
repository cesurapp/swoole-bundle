<?php

namespace Cesurapp\SwooleBundle\Tests\Runtime;

use Cesurapp\SwooleBundle\Cron\CronWorker;
use Cesurapp\SwooleBundle\Runtime\SwooleRunner;
use Cesurapp\SwooleBundle\Task\FailedTaskCron;
use Cesurapp\SwooleBundle\Task\TaskSettings;
use Cesurapp\SwooleBundle\Tests\Kernel;
use Cesurapp\SwooleBundle\Tests\KernelOnlyServer;
use Cesurapp\SwooleBundle\Tests\KernelTaskOnly;
use PHPUnit\Framework\TestCase;

class SwooleRunnerTest extends TestCase
{
    private const array ENV = ['SERVER_TASK_SETTINGS_WORKER_NUM', 'SERVER_TASK_SETTINGS_LIFETIME', 'SERVER_TASK_SETTINGS_MAX_MEMORY', 'SERVER_HTTP_SETTINGS_TASK_WORKER_NUM'];

    private array $config;

    protected function setUp(): void
    {
        $this->config = SwooleRunner::$config;
        $_ENV['APP_ENV'] ??= 'test';
    }

    protected function tearDown(): void
    {
        SwooleRunner::$config = $this->config;
        foreach (self::ENV as $name) {
            unset($_ENV[$name]);
        }
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

    /** SERVER_TASK_SETTINGS_* set the executors; Swoole's own task workers stay off. */
    public function testTaskSettingsComeFromTheEnv(): void
    {
        $_ENV['SERVER_TASK_SETTINGS_WORKER_NUM'] = '3';
        $_ENV['SERVER_TASK_SETTINGS_LIFETIME'] = '1500';
        $_ENV['SERVER_TASK_SETTINGS_MAX_MEMORY'] = '300';
        new SwooleRunner(new Kernel('test', true), ['env_var_name' => 'APP_ENV', 'debug' => false]);

        $this->assertSame(3, SwooleRunner::$config['task']['settings']['worker_num']);
        $this->assertSame(1500, SwooleRunner::$config['task']['settings']['lifetime']);
        $this->assertSame(300 * 1024 * 1024, TaskSettings::fromRuntime('/app')->maxMemory, 'MAX_MEMORY is in MB');
        $this->assertSame(0, SwooleRunner::$config['http']['settings']['task_worker_num']);
        $this->assertTrue(SwooleRunner::$config['worker']['task']);
    }

    /** The task worker count of old still sets the executors, unless the new one is set too. */
    public function testTheOldTaskWorkerCountStillCounts(): void
    {
        $_ENV['SERVER_HTTP_SETTINGS_TASK_WORKER_NUM'] = '5';
        new SwooleRunner(new Kernel('test', true), ['env_var_name' => 'APP_ENV', 'debug' => false]);
        $this->assertSame(5, SwooleRunner::$config['task']['settings']['worker_num']);
        $this->assertSame(0, SwooleRunner::$config['http']['settings']['task_worker_num']);

        SwooleRunner::$config = $this->config;
        $_ENV['SERVER_TASK_SETTINGS_WORKER_NUM'] = '2';
        new SwooleRunner(new Kernel('test', true), ['env_var_name' => 'APP_ENV', 'debug' => false]);
        $this->assertSame(2, SwooleRunner::$config['task']['settings']['worker_num']);
    }

    public function testNoExecutorsTurnTheTaskWorkerOff(): void
    {
        $_ENV['SERVER_TASK_SETTINGS_WORKER_NUM'] = '0';
        new SwooleRunner(new Kernel('test', true), ['env_var_name' => 'APP_ENV', 'debug' => false]);

        $this->assertFalse(SwooleRunner::$config['worker']['task']);
        $this->assertSame(0, SwooleRunner::$config['task']['settings']['worker_num']);
        $this->assertTrue(SwooleRunner::$config['worker']['cron'], 'the app has its own crons');
    }
}
