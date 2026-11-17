<?php

namespace Cesurapp\SwooleBundle\Client;

use Swoole\Coroutine\Http\Client;

/**
 * Swoole's coroutine client, noting a close(): a write_func ends a body read by closing the client, and a request
 * libcurl makes instead (SwooleClient, through an https proxy) learns it from this, the client never connecting there.
 */
class CoroutineHttpClient extends Client
{
    public bool $closed = false;

    public function close(): bool
    {
        $this->closed = true;

        return parent::close();
    }
}
