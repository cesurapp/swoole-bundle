<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Entity\FailedTask;
use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;
use Cesurapp\SwooleBundle\Task\FailedTaskCron;
use Cesurapp\SwooleBundle\Task\TaskWorker;
use Cesurapp\SwooleBundle\Tests\_App\Task\AcmeFailedTask;
use Cesurapp\SwooleBundle\Tests\_App\Task\AcmeTask;
use Cesurapp\SwooleBundle\Tests\Kernel;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The sweeper over the task store, run directly (not through CronWorker, whose schedule would make
 * the test depend on the minute) against a stand-in server that records what it is given.
 */
class FailedTaskCronTest extends KernelTestCase
{
    private mixed $previousServer = null;

    protected function setUp(): void
    {
        $_SERVER['KERNEL_CLASS'] = Kernel::class;
        $this->previousServer = $GLOBALS['httpServer'] ?? null;

        $em = self::getContainer()->get('doctrine')->getManager();
        $schemaTool = new SchemaTool($em);
        $schemaTool->dropDatabase();
        $schemaTool->updateSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        if (null === $this->previousServer) {
            unset($GLOBALS['httpServer']);
        } else {
            $GLOBALS['httpServer'] = $this->previousServer;
        }
        parent::tearDown();
    }

    /** A failure is claimed and queued as its next attempt — and stays until that attempt succeeds. */
    public function testFailureIsClaimedAndQueuedNotDeleted(): void
    {
        $this->failOnce(AcmeFailedTask::class);
        $this->retryDelayPasses();
        $server = $this->server();

        $this->sweep();

        $row = $this->rows()[0];
        $this->assertSame([[
            'class' => AcmeFailedTask::class,
            'payload' => serialize('data'),
            'id' => $row->getId()->toRfc4122(),
            'attempt' => 2,
        ]], $server->queued);
        $this->assertSame(2, $row->getAttempt());
        $this->assertNotNull($row->getDeliveredAt());
        $this->assertSame('acme task exception', $row->getException());
    }

    /** A failure is not retried before its task_retry delay is over. */
    public function testFailureWaitsForItsRetryDelay(): void
    {
        $this->failOnce(AcmeFailedTask::class);
        $server = $this->server();

        $this->sweep();

        $this->assertSame([], $server->queued);
        $this->assertEqualsWithDelta(time() + $this->delays()[0], $this->rows()[0]->getAvailableAt()->getTimestamp(), 5);

        $this->retryDelayPasses();
        $this->sweep();

        $this->assertCount(1, $server->queued);
    }

    public function testRunningAttemptIsLeftAlone(): void
    {
        $this->store()->insert(['class' => AcmeTask::class, 'payload' => serialize('data')], 1, new \DateTimeImmutable());
        $server = $this->server();

        $this->sweep();

        $this->assertSame([], $server->queued);
        $this->assertSame(1, $this->rows()[0]->getAttempt());
    }

    /** A durable task nothing could take (console, open transaction, refusal) runs on the next sweep. */
    public function testWaitingDurableTaskIsQueued(): void
    {
        $this->store()->insert(['class' => AcmeTask::class, 'payload' => serialize('data')]);
        $server = $this->server();

        $this->sweep();

        $this->assertSame(1, $server->queued[0]['attempt']);
    }

    /** An attempt handed over longer ago than task_redeliver_timeout died with its worker. */
    public function testLostAttemptIsMarkedAndQueuedAgain(): void
    {
        $this->store()->insert(['class' => AcmeTask::class, 'payload' => serialize('data')], 1, new \DateTimeImmutable('-2 hours'));
        $server = $this->server();

        $this->sweep();

        $this->assertStringStartsWith('Lost: not finished within 3600 seconds', $this->rows()[0]->getException());
        $this->assertSame(2, $server->queued[0]['attempt']);
    }

    /** Lost on its last attempt: shown as failed, not run again. */
    public function testLostLastAttemptIsMarkedButNotQueued(): void
    {
        $last = $this->retries() + 1;
        $this->store()->insert(['class' => AcmeTask::class, 'payload' => serialize('data')], $last, new \DateTimeImmutable('-2 hours'));
        $server = $this->server();

        $this->sweep();

        $this->assertSame([], $server->queued);
        $this->assertCount(1, $this->store()->failedQuery()->getQuery()->getResult());
    }

    /** Swoole refusing is not an attempt: it is given back, and the run stops asking. */
    public function testRefusalGivesTheAttemptBackAndEndsTheRun(): void
    {
        $this->failOnce(AcmeFailedTask::class);
        $this->failOnce(AcmeFailedTask::class);
        $this->retryDelayPasses();
        $server = $this->server(accepts: false);

        $this->sweep();

        $this->assertSame(1, $server->calls);
        foreach ($this->rows() as $row) {
            $this->assertSame(1, $row->getAttempt());
            $this->assertNull($row->getDeliveredAt());
        }
    }

    /** Two sweepers reading the same row: only one gets it. */
    public function testARowIsClaimedOnce(): void
    {
        $this->failOnce(AcmeFailedTask::class);
        $id = $this->rows()[0]->getId()->toRfc4122();

        $this->assertTrue($this->store()->claim($id, 1));
        $this->assertFalse($this->store()->claim($id, 1));
    }

    /** Batch after batch, each read on from the last row: every due row is queued exactly once. */
    public function testABacklogLargerThanABatchIsDrained(): void
    {
        for ($i = 0; $i < 120; ++$i) {
            $this->failOnce(AcmeFailedTask::class);
        }
        $this->retryDelayPasses();
        $server = $this->server();

        $this->sweep();

        $this->assertCount(120, $server->queued);
        $this->assertCount(120, array_unique(array_column($server->queued, 'id')));
    }

    /** One run plus one retry per task_retry entry, then the row rests in the failed list. */
    public function testATaskThatAlwaysFailsRunsOncePlusItsRetries(): void
    {
        $worker = self::getContainer()->get(TaskWorker::class);
        $this->failOnce(AcmeFailedTask::class);
        $runs = 1;

        do {
            $this->retryDelayPasses();
            $server = $this->server();
            $this->sweep();
            foreach ($server->queued as $request) {
                $worker->handle($request); // what the task worker does with it
                ++$runs;
            }
        } while ([] !== $server->queued);

        $this->assertSame($this->retries() + 1, $runs);
        $this->assertCount(1, $this->store()->failedQuery()->getQuery()->getResult());
    }

    /** task:failed:retry hands the failed tasks their attempts back. */
    public function testRetryFailedRequeuesFailures(): void
    {
        $this->store()->insert(['class' => AcmeTask::class, 'payload' => serialize('data')], $this->retries() + 1, null, 'gave up', new \DateTimeImmutable('+1 hour'));
        $this->store()->insert(['class' => AcmeTask::class, 'payload' => serialize('data')], 1, new \DateTimeImmutable());

        $this->assertSame(1, $this->store()->retryFailed());
        $server = $this->server();
        $this->sweep();

        $this->assertCount(1, $server->queued);
    }

    private function sweep(): void
    {
        self::getContainer()->get(FailedTaskCron::class)();
    }

    /** A first run that fails: the row every retry starts from. */
    private function failOnce(string $class): void
    {
        self::getContainer()->get(TaskWorker::class)->handle(['class' => $class, 'payload' => serialize('data')]);
    }

    /**
     * @return list<int>
     */
    private function delays(): array
    {
        return self::getContainer()->getParameter('swoole.task_retry');
    }

    private function retries(): int
    {
        return count($this->delays());
    }

    /** Every waiting retry's delay is over. */
    private function retryDelayPasses(): void
    {
        self::getContainer()->get('doctrine')->getManager()
            ->createQuery(sprintf('UPDATE %s f SET f.availableAt = :past WHERE f.availableAt IS NOT NULL', FailedTask::class))
            ->setParameter('past', new \DateTimeImmutable('-1 second'), Types::DATETIME_IMMUTABLE)
            ->execute();
    }

    private function store(): FailedTaskRepository
    {
        return self::getContainer()->get(FailedTaskRepository::class);
    }

    /**
     * @return list<FailedTask>
     */
    private function rows(): array
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(FailedTask::class)->findBy([], ['createdAt' => 'ASC']);
    }

    private function server(bool $accepts = true): object
    {
        return $GLOBALS['httpServer'] = new class ($accepts) {
            public array $queued = [];
            public int $calls = 0;
            public bool $taskworker = false;

            public function __construct(private readonly bool $accepts)
            {
            }

            public function task(array $request): int|false
            {
                ++$this->calls;
                if (!$this->accepts) {
                    return false;
                }
                $this->queued[] = $request;

                return count($this->queued) - 1;
            }
        };
    }
}
