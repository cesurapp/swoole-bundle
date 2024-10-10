<?php

namespace Cesurapp\SwooleBundle\Tests\_App\Task;

use Cesurapp\SwooleBundle\Task\TaskInterface;

class AcmeTask implements TaskInterface
{
    public function __invoke(mixed $data): void
    {
        echo $data;
    }
}
