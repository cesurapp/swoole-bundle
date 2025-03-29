<?php

namespace Cesurapp\SwooleBundle\Runtime\SwooleServer;

use Swoole\Http\Server;
use Swoole\WebSocket\Server as WebSocketServer;

readonly class TcpServer
{
    public function __construct(HttpServer $server, private array $options)
    {
        $tcpServer = $server->server->addlistener('127.0.0.1', (int) $this->options['tcp']['port'], SWOOLE_SOCK_TCP);
        $tcpServer->set(['worker_num' => 1]);
        $tcpServer->on('receive', [$this, 'onReceive']);
    }

    /**
     * Handle TCP Request.
     */
    public function onReceive(Server|WebSocketServer $server, int $fd, int $fromId, string $command): void
    {
        $cmd = explode('::', $command);
        $server->send($fd, match ($cmd[0]) {
            'shutdown' => $this->cmdShutdown($server),
            'taskRetry' => $this->cmdTaskRetry($server, $cmd[1]),
            'getMetrics' => $this->cmdMetrics($server),
            default => 0,
        });
    }

    /**
     * Command Shutdown.
     */
    private function cmdShutdown(Server|WebSocketServer $server): int
    {
        $server->shutdown();

        return 1;
    }

    /**
     * Command Reload Tasks.
     */
    private function cmdTaskRetry(Server|WebSocketServer $server, string $cmd): int
    {
        $server->task(json_decode($cmd, true, 512, JSON_THROW_ON_ERROR));

        return 1;
    }

    /**
     * Command View Server Metrics.
     */
    private function cmdMetrics(Server|WebSocketServer $server): string
    {
        $options = $this->options;

        return json_encode([
            'server' => $options,
            'metrics' => $server->stats(),
        ], JSON_THROW_ON_ERROR);
    }
}
