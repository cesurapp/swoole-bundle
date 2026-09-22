<?php

namespace Cesurapp\SwooleBundle\Runtime\SwooleServer;

use Cesurapp\SwooleBundle\Process\ProcessWorker;
use Swoole\Process;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Registers every enabled process job as a server-managed user process (Server::addProcess).
 *
 * Server-managed, not forked beside the server: a process forked before Server::start() is
 * outside the server's own set, so Server::task() is refused in it, and when it dies nothing
 * brings it back. Added through addProcess, a job can dispatch tasks like any worker, and the
 * manager restarts it whenever it exits — ProcessWorker relies on that for lock failover.
 */
class ProcessServer
{
    public function __construct(HttpKernelInterface $application, HttpServer $server, array $options)
    {
        if (!$options['worker']['process']) {
            return;
        }

        // Read the job list (and each job's ENABLE) in the master, before the server starts.
        $kernel = clone $application;
        $kernel->boot(); // @phpstan-ignore-line
        $worker = $kernel->getContainer()->get(ProcessWorker::class); // @phpstan-ignore-line

        foreach ($worker->getAll() as $process) {
            if (!$process->ENABLE) {
                continue;
            }

            $processClass = get_class($process);
            $server->server->addProcess(new Process(function (Process $childProcess) use ($application, $processClass) {
                $kernel = clone $application;
                $kernel->boot(); // @phpstan-ignore-line
                $worker = $kernel->getContainer()->get(ProcessWorker::class); // @phpstan-ignore-line
                $worker->run($childProcess->pid, $processClass);
            }, false, 2, true));
        }

        unset($worker, $kernel);
    }
}
