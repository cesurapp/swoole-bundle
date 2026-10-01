<?php

namespace Cesurapp\SwooleBundle\Task;

use Cesurapp\SwooleBundle\Runtime\SwooleProcess;
use Cesurapp\SwooleBundle\Runtime\SwooleRunner;

/**
 * What the task broker and its executors run with: `task.settings` of the runtime config
 * (SERVER_TASK_SETTINGS_*), and where the broker's socket and queue.log are.
 */
final readonly class TaskSettings
{
    /**
     * @param int $concurrency      tasks an executor runs at once
     * @param int $maxMemory        bytes an executor may hold after a task before it starts afresh; 0 for no limit
     * @param int $maxExecutionTime seconds every task has from its start; a busy executor that takes no
     *                              task for that long is taken as hung and killed
     * @param int $shutdownGrace    seconds an executor has to finish its tasks on a server stop
     * @param int $logRotate        queue.log records between two rewrites of the file
     */
    public function __construct(
        public string $socket,
        public string $log,
        public int $concurrency = 1000,
        public int $maxMemory = 200 * 1024 * 1024,
        public int $maxExecutionTime = 600,
        public int $shutdownGrace = 30,
        public int $logRotate = 10000,
    ) {
    }

    public static function fromRuntime(string $projectDir): self
    {
        $settings = SwooleRunner::$config['task']['settings'];

        return new self(
            SwooleProcess::taskSocket($projectDir),
            SwooleProcess::taskLog($projectDir),
            max(1, (int) $settings['concurrency']),
            max(0, (int) $settings['max_memory']) * 1024 * 1024, // MB in the config
            max(1, (int) $settings['max_execution_time']),
            max(1, (int) $settings['shutdown_grace']),
            max(1, (int) $settings['log_rotate']),
        );
    }
}
