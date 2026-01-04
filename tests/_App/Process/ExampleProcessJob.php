<?php

namespace Cesurapp\SwooleBundle\Tests\_App\Process;

use Cesurapp\SwooleBundle\Process\AbstractProcessJob;

class ExampleProcessJob extends AbstractProcessJob
{
    public bool $ENABLE = true;
    public bool $RESTART = true;
    public int $RESTART_DELAY = 10;

    public function __invoke(): void
    {
        // Example: Listen to Redis/Postgres or any continuous process
        echo 'ExampleProcessJob is running...'.PHP_EOL;

        // Simulate some work
        sleep(5);

        echo 'ExampleProcessJob completed one cycle.'.PHP_EOL;
    }
}
