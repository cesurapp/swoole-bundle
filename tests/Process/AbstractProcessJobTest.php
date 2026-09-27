<?php

namespace Cesurapp\SwooleBundle\Tests\Process;

use Cesurapp\SwooleBundle\Process\AbstractProcessJob;
use Cesurapp\SwooleBundle\Process\ProcessWorker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\Timer;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

use function Swoole\Coroutine\run;

class AbstractProcessJobTest extends TestCase
{
    public function testAJobStopsAtOnceUnlessItAsksForTime(): void
    {
        $this->assertSame(0, $this->job()->STOP_TIMEOUT);
        $this->assertFalse($this->job()->isStopping());
    }

    public function testPauseSleepsTheWholeTime(): void
    {
        $job = $this->job();
        $result = null;
        $took = 0.0;

        run(static function () use ($job, &$result, &$took): void {
            $started = microtime(true);
            $result = $job->wait(0.1);
            $took = microtime(true) - $started;
        });

        $this->assertTrue($result);
        $this->assertGreaterThanOrEqual(0.09, $took);
    }

    /** A stop wakes a pause in progress: the loop sees it at once, not at the end of its sleep. */
    public function testStopCutsAPauseShort(): void
    {
        $job = $this->job();
        $result = null;
        $took = 0.0;

        run(static function () use ($job, &$result, &$took): void {
            Coroutine::create(static function () use ($job): void {
                Coroutine::sleep(0.05);
                $job->stop();
            });
            $started = microtime(true);
            $result = $job->wait(5);
            $took = microtime(true) - $started;
        });

        $this->assertFalse($result);
        $this->assertLessThan(1, $took);
        $this->assertTrue($job->isStopping());
    }

    public function testAPauseAfterAStopReturnsAtOnce(): void
    {
        $job = $this->job();
        $job->stop();
        $result = null;

        run(static function () use ($job, &$result): void {
            $result = $job->wait(5);
        });

        $this->assertFalse($result);
    }

    /**
     * SIGTERM on a running job that asks for time tells it to stop and keeps the process — and the
     * lock — until the job returns or the time is up.
     */
    public function testTheWorkerGivesARunningJobItsTime(): void
    {
        $job = $this->job();
        $job->STOP_TIMEOUT = 30;
        $worker = new ProcessWorker(new ServiceLocator([]), new NullLogger(), new LockFactory(new InMemoryStore()));
        $this->assertTrue($worker->lockAcquire($job::class));
        new \ReflectionProperty(ProcessWorker::class, 'job')->setValue($worker, $job);
        new \ReflectionProperty(ProcessWorker::class, 'running')->setValue($worker, true);

        run(static function () use ($worker): void {
            new \ReflectionMethod(ProcessWorker::class, 'stop')->invoke($worker);
            Timer::clearAll(); // the timeout that would end the process
        });

        $this->assertTrue($job->isStopping());
        $this->assertNotSame([], new \ReflectionProperty(ProcessWorker::class, 'locks')->getValue($worker), 'The lock is kept while the job finishes.');
    }

    private function job(): AbstractProcessJob
    {
        return new class () extends AbstractProcessJob {
            public function __invoke(): void
            {
            }

            public function wait(float $seconds): bool
            {
                return $this->pause($seconds);
            }
        };
    }
}
