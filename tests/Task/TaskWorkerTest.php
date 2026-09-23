<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Entity\FailedTask;
use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;
use Cesurapp\SwooleBundle\Task\TaskWorker;
use Cesurapp\SwooleBundle\Tests\_App\AcmePayload;
use Cesurapp\SwooleBundle\Tests\_App\Task\AcmeErrorTask;
use Cesurapp\SwooleBundle\Tests\_App\Task\AcmeFailedTask;
use Cesurapp\SwooleBundle\Tests\_App\Task\AcmeTask;
use Cesurapp\SwooleBundle\Tests\Kernel;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\KernelInterface;

class TaskWorkerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $_SERVER['KERNEL_CLASS'] = Kernel::class;
    }

    public function testTaskWorker(): void
    {
        $this->assertTrue(self::getContainer()->has(TaskWorker::class));

        /** @var TaskWorker $worker */
        $worker = self::getContainer()->get(TaskWorker::class);

        try {
            $worker->getAll();
        } catch (\Exception $exception) {
            $this->throwException($exception);
        }
    }

    public function testTaskServiceLocator(): void
    {
        /** @var TaskWorker $worker */
        $worker = self::getContainer()->get(TaskWorker::class);

        $this->assertInstanceOf(AcmeTask::class, $worker->getTask(['class' => AcmeTask::class, 'payload' => 'Acme']));
    }

    public function testTaskProcess(): void
    {
        /** @var TaskWorker $worker */
        $worker = self::getContainer()->get(TaskWorker::class);
        $logger = self::getContainer()->get('logger');
        $logger->enableDebug();

        $worker->handle(['class' => AcmeTask::class, 'payload' => serialize('Acme')]);
        $this->expectOutputString('Acme');
        $this->assertTrue(str_contains(json_encode($logger->getLogs()), 'Success Task:'));
    }

    public function testTaskFailedRegister(): void
    {
        /** @var TaskWorker $worker */
        $worker = self::getContainer()->get(TaskWorker::class);
        $logger = self::getContainer()->get('logger');
        $logger->enableDebug();

        $this->initDatabase(self::$kernel ?? self::bootKernel());
        $worker->handle(['class' => AcmeFailedTask::class, 'payload' => serialize('AcmeData')]);

        $this->assertTrue(str_contains(json_encode($logger->getLogs()), 'Failed Task:'));
    }

    /** A first failure is stored at rest, as run 1, waiting for FailedTaskCron. */
    public function testFailureIsStoredForARetry(): void
    {
        $this->initDatabase(self::$kernel ?? self::bootKernel());
        self::getContainer()->get(TaskWorker::class)->handle(['class' => AcmeFailedTask::class, 'payload' => serialize('AcmeData')]);

        $failedTask = $this->rows()[0];
        $this->assertSame('acme task exception', $failedTask->getException());
        $this->assertSame(serialize('AcmeData'), $failedTask->getPayload());
        $this->assertSame(1, $failedTask->getAttempt());
        $this->assertNull($failedTask->getDeliveredAt());
    }

    /** A stored task (durable, or a retry) takes its row along when it succeeds. */
    public function testStoredTaskSuccessDeletesItsRow(): void
    {
        $this->initDatabase(self::$kernel ?? self::bootKernel());
        $id = $this->storedRow(AcmeTask::class, serialize('Acme'));

        self::getContainer()->get(TaskWorker::class)->handle(['class' => AcmeTask::class, 'payload' => serialize('Acme'), 'id' => $id, 'attempt' => 1]);

        $this->expectOutputString('Acme');
        $this->assertSame([], $this->rows());
    }

    public function testStoredTaskFailureKeepsItsRowForARetry(): void
    {
        $this->initDatabase(self::$kernel ?? self::bootKernel());
        $id = $this->storedRow(AcmeFailedTask::class, serialize('AcmeData'));

        self::getContainer()->get(TaskWorker::class)->handle(['class' => AcmeFailedTask::class, 'payload' => serialize('AcmeData'), 'id' => $id, 'attempt' => 1]);

        $row = $this->rows()[0];
        $this->assertSame('acme task exception', $row->getException());
        $this->assertNull($row->getDeliveredAt());
        $this->assertSame(1, $row->getAttempt());
    }

    /** Each failure waits for its own task_retry entry; after the last one there is no next run. */
    public function testRetryDelaysFollowTheTaskRetryList(): void
    {
        $this->initDatabase(self::$kernel ?? self::bootKernel());
        $worker = new TaskWorker(
            new ServiceLocator([AcmeFailedTask::class => static fn () => new AcmeFailedTask()]),
            self::getContainer()->get('logger'),
            self::getContainer()->get(FailedTaskRepository::class),
            [60, 300],
        );

        foreach ([1 => 60, 2 => 300, 3 => null] as $attempt => $delay) {
            $id = $this->storedRow(AcmeFailedTask::class, serialize('AcmeData'), $attempt);
            $worker->handle(['class' => AcmeFailedTask::class, 'payload' => serialize('AcmeData'), 'id' => $id, 'attempt' => $attempt]);

            $availableAt = self::getContainer()->get(FailedTaskRepository::class)->find($id)?->getAvailableAt();
            if (null === $delay) {
                $this->assertNull($availableAt);
            } else {
                $this->assertEqualsWithDelta(time() + $delay, $availableAt?->getTimestamp(), 5);
            }
        }
    }

    /**
     * An attempt whose lease ran out and whose row was taken again must not free the newer
     * attempt's lease — FailedTaskCron would start a third run beside it.
     */
    public function testStaleAttemptLeavesTheRowAlone(): void
    {
        $this->initDatabase(self::$kernel ?? self::bootKernel());
        $id = $this->storedRow(AcmeFailedTask::class, serialize('AcmeData'), attempt: 2);

        self::getContainer()->get(TaskWorker::class)->handle(['class' => AcmeFailedTask::class, 'payload' => serialize('AcmeData'), 'id' => $id, 'attempt' => 1]);

        $row = $this->rows()[0];
        $this->assertSame('', $row->getException());
        $this->assertNotNull($row->getDeliveredAt());
    }

    /** Recording a failure writes that row only — never what the failed task left unflushed. */
    public function testFailureDoesNotFlushTheTasksOwnChanges(): void
    {
        $this->initDatabase(self::$kernel ?? self::bootKernel());
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->persist(new FailedTask()->setTask('left-dirty-by-the-task'));

        self::getContainer()->get(TaskWorker::class)->handle(['class' => AcmeFailedTask::class, 'payload' => serialize('AcmeData')]);

        $this->assertSame([AcmeFailedTask::class], array_map(static fn (FailedTask $task) => $task->getTask(), $this->rows()));
    }

    /** A task that closed the EntityManager still gets its failure recorded. */
    public function testFailureIsRecordedAfterTheTaskClosedTheEntityManager(): void
    {
        $this->initDatabase(self::$kernel ?? self::bootKernel());
        self::getContainer()->get('doctrine')->getManager()->close();

        self::getContainer()->get(TaskWorker::class)->handle(['class' => AcmeFailedTask::class, 'payload' => serialize('AcmeData')]);

        $this->assertCount(1, $this->rows());
    }

    /** handle() runs in Swoole's task callback: a store that cannot be written must not take it down. */
    public function testAStoreErrorNeverEscapes(): void
    {
        $store = $this->createMock(FailedTaskRepository::class);
        $store->expects($this->once())->method('createTask')->willThrowException(new \RuntimeException('database is down'));
        $logger = self::getContainer()->get('logger');
        $logger->enableDebug();
        $worker = new TaskWorker(new ServiceLocator([AcmeFailedTask::class => static fn () => new AcmeFailedTask()]), $logger, $store);

        $worker->handle(['class' => AcmeFailedTask::class, 'payload' => serialize('AcmeData')]);

        $this->assertStringContainsString('Task store write failed', json_encode($logger->getLogs()));
    }

    /**
     * The store's own insert keeps the payload base64 as well: NUL bytes from private properties
     * survive the text column.
     */
    public function testStoredPayloadWithNullBytesRoundTrips(): void
    {
        $this->initDatabase(self::$kernel ?? self::bootKernel());
        $payload = serialize(['notification' => new AcmePayload(), 'device' => 'x']);

        self::getContainer()->get(TaskWorker::class)->handle(['class' => AcmeFailedTask::class, 'payload' => $payload]);

        $this->assertSame($payload, $this->rows()[0]->getPayload());
    }

    /**
     * Görevden gelen \Error worker'ı öldürmemeli; failed_task'a düşüp devam etmeli.
     */
    public function testTaskErrorIsCaught(): void
    {
        /** @var TaskWorker $worker */
        $worker = self::getContainer()->get(TaskWorker::class);
        $logger = self::getContainer()->get('logger');
        $logger->enableDebug();

        $this->initDatabase(self::$kernel ?? self::bootKernel());
        $worker->handle(['class' => AcmeErrorTask::class, 'payload' => serialize('')]);

        $this->assertTrue(str_contains(json_encode($logger->getLogs()), 'Failed Task:'));
        $this->assertSame(1, self::getContainer()->get('doctrine')->getRepository(FailedTask::class)->count([]));
    }

    /**
     * Bozuk payload göreve hiç girmemeli, açıklayıcı bir hatayla failed_task'a düşmeli.
     */
    public function testCorruptPayloadIsRejected(): void
    {
        /** @var TaskWorker $worker */
        $worker = self::getContainer()->get(TaskWorker::class);

        $this->initDatabase(self::$kernel ?? self::bootKernel());
        $worker->handle(['class' => AcmeTask::class, 'payload' => 'a:2:{s:12:"notification"']);

        /** @var FailedTask $failedTask */
        $failedTask = self::getContainer()->get('doctrine')->getRepository(FailedTask::class)->findAll()[0];
        $this->assertStringContainsString('could not be unserialized', $failedTask->getException());
    }

    /**
     * serialize() nesne alanlarını `\0Sınıf\0alan` biçiminde kodluyor. Postgres text
     * sütunu NUL baytı taşımıyor ve kayıt ilk NUL'da kesiliyor; bu yüzden sütuna
     * yazılan değerin NUL içermemesi gerekiyor.
     *
     * Asıl iddia sütunun ham içeriği üzerinden ölçülüyor: testler SQLite'ta koşuyor ve
     * SQLite NUL'a tolerans gösterdiği için yalnızca gidiş-dönüşe bakan bir test
     * düzeltme geri alındığında da geçiyor, yani hiçbir şeyi korumuyor.
     */
    public function testPayloadWithNullBytesIsStoredWithoutNullBytes(): void
    {
        $payload = serialize(['notification' => new AcmePayload(), 'device' => 'x']);
        $this->assertStringContainsString("\0", $payload, 'Fikstür NUL baytı üretmiyor.');

        $task = (new FailedTask())->setPayload($payload);

        // Sütuna gidecek değer bu; DB'ye hiç uğramadan ölçülüyor.
        $column = (new \ReflectionProperty(FailedTask::class, 'payload'))->getValue($task);
        $this->assertStringNotContainsString("\0", $column, 'Sütuna NUL baytı yazılıyor; Postgres kaydı ilk NUL\'da keser.');

        // Gidiş-dönüş: dışarıya yine ham payload çıkıyor.
        $this->assertSame($payload, $task->getPayload());
        $this->assertIsArray(unserialize($task->getPayload()));
    }

    /**
     * Boş ve "0" gibi falsy payload'lar null'a dönüşmemeli.
     */
    public function testFalsyPayloadRoundTrips(): void
    {
        foreach (['', '0', serialize(false)] as $payload) {
            $this->assertSame($payload, (new FailedTask())->setPayload($payload)->getPayload());
        }

        $this->assertNull((new FailedTask())->setPayload(null)->getPayload());
    }

    public function testFailedCreate(): void
    {
        $worker = self::getContainer()->get(TaskWorker::class);

        // Init DB
        $this->initDatabase(self::$kernel ?? self::bootKernel());

        /* @var TaskWorker $worker */
        $worker->handle([
            'class' => 'TestTaskClass',
            'data' => [],
        ]);

        $this->assertGreaterThanOrEqual(1, self::getContainer()->get('doctrine')->getRepository(FailedTask::class)->count([]));
    }

    public function testFailedClearCommand(): void
    {
        $worker = self::getContainer()->get(TaskWorker::class);

        // Init DB
        $this->initDatabase(self::$kernel ?? self::bootKernel());

        /* @var TaskWorker $worker */
        $worker->handle([
            'class' => 'TestTaskClass',
            'data' => [],
        ]);

        $application = new Application(self::$kernel);
        $cmd = $application->find('task:failed:clear');
        $cmdTester = new CommandTester($cmd);
        $cmdTester->execute([]);
        $cmdTester->assertCommandIsSuccessful();

        $this->assertGreaterThanOrEqual(0, self::getContainer()->get('doctrine')->getRepository(FailedTask::class)->count([]));
    }

    public function testFailedViewCommand(): void
    {
        $worker = self::getContainer()->get(TaskWorker::class);

        // Init DB
        $this->initDatabase(self::$kernel ?? self::bootKernel());

        /* @var TaskWorker $worker */
        $worker->handle([
            'class' => 'TestTaskClass',
            'data' => [],
        ]);

        $application = new Application(self::$kernel);

        $cmd = $application->find('task:failed:view');
        $cmdTester = new CommandTester($cmd);
        $cmdTester->execute([]);
        $cmdTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('TestTaskClass', $cmdTester->getDisplay());
    }

    /** Unfinished durable work — waiting or running — is neither listed as failed nor cleared. */
    public function testFailedCommandsLeaveUnfinishedDurableWorkAlone(): void
    {
        $this->initDatabase(self::$kernel ?? self::bootKernel());
        $store = self::getContainer()->get(FailedTaskRepository::class);
        $store->insert(['class' => 'WaitingDurableTask', 'payload' => serialize('')]);
        $store->insert(['class' => 'RunningDurableTask', 'payload' => serialize('')], 1, new \DateTimeImmutable());
        self::getContainer()->get(TaskWorker::class)->handle(['class' => 'TestTaskClass', 'payload' => serialize('')]);
        $application = new Application(self::$kernel);

        $view = new CommandTester($application->find('task:failed:view'));
        $view->execute([]);
        $this->assertStringContainsString('TestTaskClass', $view->getDisplay());
        $this->assertStringNotContainsString('DurableTask', $view->getDisplay());

        new CommandTester($application->find('task:failed:clear'))->execute([]);
        $this->assertEqualsCanonicalizing(['WaitingDurableTask', 'RunningDurableTask'], array_map(static fn (FailedTask $task) => $task->getTask(), $this->rows()));
    }

    public function testTaskListCommand(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $cmd = $application->find('task:list');
        $cmdTester = new CommandTester($cmd);
        $cmdTester->execute([]);
        $cmdTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('AcmeFailedTask', $cmdTester->getDisplay());
        $this->assertStringContainsString('AcmeTask', $cmdTester->getDisplay());
    }

    /**
     * What the table holds now, read fresh: the store writes around the identity map.
     *
     * @return list<FailedTask>
     */
    private function rows(): array
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository(FailedTask::class)->findBy([], ['createdAt' => 'ASC']);
    }

    /** A row as a worker sees it mid-attempt: handed over, not failed. */
    private function storedRow(string $class, string $payload, int $attempt = 1): string
    {
        return self::getContainer()->get(FailedTaskRepository::class)
            ->insert(['class' => $class, 'payload' => $payload], $attempt, new \DateTimeImmutable());
    }

    private function initDatabase(KernelInterface $kernel): void
    {
        if ('test' !== $kernel->getEnvironment()) {
            throw new \LogicException('Execution only in Test environment possible!');
        }

        $entityManager = $kernel->getContainer()->get('doctrine')->getManager();
        $metaData = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropDatabase();
        $schemaTool->updateSchema($metaData);
    }
}
