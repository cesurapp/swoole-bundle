<?php

namespace Cesurapp\SwooleBundle\Process;

abstract class AbstractProcessJob implements ProcessInterface
{
    /**
     * Process is Enable|Disable.
     */
    public bool $ENABLE = true;

    /**
     * Restart process after completion.
     */
    public bool $RESTART = false;

    /**
     * Sleep duration (in seconds) before restarting the process.
     * Only used when RESTART is true.
     */
    public int $RESTART_DELAY = 5;
}
