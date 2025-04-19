<?php

namespace Cesurapp\SwooleBundle\Tests\_App\Task;

use Cesurapp\SwooleBundle\Task\TaskInterface;

class AcmeFailedTask implements TaskInterface
{
    public function __invoke(mixed $data): void
    {
        throw new \RuntimeException('acme task exception');
    }
}
