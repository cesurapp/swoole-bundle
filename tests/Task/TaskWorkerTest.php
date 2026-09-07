<?php

namespace Cesurapp\SwooleBundle\Tests\Task;

use Cesurapp\SwooleBundle\Cron\CronWorker;
use Cesurapp\SwooleBundle\Entity\FailedTask;
use Cesurapp\SwooleBundle\Task\TaskWorker;
use Cesurapp\SwooleBundle\Tests\_App\AcmePayload;
use Cesurapp\SwooleBundle\Tests\_App\Task\AcmeErrorTask;
use Cesurapp\SwooleBundle\Tests\_App\Task\AcmeFailedTask;
use Cesurapp\SwooleBundle\Tests\_App\Task\AcmeTask;
use Cesurapp\SwooleBundle\Tests\Kernel;
use Doctrine\ORM\Tools\SchemaTool;
use Swoole\Event;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
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

        $this->initDatabase(self::$kernel);
        $worker->handle(['class' => AcmeFailedTask::class, 'payload' => serialize('AcmeData')]);

        $this->assertTrue(str_contains(json_encode($logger->getLogs()), 'Failed Task:'));
    }

    public function testTaskFailedCronProcess(): void
    {
        /** @var TaskWorker $worker */
        $worker = self::getContainer()->get(TaskWorker::class);
        $logger = self::getContainer()->get('logger');
        $logger->enableDebug();

        // Failed Task
        $this->initDatabase(self::$kernel);
        $worker->handle(['class' => AcmeFailedTask::class, 'payload' => serialize('AcmeData')]);
        $this->assertTrue(str_contains(json_encode($logger->getLogs()), 'Failed Task:'));

        // Re Run Failed Task with Cron Process
        $worker = self::getContainer()->get(CronWorker::class);
        $worker->run();
        Event::wait();

        $this->assertTrue(str_contains(json_encode($logger->getLogs()), 'Cron Job Process:'));
        $this->assertTrue(str_contains(json_encode($logger->getLogs()), 'Cron Job Finish:'));

        $em = self::getContainer()->get('doctrine')->getManager();
        /** @var FailedTask $failedTask */
        $failedTask = $em->getRepository(FailedTask::class)->findAll()[0];
        $this->assertSame($failedTask->getException(), 'acme task exception');
        $this->assertSame($failedTask->getPayload(), serialize('AcmeData'));
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

        $this->initDatabase(self::$kernel);
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

        $this->initDatabase(self::$kernel);
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
        $this->initDatabase(self::$kernel);

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
        $this->initDatabase(self::$kernel);

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
        $this->initDatabase(self::$kernel);

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
