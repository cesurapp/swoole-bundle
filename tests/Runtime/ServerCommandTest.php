<?php

namespace Cesurapp\SwooleBundle\Tests\Runtime;

use Cesurapp\SwooleBundle\Runtime\SwooleProcess;
use Cesurapp\SwooleBundle\Task\TaskBrokerClient;
use Cesurapp\SwooleBundle\Task\TaskSettings;
use Cesurapp\SwooleBundle\Tests\_App\Task\AcmeTask;
use Cesurapp\SwooleBundle\Tests\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ServerCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $_SERVER['KERNEL_CLASS'] = Kernel::class;
        $_ENV['SERVER_HTTP_PORT'] = 9090;
    }

    public function test1StopFail(): void
    {
        sleep(1);
        self::bootKernel();
        $application = new Application(self::$kernel);

        $cmd = $application->find('server:stop');
        $cmdTester = new CommandTester($cmd);
        $cmdTester->execute([]);
        dump($cmdTester->getDisplay());
        $this->assertStringContainsString('Swoole HTTP server not found!', $cmdTester->getDisplay());
    }

    public function test2StartStopSuccess(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        // Start
        $cmd = $application->find('server:start');
        $cmdTester = new CommandTester($cmd);
        $cmdTester->execute(['--detach' => true]);

        // The task broker takes tasks.
        sleep(2);
        $settings = TaskSettings::fromRuntime(self::$kernel->getProjectDir());
        $this->assertTrue(new TaskBrokerClient($settings)->send(['class' => AcmeTask::class, 'payload' => serialize('')]));

        // Stop
        $cmd = $application->find('server:stop');
        $cmdTester = new CommandTester($cmd);
        $cmdTester->execute([]);
        $this->assertStringContainsString('Swoole HTTP Server is Stopped!', $cmdTester->getDisplay());
        $this->assertStringNotContainsString('was killed', $cmdTester->getDisplay(), 'every process stops in time');
        $this->assertFileDoesNotExist(SwooleProcess::taskSocket(self::$kernel->getProjectDir()));
        sleep(1);
    }
}
