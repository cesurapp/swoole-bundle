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
  replace_http_client: true # Replace Symfony HTTP Client to Swoole Client
  cron_worker: true # Enable Cron Worker Service
  task_worker: true # Enable Task Worker Service
  task_sync_mode: false # Enable SYNC Mode -> Default false
  process_worker: true # Enable Process Worker Service
  failed_task_retry: '@EveryMinute10'
  failed_task_attempt: 2 # Failed Task Retry Count
  websocket_handler: null # WebSocket Handler Class (optional)
```

__Server Environment: .env__
```dotenv
# Worker Configuration
#SERVER_WORKER_CRON=true # Run Cron Worker -> Default = 1
#SERVER_WORKER_TASK=true # Run Task Worker -> Default = 1
#SERVER_WORKER_PROCESS=true # Run Process Worker -> Default = 1

# HTTP Server Configuration
SERVER_HTTP_HOST=127.0.0.1 # Default = 0.0.0.0
SERVER_HTTP_PORT=9090 # Default = 80
#SERVER_HTTP_MODE=2 # SWOOLE_PROCESS -> Default = 2
#SERVER_HTTP_SOCK_TYPE=1 # SWOOLE_SOCK_TCP -> Default = 1
#SERVER_HTTP_SOCKET=false # Websocket Socket -> Default = false

# HTTP Server Settings
#SERVER_HTTP_SETTINGS_WORKER_NUM=2 # Default = CPU Count
#SERVER_HTTP_SETTINGS_TASK_WORKER_NUM=1 # Default = CPU Count / 2
#SERVER_HTTP_SETTINGS_ENABLE_STATIC_HANDLER=false # Default = false
#SERVER_HTTP_SETTINGS_LOG_LEVEL=4 # Details Openswoole\Constant LOG_LEVEL -> Default = 4 (SWOOLE_LOG_WARNING)
#SERVER_HTTP_SETTINGS_MAX_WAIT_TIME=60 # Default = 60
#SERVER_HTTP_SETTINGS_TASK_ENABLE_COROUTINE=true # Default = true
#SERVER_HTTP_SETTINGS_TASK_MAX_REQUEST=0 # Default = 0
#SERVER_HTTP_SETTINGS_PACKAGE_MAX_LENGTH=15728640 # 15MB -> Default = 15728640
#SERVER_HTTP_SETTINGS_HTTP_COMPRESSION=true # Default = true
#SERVER_HTTP_SETTINGS_MAX_REQUEST=10000 # Default = 10000
#SERVER_HTTP_SETTINGS_HEARTBEAT_CHECK_INTERVAL=60 # Default = 60
#SERVER_HTTP_SETTINGS_HEARTBEAT_IDLE_TIME=180 # Default = 180

# TCP Server Configuration
#SERVER_TCP_PORT=9502 # Default = 9502
```

### Server Commands
```shell
# Cron Commands
bin/console cron:list         # List cron jobs
bin/console cron:run AcmeCron # Run cron process one time, without locking.

# Server Commands
bin/console server:start  # Start http,cron,queue server
bin/console server:stop   # Stop http,cron,queue server
bin/console server:status # Status http,cron,queue server
bin/console server:watch  # Start http,cron,queue server for development mode (file watcher enabled)

# Task|Job Commands
bin/console task:list           # List registered tasks
bin/console task:failed:clear   # Clear all failed task
bin/console task:failed:retry   # Forced send all failed tasks to swoole task worker
bin/console task:failed:view    # Lists failed tasks
```

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

    public function __invoke(): void
    {
        // Cron job logic here
    }
}
```

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

### Create Process Worker
Process Worker allows you to create continuously running tasks in a separate process when the server starts. It's ideal for Redis LISTEN, Postgres LISTEN, or similar continuous listening commands.

**Features:**
- Each process runs as a separate Swoole Process
- Automatic restart support when the process completes
- Configurable restart delay
- Enable/Disable support

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
        
        // Process terminates when completed
    }
}
```

**Notes:**
- Each process runs as a separate Swoole Process, isolated from each other
- Processes start automatically when the server starts
- When `RESTART=true`, the process restarts after `RESTART_DELAY` seconds upon completion
- Processes must implement `ProcessInterface` (or extend `AbstractProcessJob`)
- Automatically registered in Symfony DI container with lazy loading support

### WebSocket Server
The bundle provides built-in WebSocket server support powered by Swoole. You can enable WebSocket functionality alongside the HTTP server to handle real-time bidirectional communication.

**Enable WebSocket:**

Configuration:
```yaml
# config/packages/swoole.yaml
swoole:
    websocket_handler: App\WebSocket\MyWebSocketHandler
```

Or via environment variable:
```bash
SERVER_HTTP_SOCKET=true  # Enable WebSocket support
```

**Create WebSocket Handler:**

Your WebSocket handler must implement the `initServerEvents` method to register Swoole WebSocket events:

```php
<?php

namespace App\WebSocket;

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class MyWebSocketHandler
{
    public function initServerEvents(Server $server): void
    {
        // WebSocket connection opened
        $server->on('open', function (Server $server, $request) {
            echo "Connection opened: {$request->fd}\n";
            $server->push($request->fd, json_encode([
                'type' => 'connected',
                'message' => 'Welcome to WebSocket server'
            ]));
        });

        // WebSocket message received
        $server->on('message', function (Server $server, Frame $frame) {
            echo "Received message from {$frame->fd}: {$frame->data}\n";
            
            // Echo back to sender
            $server->push($frame->fd, "Server received: {$frame->data}");
            
            // Broadcast to all connections
            foreach ($server->connections as $fd) {
                if ($server->isEstablished($fd)) {
                    $server->push($fd, "Broadcast: {$frame->data}");
                }
            }
        });

        // WebSocket connection closed
        $server->on('close', function (Server $server, int $fd) {
            echo "Connection closed: {$fd}\n";
        });
    }
}
```

**Advanced WebSocket Handler with Dependency Injection:**

```php
<?php

namespace App\WebSocket;

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Psr\Log\LoggerInterface;
use App\Service\ChatService;

class ChatWebSocketHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ChatService $chatService
    ) {
    }

    public function initServerEvents(Server $server): void
    {
        $server->on('open', function (Server $server, $request) {
            $this->logger->info('WebSocket connection opened', ['fd' => $request->fd]);
            
            // Authenticate user from request headers/cookies
            $token = $request->header['authorization'] ?? null;
            $user = $this->chatService->authenticateToken($token);
            
            if ($user) {
                $server->push($request->fd, json_encode([
                    'type' => 'auth_success',
                    'user' => $user
                ]));
            } else {
                $server->disconnect($request->fd);
            }
        });

        $server->on('message', function (Server $server, Frame $frame) {
            $this->logger->info('WebSocket message received', [
                'fd' => $frame->fd,
                'data' => $frame->data
            ]);
            
            $data = json_decode($frame->data, true);
            
            match ($data['type'] ?? null) {
                'chat_message' => $this->handleChatMessage($server, $frame->fd, $data),
                'typing' => $this->handleTyping($server, $frame->fd, $data),
                'ping' => $server->push($frame->fd, json_encode(['type' => 'pong'])),
                default => $this->logger->warning('Unknown message type', ['data' => $data])
            };
        });

        $server->on('close', function (Server $server, int $fd) {
            $this->logger->info('WebSocket connection closed', ['fd' => $fd]);
            $this->chatService->handleDisconnect($fd);
        });
    }

    private function handleChatMessage(Server $server, int $fd, array $data): void
    {
        $message = $this->chatService->saveMessage($fd, $data['message']);
        
        // Broadcast to room members
        foreach ($this->chatService->getRoomMembers($data['room_id']) as $memberId) {
            if ($server->isEstablished($memberId)) {
                $server->push($memberId, json_encode([
                    'type' => 'new_message',
                    'message' => $message
                ]));
            }
        }
    }

    private function handleTyping(Server $server, int $fd, array $data): void
    {
        // Notify room members about typing status
        foreach ($this->chatService->getRoomMembers($data['room_id']) as $memberId) {
            if ($memberId !== $fd && $server->isEstablished($memberId)) {
                $server->push($memberId, json_encode([
                    'type' => 'user_typing',
                    'user_id' => $data['user_id']
                ]));
            }
        }
    }
}
```

**Client-Side JavaScript Example:**

```javascript
const ws = new WebSocket('ws://127.0.0.1:9090');

ws.onopen = function(event) {
    console.log('Connected to WebSocket server');
    ws.send(JSON.stringify({
        type: 'chat_message',
        room_id: 1,
        message: 'Hello, World!'
    }));
};

ws.onmessage = function(event) {
    const data = JSON.parse(event.data);
    console.log('Received:', data);
    
    switch(data.type) {
        case 'new_message':
            displayMessage(data.message);
            break;
        case 'user_typing':
            showTypingIndicator(data.user_id);
            break;
    }
};

ws.onclose = function(event) {
    console.log('Disconnected from WebSocket server');
};

ws.onerror = function(error) {
    console.error('WebSocket error:', error);
};
```

**Available Swoole WebSocket Server Methods:**

```php
// Send message to specific connection
$server->push(int $fd, string $data, int $opcode = WEBSOCKET_OPCODE_TEXT): bool

// Check if connection is valid WebSocket connection
$server->isEstablished(int $fd): bool

// Disconnect connection
$server->disconnect(int $fd, int $code = SWOOLE_WEBSOCKET_CLOSE_NORMAL, string $reason = ''): bool

// Check if connection exists
$server->exist(int $fd): bool

// Get all connection IDs
$server->connections: Iterator

// Get connection info
$server->getClientInfo(int $fd): array|false
```

**Configuration Options:**

```bash
# Enable WebSocket
SERVER_HTTP_SOCKET=true

# WebSocket heartbeat (keep-alive)
SERVER_HTTP_SETTINGS_HEARTBEAT_CHECK_INTERVAL=60  # Check interval in seconds
SERVER_HTTP_SETTINGS_HEARTBEAT_IDLE_TIME=180      # Idle timeout in seconds

# Message size limit
SERVER_HTTP_SETTINGS_PACKAGE_MAX_LENGTH=15728640  # 15MB default
```

**Notes:**
- WebSocket server runs on the same port as HTTP server
- HTTP requests and WebSocket connections are handled simultaneously
- WebSocket handler is initialized once when server starts
- Each WebSocket connection has a unique file descriptor (fd)
- Use `$server->isEstablished($fd)` before pushing data to avoid errors
- WebSocket handler has access to Symfony DI container services
- Heartbeat mechanism automatically closes idle connections

### Timer-based Cron Jobs
For simple fixed-interval tasks, you can use timer-based cron jobs instead of cron expressions. This is more efficient for high-frequency operations.

```php
<?php

namespace App\Cron;

use Cesurapp\SwooleBundle\Cron\AbstractCronJob;

class MetricsCollectorCron extends AbstractCronJob
{
    // Timer interval in SECONDS (numeric value instead of cron expression)
    public string $TIME = '30';  // Runs every 30 seconds
    public bool $ENABLE = true;

    public function __construct(
        private readonly MetricsService $metrics
    ) {}

    public function __invoke(): void
    {
        $this->metrics->collect();
    }
}
```

**Timer vs Expression Cron:**
- Timer: Use numeric value in seconds (e.g., `'30'`, `'60'`, `'300'`)
- Expression: Use cron expression or predefined alias (e.g., `'@daily'`, `'*/5 * * * *'`)
- Timer-based crons are more efficient for simple fixed intervals
- Both types support locking mechanism to prevent concurrent execution

### Requirements
- PHP >= 8.4
- Symfony 8+
- Swoole Extension
- POSIX Extension
- PCNTL Extension

### License
MIT
