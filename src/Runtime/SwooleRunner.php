<?php

namespace Cesurapp\SwooleBundle\Runtime;

use Cesurapp\SwooleBundle\Cron\CronScheduler;
use Cesurapp\SwooleBundle\Process\ProcessWorker;
use Cesurapp\SwooleBundle\Runtime\SwooleServer\CronServer;
use Cesurapp\SwooleBundle\Runtime\SwooleServer\HttpServer;
use Cesurapp\SwooleBundle\Runtime\SwooleServer\ProcessServer;
use Cesurapp\SwooleBundle\Runtime\SwooleServer\TaskServer;
use Cesurapp\SwooleBundle\Task\TaskWorker;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\RunnerInterface;

class SwooleRunner implements RunnerInterface
{
    public static array $config = [
        'worker' => [
            'cron' => 1,
            'task' => 1,
            'process' => 1,
        ],
        'http' => [
            'host' => '0.0.0.0',
            'port' => 80,
            'mode' => SWOOLE_PROCESS,
            'sock_type' => SWOOLE_SOCK_TCP,
            'settings' => [
                'worker_num' => 8,
                'task_worker_num' => 8,
                'enable_static_handler' => false,
                'log_level' => SWOOLE_LOG_WARNING,
                'max_wait_time' => 60,
                'task_enable_coroutine' => true,
                'task_max_request' => 1000,
                'package_max_length' => 15 * 1024 * 1024,
                'http_compression' => true,
                'max_request' => 10000,
                // A stop waits for the next check, so it stays short.
                'heartbeat_check_interval' => 5,
                'heartbeat_idle_time' => 180,
            ],
        ],
    ];

    public function __construct(private readonly HttpKernelInterface $application, array $options)
    {
        self::$config['http']['settings']['worker_num'] = swoole_cpu_num();
        self::$config['http']['settings']['task_worker_num'] = ceil(swoole_cpu_num() / 2);

        self::$config = $this->replaceRuntimeEnv(self::$config);

        // A worker runs only when the bundle config has it too: SERVER_WORKER_* can turn one off, not
        // on. The cron worker runs whenever the task worker does, for FailedTaskCron.
        $kernel = clone $application;
        $kernel->boot(); // @phpstan-ignore-line
        $container = $kernel->getContainer(); // @phpstan-ignore-line
        $task = self::$config['worker']['task'] && 0 !== self::$config['http']['settings']['task_worker_num'] && $container->has(TaskWorker::class);
        self::$config['worker']['task'] = $task;
        self::$config['worker']['cron'] = (self::$config['worker']['cron'] || $task) && $container->has(CronScheduler::class);
        self::$config['worker']['process'] = self::$config['worker']['process'] && $container->has(ProcessWorker::class);
        if (!$task) {
            self::$config['http']['settings']['task_worker_num'] = 0;
        }

        self::$config['env'] = $_ENV[$options['env_var_name']];
        self::$config['debug'] = $options['debug'];
        self::$config['worker']['watch'] = (bool) ($_SERVER['watch'] ?? false);

        // server:start and server:stop find the running server by this file.
        $pidFile = SwooleProcess::pidFile($application->getProjectDir()); // @phpstan-ignore-line
        if (!is_dir(dirname($pidFile))) {
            mkdir(dirname($pidFile), 0777, true);
        }
        self::$config['http']['settings']['pid_file'] = $pidFile;

        // Setup Debug Mode MaxRequest
        if (self::$config['debug']) {
            self::$config['http']['settings']['max_request'] = 15;
        }
    }

    private function assignArrayByPath(array &$arr, string $path, mixed $value): void
    {
        $keys = explode('.', $path);

        foreach ($keys as $key) {
            $arr = &$arr[$key];
        }

        $arr = $value;
    }

    private function replaceRuntimeEnv(array $options): array
    {
        $opts = array_merge_recursive(
            array_filter($_ENV, static fn ($k) => str_starts_with($k, 'SERVER_WORKER'), ARRAY_FILTER_USE_KEY),
            array_filter($_ENV, static fn ($k) => str_starts_with($k, 'SERVER_HTTP'), ARRAY_FILTER_USE_KEY)
        );

        $parseType = static function (mixed $type) {
            if (is_numeric($type)) {
                return (int) $type;
            }

            return match ($type) {
                'false' => false,
                'true' => true,
                default => $type,
            };
        };

        foreach ($opts as $key => $value) {
            $this->assignArrayByPath($options, strtolower(preg_replace(['/SERVER_/', '/_/'], ['', '.'], $key, 2)), $parseType($value));
        }

        return $options;
    }

    public function run(): int
    {
        $httpServer = new HttpServer($this->application, self::$config);
        new TaskServer($this->application, $httpServer, self::$config);
        new CronServer($this->application, $httpServer, self::$config);
        new ProcessServer($this->application, $httpServer, self::$config);

        return (int) $httpServer->server->start();
    }
}
