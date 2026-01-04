<?php

namespace Cesurapp\SwooleBundle\Runtime\SwooleServer;

use Cesurapp\SwooleBundle\Process\ProcessWorker;
use Psr\Log\LoggerInterface;
use Swoole\Process;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Lock\LockFactory;

class ProcessServer
{
    public function __construct(HttpKernelInterface $application, HttpServer $server, array $options)
    {
        if (!$options['worker']['process']) {
            return;
        }

        // Get all registered processes
        $kernel = clone $application;
        $kernel->boot(); // @phpstan-ignore-line
        $worker = $kernel->getContainer()->get(ProcessWorker::class); // @phpstan-ignore-line

        // Create a separate Swoole process for each registered process
        foreach ($worker->getAll() as $process) {
            if (!$process->ENABLE) {
                continue;
            }

            $processClass = get_class($process);
            $server->server->addProcess(new Process(function () use ($application, $processClass) {
                $kernel = clone $application;
                $kernel->boot(); // @phpstan-ignore-line
                $worker = $kernel->getContainer()->get(ProcessWorker::class); // @phpstan-ignore-line
                $lockFactory = $kernel->getContainer()->get(LockFactory::class); // @phpstan-ignore-line
                $logger = $kernel->getContainer()->get(LoggerInterface::class); // @phpstan-ignore-line

                // Lock: Birden fazla sunucuda çalışırken, lock'u ilk alan sunucu processleri çalıştırır
                $lock = $lockFactory->createLock('process_server_'.$processClass, 3600);
                if (!$lock->acquire()) {
                    $logger->info('Process lock could not be acquired, shutting down: '.$processClass);

                    return;
                }

                try {
                    $worker->run($processClass);
                } finally {
                    $lock->release();
                }
            }, false, 2, true));
        }
    }
}
