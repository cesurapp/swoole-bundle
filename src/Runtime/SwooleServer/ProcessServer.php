<?php

namespace Cesurapp\SwooleBundle\Runtime\SwooleServer;

use Cesurapp\SwooleBundle\Process\ProcessWorker;
use Swoole\Process;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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

                $worker->run($processClass);
            }, false, 2, true));
        }
    }
}
