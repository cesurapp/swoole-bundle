<?php

namespace Cesurapp\SwooleBundle\Tests\Cron;

use Cesurapp\SwooleBundle\Cron\CronScheduler;
use Cesurapp\SwooleBundle\Cron\CronWorker;
use Cesurapp\SwooleBundle\Tests\_App\Cron\AcmeCron;
use Cesurapp\SwooleBundle\Tests\Kernel;
use Psr\Log\LoggerInterface;
use Swoole\Process;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CronWorkerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $_SERVER['KERNEL_CLASS'] = Kernel::class;
    }

    public function testCronWorker(): void
    {
        $this->assertTrue(self::getContainer()->has(CronWorker::class));
        $this->assertTrue(self::getContainer()->has(CronScheduler::class));
    }

    public function testCronServiceLocator(): void
    {
        /** @var CronWorker $worker */
        $worker = self::getContainer()->get(CronWorker::class);

        $this->assertSame($worker->get(AcmeCron::class)->TIME, '@EveryMinute');
    }

    public function testCronProcess(): void
    {
        /** @var CronWorker $worker */
        $worker = self::getContainer()->get(CronWorker::class);
        $logger = self::getContainer()->get('logger');
        $logger->enableDebug();

        $this->expectOutputString('Acme Cron');
        $worker->execute(AcmeCron::class, new \DateTimeImmutable('-1 minute'));

        $this->assertTrue(str_contains(json_encode($logger->getLogs()), 'Cron Job Process:'));
        $this->assertTrue(str_contains(json_encode($logger->getLogs()), 'Cron Job Finish:'));
    }

    public function testSchedulerRunsDueJobOncePerMinute(): void
    {
        $scheduler = $this->scheduler(true);

        foreach (['12:00:30', '12:00:45', '12:01:00', '12:05:10', '12:05:40'] as $time) {
            $scheduler->tick(new \DateTimeImmutable('2026-01-01 '.$time));
        }

        // Due at start, then each minute; after falling behind, once rather than once per missed minute.
        $this->assertSame(['12:00', '12:01', '12:02'], $scheduler->started(AcmeCron::class)); // @phpstan-ignore-line
    }

    public function testSchedulerSkipsJobStillRunning(): void
    {
        $scheduler = $this->scheduler(false);

        foreach (['12:00:30', '12:01:00', '12:02:00'] as $time) {
            $scheduler->tick(new \DateTimeImmutable('2026-01-01 '.$time));
        }

        $this->assertSame(['12:00'], $scheduler->started(AcmeCron::class)); // @phpstan-ignore-line
    }

    public function testSchedulerStopsRunPastTimeout(): void
    {
        /** @var CronWorker $worker */
        $worker = self::getContainer()->get(CronWorker::class);
        $worker->get(AcmeCron::class)->TIMEOUT = 1;
        $logger = self::getContainer()->get('logger');
        $logger->enableDebug();

        $scheduler = new class ($worker, $logger) extends CronScheduler {
            public ?int $pid = null;

            protected function spawn(string $class, \DateTimeImmutable $slot): ?int
            {
                if (AcmeCron::class !== $class) {
                    return null;
                }

                // A run that would take 30 seconds.
                return $this->pid = new Process(static fn () => sleep(30), false, 0, false)->start() ?: null;
            }
        };

        $scheduler->tick();
        sleep(2);
        $scheduler->tick(); // past the timeout: stopped

        // Collected on a later tick, once the process is gone.
        for ($i = 0; $i < 30 && $scheduler->pid && Process::kill($scheduler->pid, 0); ++$i) {
            usleep(100000);
            $scheduler->tick();
        }

        $this->assertNotNull($scheduler->pid);
        $this->assertFalse(Process::kill($scheduler->pid, 0));
        $this->assertTrue(str_contains(json_encode($logger->getLogs()), 'Cron Job Timeout:'));
    }

    public function testCronListCommand(): void
    {
        static::bootKernel();
        $application = new Application(self::$kernel);

        $cmd = $application->find('cron:list');
        $cmdTester = new CommandTester($cmd);
        $cmdTester->execute([]);
        $cmdTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('AcmeCron', $cmdTester->getDisplay());
    }

    public function testCronRunManuel(): void
    {
        static::bootKernel();
        $application = new Application(self::$kernel);

        $cmd = $application->find('cron:run');
        $cmdTester = new CommandTester($cmd);
        $this->expectOutputString('Acme Cron');
        $cmdTester->execute(['class' => 'AcmeCron']);
        $cmdTester->assertCommandIsSuccessful();
    }

    /**
     * A scheduler that records the runs it would fork, instead of forking them.
     *
     * @param bool $endAtOnce whether a run is over as soon as it starts; otherwise it never ends
     */
    private function scheduler(bool $endAtOnce): CronScheduler
    {
        return new class (self::getContainer()->get(CronWorker::class), self::getContainer()->get('logger'), $endAtOnce) extends CronScheduler {
            /** @var list<array{string, string}> */
            private array $forks = [];

            public function __construct(CronWorker $worker, LoggerInterface $logger, private readonly bool $endAtOnce)
            {
                parent::__construct($worker, $logger);
            }

            protected function spawn(string $class, \DateTimeImmutable $slot): ?int
            {
                $this->forks[] = [$class, $slot->format('H:i')];

                // A pid of its own for every run that goes on, as a real fork would have.
                return $this->endAtOnce ? null : 900000 + count($this->forks);
            }

            /**
             * @return list<string> the minutes $class was started for
             */
            public function started(string $class): array
            {
                return array_values(array_map(static fn ($run) => $run[1], array_filter($this->forks, static fn ($run) => $run[0] === $class)));
            }
        };
    }
}
