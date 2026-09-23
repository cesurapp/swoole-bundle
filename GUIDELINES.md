# Swoole Bundle Usage Guidelines

## 1. Server Configuration

Configuration is defined in `config/packages/swoole.yaml`:

```yaml
swoole:
  entrypoint: public/index.php              # Required: Application entry point
  watch_dir: /config,/src,/templates        # Directories to watch (dev mode)
  watch_extension: '*.php,*.yaml,*.yml,*.twig'
  replace_http_client: true                 # Replace Symfony HTTP client with Swoole (see 6. for certificates)
  cron_worker: true                         # Enable cron job system (FailedTaskCron runs while task_worker is on)
  task_worker: true                         # Enable task/queue system (default: false)
  task_sync_mode: false                     # Sync mode for testing (default: async)
  process_worker: true                      # Enable custom process workers
  task_retry: [60, 300, 600, 1800]          # Seconds before each retry of a failed task (default: [600])
  task_redeliver_timeout: 3600              # Seconds before a running stored task counts as lost (min 60)
```

Environment variables override config (`.env` file):

```bash
# Worker Control: turns off a worker the config above enables, never turns one on
SERVER_WORKER_CRON=true                   # Default: 1
SERVER_WORKER_TASK=true                   # Default: 1
SERVER_WORKER_PROCESS=true                # Default: 1

# HTTP Server
SERVER_HTTP_HOST=0.0.0.0                  # Default: 0.0.0.0
SERVER_HTTP_PORT=80                       # Default: 80
SERVER_HTTP_MODE=2                        # SWOOLE_PROCESS (default: 2)
SERVER_HTTP_SOCK_TYPE=1                   # SWOOLE_SOCK_TCP (default: 1)

# Server Settings
SERVER_HTTP_SETTINGS_WORKER_NUM=4         # Default: CPU count
SERVER_HTTP_SETTINGS_TASK_WORKER_NUM=2    # Default: CPU count / 2
SERVER_HTTP_SETTINGS_LOG_LEVEL=4          # Default: 4 (SWOOLE_LOG_WARNING)
SERVER_HTTP_SETTINGS_MAX_WAIT_TIME=60     # Default: 60
SERVER_HTTP_SETTINGS_MAX_REQUEST=10000    # Default: 10000
SERVER_HTTP_SETTINGS_TASK_MAX_REQUEST=1000 # Default: 1000 (0 = unlimited)
SERVER_HTTP_SETTINGS_PACKAGE_MAX_LENGTH=15728640  # 15MB
SERVER_HTTP_SETTINGS_HTTP_COMPRESSION=true
SERVER_HTTP_SETTINGS_HEARTBEAT_CHECK_INTERVAL=5   # Default: 5 (a stop waits for the next check)
SERVER_HTTP_SETTINGS_HEARTBEAT_IDLE_TIME=180
```

Minimal example:

```yaml
swoole:
  entrypoint: public/index.php
```

## 2. Server Commands

```bash
# Start/Stop
bin/console server:start   # Start production server
bin/console server:stop    # Stop server
bin/console server:watch   # Development mode with file watching

# Cron Management
bin/console cron:list      # List all registered cron jobs
bin/console cron:run AcmeCron  # Run specific cron manually (no locking)

# Task Management
bin/console task:list           # List registered tasks
bin/console task:failed:view    # View failed tasks
bin/console task:failed:retry   # Give failed tasks their attempts back (run by the next FailedTaskCron)
bin/console task:failed:clear   # Clear failed tasks (unfinished durable tasks stay)
```

`server:stop` finds the server by `var/swoole.pid` (the server writes it on start) and stops it
gracefully: running requests end first, for up to `max_wait_time`.

Development vs Production:
- Use `server:watch` in development (enables file watching and auto-reload)
- Use `server:start` in production (no file watching, better performance)

Entry point requires modification in `public/index.php`:

```php
require_once dirname(__DIR__).'/vendor/cesurapp/swoole-bundle/src/Runtime/entrypoint.php';
require_once dirname(__DIR__).'/vendor/autoload_runtime.php';
```

## 3. Process Management

Processes are long-running workers that start with the server. Use for continuous operations like listening to Redis/Postgres notifications.

**When to use:**
- Redis SUBSCRIBE/LISTEN operations
- Postgres LISTEN/NOTIFY operations
- Message queue consumers
- Any continuous background operation

**Implementation:**

```php
namespace App\Process;

use Cesurapp\SwooleBundle\Process\AbstractProcessJob;

class RedisListenerProcess extends AbstractProcessJob
{
    public bool $ENABLE = true;       // Enable/disable process
    public bool $RESTART = true;      // Auto-restart when completed
    public int $RESTART_DELAY = 5;    // Seconds to wait before restart

    public function __construct(
        private readonly RedisClient $redis,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(): void
    {
        // Continuous operation
        $this->redis->subscribe(['channel'], function ($redis, $channel, $message) {
            // Handle message
        });
    }
}
```

Alternative: Implement `ProcessInterface` directly instead of extending `AbstractProcessJob`.

**Lifecycle:**
- Processes start automatically when server starts
- Each runs in isolated Swoole Process, registered with `Server::addProcess`: the server restarts
  a process that exits or crashes, and tasks can be dispatched from inside it
- One copy per job across instances (lock); the other instances wait on standby as failover. A
  copy whose lock cannot be refreshed stops and is restarted, then queues for the lock again
- Lock store: PostgreSQL advisory lock (`LOCK_DSN=postgresql+advisory://...`), connected directly,
  never through PgBouncer's transaction pooling. It never expires, however long the job blocks, and
  drops with its process. An expiring store (Redis, a plain `postgresql://` table) lasts 60s past
  its last refresh: a job that blocks its process longer lets a standby copy start
- A stop releases the lock at once; the lock is checked every 10s. A copy taking the lock over from
  another waits 15s before starting, so the other has noticed a lost lock and stopped
- Auto-restart of the job controlled by `$RESTART` and `$RESTART_DELAY`
- Set `$RESTART = false` for one-time initialization tasks — the finished process stays parked
  (exiting would only make the server restart it and run the job again)
- Services are dependency-injected via constructor — and the constructor runs in the master process,
  so open connections in `__invoke()`, never earlier

**Naming convention:** Suffix with `Process` (e.g., `RedisListenerProcess`)

## 4. Task & Queue Handling

Tasks are asynchronous background jobs dispatched to task workers.

**When to use:**
- Sending emails
- Image processing
- API calls
- Any non-blocking operation

**Task structure:**

```php
namespace App\Task;

use Cesurapp\SwooleBundle\Task\TaskInterface;

class SendEmailTask implements TaskInterface
{
    public function __construct(private readonly MailerInterface $mailer) {}

    public function __invoke(string $data): mixed
    {
        $payload = unserialize($data);
        $this->mailer->send($payload['email'], $payload['subject']);
        return 'success';
    }
}
```

**Dispatching tasks:**

```php
use Cesurapp\SwooleBundle\Task\TaskHandler;

class OrderController
{
    public function __construct(private readonly TaskHandler $taskHandler) {}

    public function create(): Response
    {
        // Dispatch by class name (string)
        $this->taskHandler->dispatch(SendEmailTask::class, [
            'email' => 'user@example.com',
            'subject' => 'Order Confirmation'
        ]);

        // Or dispatch by instance
        $this->taskHandler->dispatch(new SendEmailTask(...), [...]);
    }
}
```

**Where a dispatched task runs:** queued to the task workers from HTTP workers, cron and process
workers. Inside a task worker Swoole refuses `task()`, and when a queue attempt is refused the task
would be lost — in both cases `dispatch()` runs it inline instead, in the calling process, through
the same `TaskWorker::handle()` (failures are recorded and retried as usual). In the test
environment and with `task_sync_mode` every task runs inline.

**Durable tasks:** Swoole's task queue lives in memory. A task that is still queued or running when the
server stops (deploy, crash, SIGKILL) is gone. Pass `durable: true` when the work must survive that:

```php
$this->taskHandler->dispatch(TranscribeTask::class, ['call_id' => $id], durable: true);
```

- **Storage.** The task is written to the `failed_task` store before it is queued, and the request
  carries the row's `id` and `attempt`. The worker deletes the row on success. On failure it records
  the error, and `FailedTaskCron` retries the task like any failed one.
- **Lost runs.** A run handed over longer than `task_redeliver_timeout` ago is taken as lost: its
  worker was stopped or killed. It is marked `Lost: …` and retried at once (it has waited long
  enough), counting as an attempt. Keep the timeout above the longest durable task. After a deploy,
  a killed run comes back within that timeout plus a minute.
- **At-least-once.** A task can run twice: after a lost run, after a crash between its work and the
  delete, or after a redelivered trigger. Make the task idempotent, for example check whether the
  result already exists, or write it with `UPDATE … WHERE result IS NULL`.
- **Stale runs are fenced.** Every run carries its attempt number, so a run whose lease already ran
  out cannot overwrite the row of a newer one.
- **Never inline in the caller.** A long task must not take over an HTTP worker or a process. When
  the task cannot be handed over right away, the row waits for `FailedTaskCron`: Swoole refused it,
  there is no server (a console command), or the dispatch sits inside an open DB transaction.
  Inside a transaction a worker could not see the row until commit, and a rollback takes the row
  along. Inside a task worker the task runs inline with its id, as the pool would run it.
- **Sync mode** (`task_sync_mode`, the test environment) runs the task inline and writes no row.
- **Worker recycling.** A task worker that reaches `task_max_request` stops taking tasks and gives the
  ones still running `max_wait_time` seconds before it kills them. A durable task that runs longer
  than `max_wait_time` therefore dies in a recycle as well. Raise `max_wait_time`
  (`SERVER_HTTP_SETTINGS_MAX_WAIT_TIME`) above the longest one.
- **No transactions across network calls.** Task workers run coroutines that share one database
  connection, so never keep a transaction open across a network call.
- **Deploy all instances together.** A release before durable tasks deletes stored rows when it
  retries them.

**Error handling:**
- Failed tasks are automatically saved to database
- `task_retry` is the retry schedule: one delay (seconds) per retry, so `[60, 300, 600, 1800]` runs a
  task at most five times — retries 1, 5, 10 and 30 minutes after each failure — then it rests in
  the failed list. `[]` means no retries (a lost durable run is not restarted either)
- `FailedTaskCron` looks every minute, so a delay is late by up to a minute and values below 60 are
  refused
- Changing the list applies to rows already waiting: a shorter list ends their retries, a longer
  one revives rows that had run out — they run again within a minute
- A stored row is deleted only when its task succeeds — a retry lost to a restart runs again
- View failures: `bin/console task:failed:view`
- Manual retry: `bin/console task:failed:retry` (attempts and delay reset; runs within a minute)
- Anything a task throws is recorded, `Error` included. A `TypeError` used to kill the
  task worker outright, after which Swoole reports `No idle task worker is available`
  and the whole queue stalls

**Data constraints:**
- Payload must be serializable — scalars, arrays and objects are all fine
- Data is serialized/unserialized automatically (`serialize()` / `unserialize()`)
- Objects come back **detached**: an unserialized Doctrine entity has no EntityManager
  behind it and carries the values it had when the task was dispatched. Pass an id and
  reload inside the task when you need live data
- A payload that cannot be unserialized is rejected before the task runs and lands in
  `failed_task` with an explicit error, rather than failing deep inside the task
- `failed_task.payload` is stored base64-encoded: `serialize()` writes private and
  protected property names as `\0Class\0prop`, and a Postgres `text` column cannot carry
  NUL bytes — the row would be cut at the first one and come back unusable

## 5. Cron Jobs

A single scheduler process starts the cron jobs on time and gives every run a process of its own, so
a slow or blocking job never delays the others. Schedules are cron expressions (minute precision).

**Cron structure:**

```php
namespace App\Cron;

use Cesurapp\SwooleBundle\Cron\AbstractCronJob;

class CleanupCron extends AbstractCronJob
{
    public string $TIME = '@daily';     // Cron expression
    public bool $ENABLE = true;         // Enable/disable
    public int $TIMEOUT = 1200;         // Seconds before a run is stopped

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function __invoke(): void
    {
        // Job logic
        $this->em->createQuery('DELETE FROM OldRecords')->execute();
    }
}
```

**Schedule expressions:**

Standard cron expressions:
- `'0 2 * * *'` - Daily at 2:00 AM
- `'*/15 * * * *'` - Every 15 minutes
- `'0 0 * * 0'` - Weekly on Sunday

Predefined aliases:
- `@yearly` → `0 0 1 1 *`
- `@annually` → `0 0 1 1 *`
- `@monthly` → `0 0 1 * *`
- `@weekly` → `0 0 * * 0`
- `@daily` → `0 0 * * *`
- `@hourly` → `0 * * * *`
- `@EveryMinute` → `* * * * *`
- `@EveryMinute5` → `*/5 * * * *`
- `@EveryMinute10` → `*/10 * * * *`
- `@EveryMinute15` → `*/15 * * * *`
- `@EveryMinute30` → `*/30 * * * *`

Reference: https://crontab.guru

**Runs:**
- Each run is forked from the scheduler and ends when the job returns; the job runs in a coroutine
  (hooked I/O and the Swoole HTTP client work)
- A job whose previous run is still going is not started again; that run is skipped
- A scheduler that fell behind runs a due job once, not once for every missed minute
- The scheduler looks every 10 seconds, but starts jobs only on the minute; `TIMEOUT` (default
  1200s) stops an overdue run within 10 seconds: SIGTERM, then SIGKILL if it is still going 10
  seconds later
- Stopping the server stops the runs in progress
- The job's constructor runs in the scheduler: open connections in `__invoke()`, never earlier

**Locking:**
- Each run takes a distributed lock: one run at a time across servers
- The lock lasts `TIMEOUT` + 60s, so no other server starts the job while it runs
- A run that ends within its minute keeps the lock until that minute is over, so another server does
  not run the same minute again (with a lock store whose locks expire; one bound to its connection
  lets go when the run ends)

**Naming convention:** Suffix with `Cron` (e.g., `CleanupCron`)

## 6. HTTP Client Bridge

Swoole HTTP Client replaces Symfony's native HTTP client for coroutine compatibility.

**Purpose:**
- Enables coroutine-based async HTTP calls
- Compatible with Swoole's event loop
- Prevents blocking in Swoole workers

**Enable:**

```yaml
swoole:
  replace_http_client: true
```

**Usage (transparent):**

Standard Symfony HttpClient interface:

```php
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApiService
{
    public function __construct(private readonly HttpClientInterface $client) {}

    public function fetch(): array
    {
        $response = $this->client->request('GET', 'https://api.example.com/data');
        return $response->toArray();
    }
}
```

Autowired `HttpClientInterface` is automatically replaced with `SwooleBridge`.

**Supported options:**

```php
$client->request('POST', 'https://api.example.com', [
    'headers' => ['X-Custom' => 'value'],
    'json' => ['key' => 'value'],           // Auto-encoded as JSON
    'body' => 'raw body',
    'query' => ['param' => 'value'],
    'auth_bearer' => 'token',               // Bearer token
    'verify_peer' => true,                  // Verify the certificate and host name (default: off)
    'proxy' => 'http://user:pass@host:port', // HTTP proxy
    'proxy' => 'socks5://user:pass@host:port', // SOCKS5 proxy
    'extra' => ['custom' => 'data'],        // Custom metadata (for events)
]);
```

**Limitations:**
- `stream()` method is not implemented
- `withOptions()` returns same instance (no-op)
- Response streaming not supported

**Direct usage (advanced):**

```php
use Cesurapp\SwooleBundle\Client\SwooleClient;

$client = SwooleClient::create('https://api.example.com/endpoint')
    ->setMethod('POST')
    ->setHeaders(['Authorization' => 'Bearer token'])
    ->setJsonData(['key' => 'value'])
    ->setQuery(['filter' => 'active'])
    ->setRequiredSsl()                      // Verify the certificate and host name (default: off)
    ->execute();

echo $client->statusCode;
echo $client->body;
```

## 7. Conventions & Rules

**Naming conventions:**
- Cron jobs: `*Cron` suffix (e.g., `CleanupCron`)
- Tasks: `*Task` suffix (e.g., `SendEmailTask`)
- Processes: `*Process` suffix (e.g., `RedisListenerProcess`)

**Class requirements:**
- Crons: Implement `CronInterface` or extend `AbstractCronJob`
- Tasks: Implement `TaskInterface`
- Processes: Implement `ProcessInterface` or extend `AbstractProcessJob`
- All use `__invoke()` method for execution

**Auto-registration:**
- Services implementing interfaces are auto-tagged and lazy-loaded
- No manual service registration needed
- Dependency injection via constructor

**Do's:**
- Use tasks for async operations (emails, processing)
- Use processes for continuous operations (listeners)
- Raise `TIMEOUT` (default 1200s) on crons that can run longer; a job that never ends belongs in a process
- Enable `replace_http_client` in Swoole environment
- Use `server:watch` for development

**Don'ts:**
- Don't pass unserializable values in task payloads (closures, resources, PDO handles)
- Don't rely on a Doctrine entity in a payload being managed — it arrives detached
- Don't use blocking operations in workers
- Don't use native `sleep()` in coroutines (use `\Swoole\Coroutine::sleep()`)
- Don't run crons manually in production (use `cron:run` for testing only)
- Don't disable auto-restart for listener processes
- Don't use standard HTTP client in Swoole workers (enable bridge)

**Performance constraints:**
- `max_request` restarts workers after N requests (prevents memory leaks)
- `task_max_request` restarts task workers after N tasks
- Heartbeat closes idle connections automatically
- Process restart delay prevents rapid restart loops

**Common mistakes:**
- Forgetting to serialize/unserialize task payloads
- Opening a connection in a cron's constructor (it runs in the scheduler, and every run's fork would share it)
- Blocking operations in async context
- Disabling process restart for listeners (causes termination)
- Not dispatching critical tasks `durable: true`, or a `task_retry` too short to outlast an outage
