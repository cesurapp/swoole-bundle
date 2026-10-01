<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Task\TaskBrokerClient;
use Cesurapp\SwooleBundle\Task\TaskExecutor;
use Cesurapp\SwooleBundle\Task\TaskLog;
use Cesurapp\SwooleBundle\Task\TaskSettings;
use Cesurapp\SwooleBundle\Task\TaskWorker;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * An executor against a broker in this process. Its alarm, signals, startup delay, memory reading
 * and exit are stand-ins (see executor()); the alarm itself is tried in a forked process.
 */
class TaskExecutorTest extends TaskTestCase
{
    /** @var list<string> classes of the tasks run */
    private array $handled = [];

    public function testRunsTheTasksAndDrainsWhenTheServerStops(): void
    {
        $executor = $this->executor($this->settings());

        $this->coroutine(function () use ($executor) {
            $this->startBroker();
            $done = $this->launch($executor);
            $client = new TaskBrokerClient($this->settings());
            $client->send($this->request('a'));
            $client->send($this->request('b'));
            $this->assertTrue($this->waitFor(fn () => 2 === count($this->handled)));

            $executor->shutdown();
            $this->assertTrue($done->pop(3));
        });

        $this->assertEqualsCanonicalizing(['a', 'b'], $this->handled);
        $this->assertSame([600, 0, 600, 0, 30], $executor->alarms, 'max_execution_time from each task\'s start, off while idle, shutdown_grace on a stop');
        $this->assertSame(1, $executor->exits);
    }

    /** Above max_memory after a task: it takes no more, and the waiting tasks stay with the broker. */
    public function testATaskThatLeavesItOverMaxMemoryDrainsIt(): void
    {
        $settings = $this->settings(concurrency: 1, maxMemory: 100);
        $executor = $this->executor($settings);
        $executor->memory = 200;

        $this->coroutine(function () use ($executor, $settings) {
            $this->startBroker($settings);
            $client = new TaskBrokerClient($settings);
            foreach (['a', 'b', 'c'] as $class) {
                $client->send($this->request($class));
            }

            $this->assertTrue($this->launch($executor)->pop(3));
        });

        $this->assertSame(['a'], $this->handled);
        $this->assertSame(1, $executor->exits);
        $this->assertEqualsCanonicalizing([serialize($this->request('b')), serialize($this->request('c'))], $this->pending());
    }

    /** No task, no alarm, and no end: an idle executor runs until memory or a stop ends it. */
    public function testAnIdleExecutorRunsOnWithoutAnAlarm(): void
    {
        $executor = $this->executor($this->settings());
        $idle = null;

        $this->coroutine(function () use ($executor, &$idle) {
            $this->startBroker();
            $done = $this->launch($executor);
            Coroutine::sleep(1.2);
            $idle = [$executor->exits, $executor->alarms];

            $executor->shutdown();
            $this->assertTrue($done->pop(3));
        });

        $this->assertSame([0, []], $idle);
    }

    /** It pings the broker, which would otherwise take it as frozen and send it nothing. */
    public function testAnExecutorThatPingsKeepsGettingTasks(): void
    {
        $executor = $this->executor($this->settings());

        $this->coroutine(function () use ($executor) {
            $this->startBroker(impatient: true);
            $done = $this->launch($executor);
            Coroutine::sleep(2.5); // quiet for longer than the broker's patience, but for its pings

            new TaskBrokerClient($this->settings())->send($this->request('a'));
            $this->assertTrue($this->waitFor(fn () => 1 === count($this->handled), 1.0));

            $executor->shutdown();
            $this->assertTrue($done->pop(3));
        });

        $this->assertSame(['a'], $this->handled);
    }

    /** A drain waits for the running tasks before the process ends. */
    public function testADrainLetsTheRunningTaskFinish(): void
    {
        $executor = $this->executor($this->settings(), sleep: 0.3);
        $finished = null;

        $this->coroutine(function () use ($executor, &$finished) {
            $this->startBroker();
            $done = $this->launch($executor);
            new TaskBrokerClient($this->settings())->send($this->request('a'));
            Coroutine::sleep(0.1);

            $executor->shutdown();
            $this->assertSame(0, $executor->exits);
            $this->assertTrue($done->pop(3));
            $finished = $this->handled;
        });

        $this->assertSame(['a'], $finished);
        $this->assertSame(1, $executor->exits);
        $this->assertSame([600, 30], $executor->alarms, 'after a stop the alarm is neither put off nor turned off');
    }

    /** The broker restarting: the executor connects to the new one and goes on. */
    public function testItReconnectsWhenTheBrokerComesBack(): void
    {
        $executor = $this->executor($this->settings());

        $this->coroutine(function () use ($executor) {
            $broker = $this->startBroker();
            $done = $this->launch($executor);
            Coroutine::sleep(0.1);
            $broker->stop();

            $this->startBroker();
            new TaskBrokerClient($this->settings())->send($this->request('a'));
            $this->assertTrue($this->waitFor(fn () => 1 === count($this->handled)));

            $executor->shutdown();
            $this->assertTrue($done->pop(3));
        });

        $this->assertSame(['a'], $this->handled);
    }

    /** The kernel ends a hung executor wherever it hangs: in a PHP loop or a blocking call. */
    #[DataProvider('hangs')]
    public function testAHungExecutorIsKilledByItsAlarm(\Closure $hang): void
    {
        $executor = new TaskExecutor($this->createStub(TaskWorker::class), new NullLogger(), $this->settings());

        $pid = pcntl_fork();
        if (0 === $pid) {
            (fn () => $this->alarm(1))->call($executor);
            $hang();
            exit(0);
        }

        $status = 0;
        $started = microtime(true);
        while (0 === pcntl_waitpid($pid, $status, WNOHANG) && microtime(true) - $started < 5) {
            usleep(10000);
        }
        if (0 === pcntl_waitpid($pid, $status, WNOHANG)) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            $this->fail('The alarm did not end the process.');
        }

        $this->assertTrue(pcntl_wifsignaled($status));
        $this->assertSame(SIGALRM, pcntl_wtermsig($status));
    }

    public static function hangs(): iterable
    {
        yield 'php loop' => [static function (): void {
            while (true) { // @phpstan-ignore-line
            }
        }];
        yield 'blocking call' => [static fn () => sleep(10)];
    }

    /** A stop never puts the alarm off: with less time left than shutdown_grace, that time stays. */
    public function testAStopOnlyBringsTheAlarmForward(): void
    {
        $executor = new TaskExecutor($this->createStub(TaskWorker::class), new NullLogger(), $this->settings());
        $alarm = fn (int $seconds) => (fn () => $this->alarm($seconds))->call($executor);

        try {
            $alarm(100);
            $executor->shutdown();
            $this->assertSame(30, pcntl_alarm(0));

            $alarm(10);
            $executor->shutdown();
            $this->assertSame(10, pcntl_alarm(0));
        } finally {
            pcntl_alarm(0);
        }
    }

    /**
     * An executor with stand-ins for what would end or signal this process.
     */
    private function executor(TaskSettings $settings, float $sleep = 0): TaskExecutor
    {
        $worker = $this->createStub(TaskWorker::class);
        $worker->method('handle')->willReturnCallback(function (array $request) use ($sleep): void {
            if ($sleep > 0) {
                Coroutine::sleep($sleep);
            }
            $this->handled[] = $request['class'];
        });

        return new class ($worker, new NullLogger(), $settings) extends TaskExecutor {
            /** @var list<int> */
            public array $alarms = [];

            public int $exits = 0;

            public int $memory = 0;

            protected function alarm(int $seconds): int
            {
                $this->alarms[] = $seconds;

                return 0;
            }

            protected function signals(): void
            {
            }

            protected function delay(): float
            {
                return 0.001;
            }

            protected function memory(): int
            {
                return $this->memory;
            }

            protected function exit(): void
            {
                ++$this->exits;
            }
        };
    }

    /** Runs the executor; the channel gets true once run() returns. */
    private function launch(TaskExecutor $executor): Channel
    {
        $done = new Channel(1);
        Coroutine::create(static function () use ($executor, $done) {
            $executor->run();
            $done->push(true);
        });

        // A test that failed half way: stop it, or run() never returns.
        $this->defer(static fn () => 0 === $executor->exits ? $executor->shutdown() : null); // @phpstan-ignore-line

        return $done;
    }

    /**
     * @return list<string>
     */
    private function pending(): array
    {
        $log = new TaskLog($this->path('queue.log'), 10000, new NullLogger(), static fn () => null);
        $pending = array_values($log->open());
        $log->close();

        return $pending;
    }
}
