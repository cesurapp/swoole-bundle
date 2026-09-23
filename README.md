# Symfony Swoole Bundle

[![App Tester](https://github.com/cesurapp/swoole-bundle/actions/workflows/testing.yaml/badge.svg)](https://github.com/cesurapp/swoole-bundle/actions/workflows/testing.yaml)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?logo=Unlicense)](LICENSE.md)

Built-in Swoole http server, background jobs (Task), scheduled task (Cron) worker are available.
Failed jobs are saved in the database to be retried. Each server has built-in background task worker.
Scheduled tasks run simultaneously on all servers. It is not possible for tasks to run at the same time as locking is used.

### Install 
Required Symfony 8
```bash
composer req cesurapp/swoole-bundle
```

__Edit: public/index.php__
```php
...
require_once dirname(__DIR__).'/vendor/cesurapp/swoole-bundle/src/Runtime/entrypoint.php';
require_once dirname(__DIR__).'/vendor/autoload_runtime.php';
...
```

__Configuration:__
```yaml
# config/packages/swoole.yaml
swoole:
  entrypoint: public/index.php
  watch_dir: /config,/src,/templates
  watch_extension: '*.php,*.yaml,*.yml,*.twig'
  replace_http_client: true # Replace Symfony HTTP Client to Swoole Client (checks certificates only with verify_peer: true)
  cron_worker: true # Enable Cron Worker Service (FailedTaskCron runs while task_worker is on, even without it)
  task_worker: true # Enable Task Worker Service -> Default false
  task_sync_mode: false # Enable SYNC Mode -> Default false
  process_worker: true # Enable Process Worker Service
  task_retry: [60, 300, 600, 1800] # Seconds before each retry of a failed task (min 60) -> Default [600]
  task_redeliver_timeout: 3600 # Seconds before a running stored task counts as lost and runs again (min 60)
```

__Server Environment: .env__
```dotenv
# Worker Configuration: turns off a worker swoole.yaml enables, never turns one on
#SERVER_WORKER_CRON=true # Run Cron Worker -> Default = 1
#SERVER_WORKER_TASK=true # Run Task Worker -> Default = 1
#SERVER_WORKER_PROCESS=true # Run Process Worker -> Default = 1

# HTTP Server Configuration
SERVER_HTTP_HOST=127.0.0.1 # Default = 0.0.0.0
SERVER_HTTP_PORT=9090 # Default = 80
#SERVER_HTTP_MODE=2 # SWOOLE_PROCESS -> Default = 2
#SERVER_HTTP_SOCK_TYPE=1 # SWOOLE_SOCK_TCP -> Default = 1

# HTTP Server Settings
#SERVER_HTTP_SETTINGS_WORKER_NUM=2 # Default = CPU Count
#SERVER_HTTP_SETTINGS_TASK_WORKER_NUM=1 # Default = CPU Count / 2
#SERVER_HTTP_SETTINGS_ENABLE_STATIC_HANDLER=false # Default = false
#SERVER_HTTP_SETTINGS_LOG_LEVEL=4 # Details Openswoole\Constant LOG_LEVEL -> Default = 4 (SWOOLE_LOG_WARNING)
#SERVER_HTTP_SETTINGS_MAX_WAIT_TIME=60 # Default = 60
#SERVER_HTTP_SETTINGS_TASK_ENABLE_COROUTINE=true # Default = true
#SERVER_HTTP_SETTINGS_TASK_MAX_REQUEST=1000 # Restart task worker after N tasks, 0 = unlimited -> Default = 1000
#SERVER_HTTP_SETTINGS_PACKAGE_MAX_LENGTH=15728640 # 15MB -> Default = 15728640
#SERVER_HTTP_SETTINGS_HTTP_COMPRESSION=true # Default = true
#SERVER_HTTP_SETTINGS_MAX_REQUEST=10000 # Default = 10000
#SERVER_HTTP_SETTINGS_HEARTBEAT_CHECK_INTERVAL=5 # A stop waits for the next check -> Default = 5
#SERVER_HTTP_SETTINGS_HEARTBEAT_IDLE_TIME=180 # Default = 180
```

### Server Commands
```shell
# Cron Commands
bin/console cron:list         # List cron jobs
bin/console cron:run AcmeCron # Run cron process one time, without locking.

# Server Commands
bin/console server:start  # Start http,cron,queue server
bin/console server:stop   # Stop http,cron,queue server
bin/console server:watch  # Start http,cron,queue server for development mode (file watcher enabled)

# Task|Job Commands
bin/console task:list           # List registered tasks
bin/console task:failed:clear   # Clear all failed task (unfinished durable tasks stay)
bin/console task:failed:retry   # Give failed tasks their attempts back; FailedTaskCron runs them on its next run
bin/console task:failed:view    # Lists failed tasks
```

The running server keeps its master process id in `var/swoole.pid`. `server:stop` sends it SIGTERM
and waits while the running requests end (up to `max_wait_time`), then kills a server still up.

### Create Cron Job
You can use cron expression for scheduled tasks, or you can use predefined expressions.

```php
<?php

namespace App\Cron;

use Cesurapp\SwooleBundle\Cron\AbstractCronJob;

/**
 * Predefined Scheduling
 *
 * '@yearly'           => '0 0 1 1 *',
 * '@annually'         => '0 0 1 1 *',
 * '@monthly'          => '0 0 1 * *',
 * '@weekly'           => '0 0 * * 0',
 * '@daily'            => '0 0 * * *',
 * '@hourly'           => '0 * * * *',
 * '@EveryMinute'      => '* * * * *',
 * '@EveryMinute5'     => '*/5 * * * *',
 * '@EveryMinute10'    => '*/10 * * * *',
 * '@EveryMinute15'    => '*/15 * * * *',
 * '@EveryMinute30'    => '*/30 * * * *',
 */
class ExampleCron extends AbstractCronJob
{
    public string $TIME = '@EveryMinute10';
    public bool $ENABLE = true;
    public int $TIMEOUT = 1200; // Seconds a run may take before it is stopped

    public function __invoke(): void
    {
        // Cron job logic here
    }
}
```

**Notes:**
- One scheduler process starts the jobs on time and gives every run a process of its own: a slow
  or blocking job (a long query, say) holds up only its own run, never the other jobs
- A run's process lives as long as the run and opens its own connections; the job runs in a
  coroutine, like in any worker
- A job whose previous run is still going is not started again; that run is skipped
- `TIMEOUT` (default 1200 seconds) stops a run that takes longer; raise it for a longer job. The
  run's lock lasts `TIMEOUT` plus a minute, so no other server starts the job while it runs
- Stopping the server stops the runs in progress
- The job's constructor runs in the scheduler: open connections in `__invoke()`, never earlier

### Create Task (Background Job or Queue)
Data passed to tasks must be serializable (string, int, bool, array). Objects cannot be serialized directly.

Create Task:
```php
<?php

namespace App\Task;

use Cesurapp\SwooleBundle\Task\TaskInterface;

class ExampleTask implements TaskInterface
{
    public function __invoke(string $data): mixed
    {
        $payload = unserialize($data);

        var_dump(
            $payload['name'],
            $payload['invoke']
        );

        return 'Task completed';
    }
}
```

Dispatch Task:
```php
<?php

namespace App\Controller;

use App\Task\ExampleTask;
use Cesurapp\SwooleBundle\Task\TaskHandler;
use Symfony\Component\HttpFoundation\Response;

class ExampleController
{
    public function __construct(
        private readonly TaskHandler $taskHandler
    ) {}

    public function hello(): Response
    {
        $this->taskHandler->dispatch(ExampleTask::class, [
            'name' => 'Test',
            'invoke' => 'Data'
        ]);

        return new Response('Task dispatched');
    }
}
```

Durable Task:

Swoole keeps its task queue in memory, so a task still queued or running when the server stops is lost.
Pass `durable: true` for work that must survive a deploy or a crash. The task is written to the
`failed_task` store before it is queued, and its row is deleted only once the task succeeds.
`FailedTaskCron` runs it again after a failure (on the `task_retry` schedule) and after
`task_redeliver_timeout` if its worker died.

```php
$this->taskHandler->dispatch(TranscribeTask::class, ['call_id' => $id], durable: true);
```

- A durable task runs at least once, so make it idempotent.
- A durable task never runs inline in the caller. When it can't be queued right away it waits for
  `FailedTaskCron`. That happens when Swoole refuses it, when there is no server (a console
  command), or when the dispatch is inside an open database transaction, where a worker could not
  yet see its row.
- In sync mode (`task_sync_mode`, tests) it runs inline like any other task and writes no row.

### Create Process Worker
Process Worker allows you to create continuously running tasks in a separate process when the server starts. It's ideal for Redis LISTEN, Postgres LISTEN, or similar continuous listening commands.

**Features:**
- Each process runs as a separate, server-managed Swoole Process (`Server::addProcess`)
- Automatic restart support when the process completes
- Configurable restart delay
- Enable/Disable support
- One running copy across instances (lock), with the other instances on standby as failover
- Can dispatch tasks like any worker

**Configuration:**
```yaml
# config/packages/swoole.yaml
swoole:
    process_worker: true  # Default: true
```

Or via environment variable:
```bash
SERVER_WORKER_PROCESS=1  # Enable
SERVER_WORKER_PROCESS=0  # Disable
```

**Create Process Job:**

Use `ProcessInterface` or extend `AbstractProcessJob`:

```php
<?php

namespace App\Process;

use Cesurapp\SwooleBundle\Process\AbstractProcessJob;

class RedisListenerProcess extends AbstractProcessJob
{
    // Is process active?
    public bool $ENABLE = true;
    
    // Restart when process completes
    public bool $RESTART = true;
    
    // Wait time before restart (seconds)
    public int $RESTART_DELAY = 5;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(): void
    {
        $this->logger->info('Redis listener started');
        
        // Redis SUBSCRIBE command
        $this->redis->subscribe(['channel1', 'channel2'], function ($redis, $channel, $message) {
            $this->logger->info("Received message from {$channel}: {$message}");
            // Process here
        });
    }
}
```

**Postgres LISTEN Example:**

```php
<?php

namespace App\Process;

use Cesurapp\SwooleBundle\Process\AbstractProcessJob;
use Doctrine\DBAL\Connection;

class PostgresListenerProcess extends AbstractProcessJob
{
    public bool $ENABLE = true;
    public bool $RESTART = true;
    public int $RESTART_DELAY = 3;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(): void
    {
        $this->logger->info('Postgres listener started');
        
        // LISTEN command
        $this->connection->executeStatement('LISTEN my_channel');
        
        while (true) {
            // Wait for notification
            $notification = pg_get_notify($this->connection->getNativeConnection());
            
            if ($notification) {
                $this->logger->info('Received notification', [
                    'channel' => $notification['message'],
                    'payload' => $notification['payload']
                ]);
                
                // Process here
            }
            
            usleep(100000); // Wait 100ms
        }
    }
}
```

**One-Time Process (Without Restart):**

```php
<?php

namespace App\Process;

use Cesurapp\SwooleBundle\Process\AbstractProcessJob;

class OneTimeProcess extends AbstractProcessJob
{
    public bool $ENABLE = true;
    public bool $RESTART = false; // Restart disabled

    public function __invoke(): void
    {
        // One-time operation
        $this->doSomething();
        
        // The job is done; the process stays parked (see the notes below)
    }
}
```

**Notes:**
- Each process runs as a separate Swoole Process, isolated from each other
- Processes are registered with `Server::addProcess`: they start with the server, the server
  restarts one that exits or crashes, and `TaskHandler::dispatch()` works inside them
- One copy runs per job across all instances (the `process_server_<FQCN>` lock). The other
  instances' copies wait on standby and take over when that lock is released
- Use a PostgreSQL advisory lock for it: `LOCK_DSN=postgresql+advisory://...`, connected directly
  (PgBouncer's transaction pooling hands the lock's session to other clients, so two copies could
  run). It never expires, however long the job holds up its process, and drops with the process.
  With a store whose locks expire (Redis, a plain `postgresql://` table) the lock lasts 60 seconds
  past its last refresh, so a job that blocks its process longer lets a standby copy start
- A stop releases the lock at once. Each copy checks its lock every 10 seconds; one that lost it
  (e.g. its database session dropped) stops and the server restarts it
- A copy that takes the lock over from another waits 15 seconds before starting the job, so the
  other has noticed a lost lock and stopped
- When `RESTART=true`, the job runs again after `RESTART_DELAY` seconds upon completion (an
  exception or error counts as completion)
- When `RESTART=false`, the finished process stays parked while the server runs: exiting would
  only have the server restart it and run the job again
- The job's constructor runs in the master process (to read `ENABLE`): open connections in
  `__invoke()`, never earlier
- Processes must implement `ProcessInterface` (or extend `AbstractProcessJob`)
- Automatically registered in Symfony DI container with lazy loading support

### Requirements
- PHP >= 8.4
- Symfony 8+
- Swoole Extension
- POSIX Extension
- PCNTL Extension

### License
MIT
