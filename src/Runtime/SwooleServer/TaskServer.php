<?php

namespace Cesurapp\SwooleBundle\Runtime\SwooleServer;

use Cesurapp\SwooleBundle\Task\TaskBroker;
use Cesurapp\SwooleBundle\Task\TaskExecutor;
use Swoole\Coroutine;
use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Registers the task broker and the task executors as server-managed processes (Server::addProcess),
 * in place of Swoole's task workers.
 *
 * Swoole has one max_wait_time for its HTTP and task workers alike: a task worker running a long
 * task was cut off with the HTTP workers' limit. User processes are never reloaded and have no
 * max_request, so the limit does not reach them; an executor guards itself instead (TaskExecutor).
 * The manager restarts either process whenever it exits.
 */
class TaskServer
{
    public function __construct(HttpKernelInterface $application, HttpServer $server, array $options)
    {
        if (!$options['worker']['task']) {
            return;
        }

        $server->server->addProcess(new Process(static function () use ($application) {
            $kernel = clone $application;
            $kernel->boot(); // @phpstan-ignore-line

            /** @var TaskBroker $broker */
            $broker = $kernel->getContainer()->get(TaskBroker::class); // @phpstan-ignore-line
            Process::signal(SIGTERM, static fn () => $broker->stop());
            $broker->run();

            // Its connections' coroutines may still wait: end without Swoole reporting a deadlock.
            Coroutine::set(['enable_deadlock_check' => false]);
            Timer::clearAll();
            Event::exit();
        }, false, 2, true));

        for ($i = 0; $i < $options['task']['settings']['worker_num']; ++$i) {
            $server->server->addProcess(new Process(static function () use ($application) {
                $kernel = clone $application;
                $kernel->boot(); // @phpstan-ignore-line
                $kernel->getContainer()->get(TaskExecutor::class)->run(); // @phpstan-ignore-line
            }, false, 2, true));
        }
    }
}
