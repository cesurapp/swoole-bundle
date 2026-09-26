<?php

namespace Cesurapp\SwooleBundle\Tests\Process;

use Cesurapp\SwooleBundle\Process\ProcessWorker;
use Cesurapp\SwooleBundle\Tests\_App\Process\ExampleProcessJob;
use Cesurapp\SwooleBundle\Tests\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;

class ProcessWorkerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $_SERVER['KERNEL_CLASS'] = Kernel::class;
    }

    public function testGetProcess(): void
    {
        self::bootKernel();
        $worker = self::getContainer()->get(ProcessWorker::class);

        $process = $worker->get(ExampleProcessJob::class);

        $this->assertInstanceOf(ExampleProcessJob::class, $process);
        $this->assertTrue($process->ENABLE);
        $this->assertTrue($process->RESTART);
        $this->assertEquals(10, $process->RESTART_DELAY);
    }

    public function testGetNonExistentProcess(): void
    {
        self::bootKernel();
        $worker = self::getContainer()->get(ProcessWorker::class);

        $process = $worker->get('NonExistentProcessClass');

        $this->assertNull($process);
    }

    public function testLockReleaseLetsNextCopyTakeOver(): void
    {
        self::bootKernel();
        /** @var ProcessWorker $worker */
        $worker = self::getContainer()->get(ProcessWorker::class);
        $nextCopy = new ProcessWorker(new ServiceLocator([]), self::getContainer()->get('logger'), self::getContainer()->get(LockFactory::class));

        $this->assertTrue($worker->lockAcquire(ExampleProcessJob::class));
        $this->assertFalse($nextCopy->lockAcquire(ExampleProcessJob::class)); // at once, no waiting

        $worker->lockRelease();
        $this->assertTrue($nextCopy->lockAcquire(ExampleProcessJob::class));
        $nextCopy->lockRelease();
    }

    /** The exception goes along as context, so the log line carries its trace and not only its message. */
    public function testLockReleaseFailureLogsTheException(): void
    {
        $exception = new \RuntimeException('store is down');
        $store = $this->createStub(PersistingStoreInterface::class);
        $store->method('delete')->willThrowException($exception);
        $logger = self::getContainer()->get('logger');
        $logger->enableDebug();
        $worker = new ProcessWorker(new ServiceLocator([]), $logger, new LockFactory($store));

        $this->assertTrue($worker->lockAcquire(ExampleProcessJob::class));
        $worker->lockRelease();

        // Lock::release() wraps the store's exception.
        $failed = array_find($logger->getLogs(), static fn (array $log) => str_starts_with($log['message'], 'Process lock release failed:'));
        $this->assertSame($exception, ($failed['context']['exception'] ?? null)?->getPrevious());
    }

    public function testGetAllProcesses(): void
    {
        self::bootKernel();
        $worker = self::getContainer()->get(ProcessWorker::class);

        $processes = iterator_to_array($worker->getAll());

        $this->assertNotEmpty($processes);
        $this->assertContainsOnlyInstancesOf(ExampleProcessJob::class, $processes);
    }
}
