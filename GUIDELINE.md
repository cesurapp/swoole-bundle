# Swoole Bundle Usage Guidelines

## 1. Server Configuration

Configuration is defined in `config/packages/swoole.yaml`:

```yaml
swoole:
  entrypoint: public/index.php              # Required: Application entry point
  watch_dir: /config,/src,/templates        # Directories to watch (dev mode)
  watch_extension: '*.php,*.yaml,*.yml,*.twig'
  replace_http_client: true                 # Replace Symfony HTTP client with Swoole
  cron_worker: true                         # Enable cron job system
  task_worker: true                         # Enable task/queue system
  task_sync_mode: false                     # Sync mode for testing (default: async)
  process_worker: true                      # Enable custom process workers
  failed_task_retry: '@EveryMinute10'       # Retry schedule for failed tasks
  failed_task_attempt: 2                    # Max retry attempts
  websocket_handler: null                   # WebSocket handler class (optional)
```

Environment variables override config (`.env` file):

```bash
# Worker Control
SERVER_WORKER_CRON=true                   # Default: 1
SERVER_WORKER_TASK=true                   # Default: 1
SERVER_WORKER_PROCESS=true                # Default: 1

# HTTP Server
SERVER_HTTP_HOST=0.0.0.0                  # Default: 0.0.0.0
SERVER_HTTP_PORT=80                       # Default: 80
SERVER_HTTP_MODE=2                        # SWOOLE_PROCESS (default: 2)
SERVER_HTTP_SOCK_TYPE=1                   # SWOOLE_SOCK_TCP (default: 1)
SERVER_HTTP_SOCKET=false                  # Enable WebSocket (default: false)

# Server Settings
SERVER_HTTP_SETTINGS_WORKER_NUM=4         # Default: CPU count
SERVER_HTTP_SETTINGS_TASK_WORKER_NUM=2    # Default: CPU count / 2
SERVER_HTTP_SETTINGS_LOG_LEVEL=4          # Default: 4 (SWOOLE_LOG_WARNING)
SERVER_HTTP_SETTINGS_MAX_WAIT_TIME=60     # Default: 60
SERVER_HTTP_SETTINGS_MAX_REQUEST=10000    # Default: 10000
SERVER_HTTP_SETTINGS_TASK_MAX_REQUEST=0   # Default: 0 (unlimited)
SERVER_HTTP_SETTINGS_PACKAGE_MAX_LENGTH=15728640  # 15MB
SERVER_HTTP_SETTINGS_HTTP_COMPRESSION=true
SERVER_HTTP_SETTINGS_HEARTBEAT_CHECK_INTERVAL=60
SERVER_HTTP_SETTINGS_HEARTBEAT_IDLE_TIME=180
```

Minimal example:

```yaml
swoole:
  entrypoint: public/index.php
```

## 2. Server Commands

```bash
# Start/Stop/Status
bin/console server:start   # Start production server
bin/console server:stop    # Stop server
bin/console server:status  # Check server status
bin/console server:watch   # Development mode with file watching

# Cron Management
bin/console cron:list      # List all registered cron jobs
bin/console cron:run AcmeCron  # Run specific cron manually (no locking)

# Task Management
bin/console task:list           # List registered tasks
bin/console task:failed:view    # View failed tasks
bin/console task:failed:retry   # Retry all failed tasks
bin/console task:failed:clear   # Clear failed task queue
```

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
- Each runs in isolated Swoole Process
- Auto-restart controlled by `$RESTART` and `$RESTART_DELAY`
- Set `$RESTART = false` for one-time initialization tasks
- Services are dependency-injected via constructor

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

**Error handling:**
- Failed tasks are automatically saved to database
- Retry mechanism controlled by `failed_task_retry` and `failed_task_attempt` config
- View failures: `bin/console task:failed:view`
- Manual retry: `bin/console task:failed:retry`

**Data constraints:**
- Payload must be serializable (string, int, bool, array)
- Objects are NOT supported in payload
- Data is serialized/unserialized automatically

## 5. Cron Jobs

Standard tick-based cron jobs run every minute and check schedule via cron expressions.

**Cron structure:**

```php
namespace App\Cron;

use Cesurapp\SwooleBundle\Cron\AbstractCronJob;

class CleanupCron extends AbstractCronJob
{
    public string $TIME = '@daily';     // Cron expression
    public bool $ENABLE = true;         // Enable/disable

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

**Locking:**
- Each cron uses distributed locking (1200s default)
- Prevents concurrent execution across multiple servers
- Lock is automatically released after completion

**Naming convention:** Suffix with `Cron` (e.g., `CleanupCron`)

## 6. Timer-based Cron Jobs

Timer-based crons run at fixed intervals without cron expression evaluation. More efficient for simple intervals.

**When to use timers:**
- Simple fixed intervals (e.g., every 5 seconds, every 30 seconds)
- High-frequency operations
- When cron expressions are unnecessary overhead

**Implementation:**

```php
namespace App\Cron;

use Cesurapp\SwooleBundle\Cron\AbstractCronJob;

class MetricsCollectorCron extends AbstractCronJob
{
    public string $TIME = '30';         // Interval in SECONDS (numeric)
    public bool $ENABLE = true;

    public function __construct(private readonly MetricsService $metrics) {}

    public function __invoke(): void
    {
        $this->metrics->collect();
    }
}
```

**Key differences from standard cron:**
- `$TIME` must be numeric (seconds) instead of cron expression
- Runs in separate timer process
- No expression parsing overhead
- Still uses locking mechanism

**Lifecycle:**
- Timer process initializes all numeric-TIME crons at startup
- Countdown decrements every 5 seconds
- Executes when countdown reaches 0
- Resets countdown after execution

## 7. WebSocket Support

WebSocket mode runs on the same port as HTTP server. HTTP and WS coexist.

**Enable WebSocket:**

Configuration:
```yaml
swoole:
  websocket_handler: App\WebSocket\ChatHandler
```

Or environment:
```bash
SERVER_HTTP_SOCKET=true
```

**Handler implementation:**

```php
namespace App\WebSocket;

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class ChatHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ChatService $chat
    ) {}

    public function initServerEvents(Server $server): void
    {
        $server->on('open', function (Server $server, $request) {
            echo "Connected: {$request->fd}\n";
            $server->push($request->fd, json_encode(['type' => 'welcome']));
        });

        $server->on('message', function (Server $server, Frame $frame) {
            $data = json_decode($frame->data, true);

            // Broadcast to all
            foreach ($server->connections as $fd) {
                if ($server->isEstablished($fd)) {
                    $server->push($fd, $frame->data);
                }
            }
        });

        $server->on('close', function (Server $server, int $fd) {
            echo "Disconnected: {$fd}\n";
        });
    }
}
```

**Required events:**
- `open` - Connection established
- `message` - Message received
- `close` - Connection closed

**Server API:**
```php
$server->push(int $fd, string $data): bool  // Send to specific connection
$server->isEstablished(int $fd): bool       // Check connection validity
$server->disconnect(int $fd): bool          // Force disconnect
$server->exist(int $fd): bool               // Check if connection exists
$server->connections                        // Iterator of all connection IDs
$server->getClientInfo(int $fd): array      // Get connection metadata
```

**Connection lifecycle:**
- Each connection receives unique file descriptor (`$fd`)
- Always check `isEstablished()` before `push()` to avoid errors
- Handler has access to Symfony DI services
- HTTP requests and WebSocket connections share the same port

**Heartbeat configuration:**
```bash
SERVER_HTTP_SETTINGS_HEARTBEAT_CHECK_INTERVAL=60  # Check every 60s
SERVER_HTTP_SETTINGS_HEARTBEAT_IDLE_TIME=180      # Disconnect after 180s idle
```

## 8. HTTP Client Bridge

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
    ->execute();

echo $client->statusCode;
echo $client->body;
```

## 9. Conventions & Rules

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
- Use timer crons for simple fixed intervals
- Check `isEstablished()` before WebSocket `push()`
- Enable `replace_http_client` in Swoole environment
- Use `server:watch` for development

**Don'ts:**
- Don't pass objects in task payloads (only serializable types)
- Don't use blocking operations in workers
- Don't use native `sleep()` in coroutines (use `\Swoole\Coroutine::sleep()`)
- Don't run crons manually in production (use `cron:run` for testing only)
- Don't disable auto-restart for listener processes
- Don't use standard HTTP client in Swoole workers (enable bridge)

**Performance constraints:**
- `max_request` restarts workers after N requests (prevents memory leaks)
- `task_max_request` restarts task workers after N tasks
- WebSocket heartbeat closes idle connections automatically
- Process restart delay prevents rapid restart loops

**Common mistakes:**
- Forgetting to serialize/unserialize task payloads
- Not checking WebSocket connection before push
- Using cron expressions for timer-based crons (must be numeric)
- Blocking operations in async context
- Disabling process restart for listeners (causes termination)
- Not configuring `failed_task_retry` for critical tasks
