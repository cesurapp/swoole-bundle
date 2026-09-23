<?php

namespace Cesurapp\SwooleBundle\Cron;

abstract class AbstractCronJob implements CronInterface
{
    /**
     * Cron Expression.
     *
     * Predefined Scheduling
     *
     * '@yearly'    => '0 0 1 1 *',
     * '@annually'  => '0 0 1 1 *',
     * '@monthly'   => '0 0 1 * *',
     * '@weekly'    => '0 0 * * 0',
     * '@daily'     => '0 0 * * *',
     * '@hourly'    => '0 * * * *',
     * '@EveryMinute'    => '* * * * *',
     * '@EveryMinute5'  => '*\/5 * * * *',
     * '@EveryMinute10'  => '*\/10 * * * *',
     * '@EveryMinute15'  => '*\/15 * * * *',
     * '@EveryMinute30'  => '*\/30 * * * *',
     *
     * @see https://crontab.guru
     */
    public string $TIME = '@daily';

    /**
     * Cron is Enable|Disable.
     */
    public bool $ENABLE = true;

    /**
     * Seconds a run may take before it is stopped.
     *
     * The run's lock lasts this long plus a minute, so no other server starts the job while it runs.
     */
    public int $TIMEOUT = 1200;

    public ?bool $isDue = null;
    public ?\DateTime $next = null;
}
