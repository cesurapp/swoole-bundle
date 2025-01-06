<?php

namespace Cesurapp\SwooleBundle\Runtime\SwooleServer;

use Swoole\WebSocket\Server;
use Symfony\Component\HttpKernel\HttpKernelInterface;

trait SocketServer
{
    public function initWebSocket(HttpKernelInterface $application, Server $server): void
    {
        // WebSocket Handler
        $kernel = clone $application;
        $kernel->boot(); // @phpstan-ignore-line
        $container = $kernel->getContainer(); // @phpstan-ignore-line
        $handler = $container->get('websocket_handler');

        // Register Events
        $server->on('start', [$handler, 'onStart']);
        $server->on('open', [$handler, 'onOpen']);
        $server->on('message', [$handler, 'onMessage']);
        $server->on('close', [$handler, 'onClose']);
    }
}
