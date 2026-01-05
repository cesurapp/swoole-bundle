<?php

namespace Cesurapp\SwooleBundle\Runtime\SwooleServer;

use Swoole\WebSocket\Server;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class SocketServer extends Server
{
    public function initHandler(HttpKernelInterface $application): void
    {
        // WebSocket Handler
        $kernel = clone $application;
        $kernel->boot(); // @phpstan-ignore-line
        $container = $kernel->getContainer(); // @phpstan-ignore-line
        $handler = $container->get('websocket_handler');
        $handler->initServerEvents($this);
    }
}
