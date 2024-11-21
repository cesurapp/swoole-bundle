<?php

namespace Cesurapp\SwooleBundle\Client;

use Symfony\Contracts\EventDispatcher\Event;

class ClientResponseEvent extends Event
{
    public function __construct(private readonly SwooleResponse $response)
    {
    }

    public function getResponse(): SwooleResponse
    {
        return $this->response;
    }
}
