<?php

namespace Cesurapp\SwooleBundle\Task;

interface TaskInterface
{
    public function __invoke(mixed $data): void;
}
