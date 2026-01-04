<?php

namespace Cesurapp\SwooleBundle\Process;

interface ProcessInterface
{
    public function __invoke(): void;
}
