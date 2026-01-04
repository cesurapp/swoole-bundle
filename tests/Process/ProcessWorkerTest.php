<?php

namespace Cesurapp\SwooleBundle\Tests\Process;

use Cesurapp\SwooleBundle\Process\ProcessWorker;
use Cesurapp\SwooleBundle\Tests\_App\Process\ExampleProcessJob;
use Cesurapp\SwooleBundle\Tests\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ProcessWorkerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $_SERVER['KERNEL_CLASS'] = Kernel::class;
    }

    public function testProcessWorker(): void
    {
        $this->assertTrue(self::getContainer()->has(ProcessWorker::class));

        /** @var ProcessWorker $worker */
        $worker = self::getContainer()->get(ProcessWorker::class);
        $this->assertInstanceOf(ProcessWorker::class, $worker);
    }

    public function testProcessServiceLocator(): void
    {
        /** @var ProcessWorker $worker */
        $worker = self::getContainer()->get(ProcessWorker::class);

        $process = $worker->get(ExampleProcessJob::class);
        $this->assertInstanceOf(ExampleProcessJob::class, $process);
        $this->assertTrue($process->ENABLE);
        $this->assertTrue($process->RESTART);
        $this->assertSame(10, $process->RESTART_DELAY);
    }

    public function testGetAllProcesses(): void
    {
        /** @var ProcessWorker $worker */
        $worker = self::getContainer()->get(ProcessWorker::class);

        $processes = iterator_to_array($worker->getAll());
        $this->assertNotEmpty($processes);
        $this->assertContainsOnlyInstancesOf(ExampleProcessJob::class, $processes);
    }

    public function testProcessNotFound(): void
    {
        /** @var ProcessWorker $worker */
        $worker = self::getContainer()->get(ProcessWorker::class);
        $logger = self::getContainer()->get('logger');
        $logger->enableDebug();

        $worker->run('NonExistentProcess');

        $logs = json_encode($logger->getLogs());
        $this->assertTrue(str_contains($logs, 'Process not found:'));
    }
}
