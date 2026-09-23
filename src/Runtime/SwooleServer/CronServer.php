<?php

namespace Cesurapp\SwooleBundle\Runtime\SwooleServer;

use Cesurapp\SwooleBundle\Cron\CronScheduler;
use Swoole\Process;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Registers the cron scheduler as a server-managed process (Server::addProcess).
 *
 * The scheduler forks a process for every run, so its own process runs without coroutines: Swoole
 * refuses to fork inside a coroutine. The runs have coroutines of their own (see CronScheduler).
 */
class CronServer
{
    public function __construct(HttpKernelInterface $application, HttpServer $server, array $options)
    {
        if (!$options['worker']['cron']) {
            return;
        }

        $server->server->addProcess(new Process(function () use ($application) {
            $kernel = clone $application;
            $kernel->boot(); // @phpstan-ignore-line
            $kernel->getContainer()->get(CronScheduler::class)->run(); // @phpstan-ignore-line
        }, false, 2, false));
    }
}
