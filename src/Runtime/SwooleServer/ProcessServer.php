<?php

namespace Cesurapp\SwooleBundle\Runtime\SwooleServer;

use Cesurapp\SwooleBundle\Process\ProcessWorker;
use Swoole\Process;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ProcessServer
{
    public function __construct(HttpKernelInterface $application, array $options)
    {
        if (!$options['worker']['process']) {
            return;
        }

        // Get Process Worker
        $kernel = clone $application;
        $kernel->boot(); // @phpstan-ignore-line
        $worker = $kernel->getContainer()->get(ProcessWorker::class); // @phpstan-ignore-line

        // Create Custom Processes
        foreach ($worker->getAll() as $process) {
            if ($process->ENABLE) {
                $subProcess = new Process(function ($chilProc) use ($application, $process) {
                    // Get Process Worker
                    $kernel = clone $application;
                    $kernel->boot(); // @phpstan-ignore-line
                    $worker = $kernel->getContainer()->get(ProcessWorker::class); // @phpstan-ignore-line
                    $worker->run($chilProc->pid, get_class($process));
                }, false, 2, true);
                $subProcess->start();
            }
        }

        unset($worker, $kernel);
    }
}
