<?php

namespace Cesurapp\SwooleBundle\Cron;

use Cron\CronExpression;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;

class CronWorker
{
    private CronExpression $expression;

    public function __construct(private readonly ServiceLocator $locator, private readonly LoggerInterface $logger, private readonly LockFactory $lockFactory)
    {
        // Predefined Constants
        $aliases = [
            '@EveryMinute' => '* * * * *',
            '@EveryMinute5' => '*/5 * * * *',
            '@EveryMinute10' => '*/10 * * * *',
            '@EveryMinute15' => '*/15 * * * *',
            '@EveryMinute30' => '*/30 * * * *',
        ];

        foreach ($aliases as $alias => $expr) {
            if (!CronExpression::supportsAlias($alias)) {
                CronExpression::registerAlias($alias, $expr);
            }
        }

        $this->expression = new CronExpression('* * * * *');
    }

    /**
     * Runs one job, in the process CronScheduler forked for this run.
     *
     * The lock keeps the job to one run at a time across servers. A run that ends within its minute
     * ($slot) keeps the lock until that minute is over, so a server whose scheduler looks a moment
     * later does not run the same minute again. That holds with a lock store whose locks expire; one
     * bound to its connection lets go as soon as this process ends.
     */
    public function execute(string $cronClass, \DateTimeImmutable $slot): void
    {
        /** @var AbstractCronJob $cron */
        $cron = $this->locator->get($cronClass);
        $lock = $this->lockFactory->createLock($cronClass, $cron->TIMEOUT + 60, false);
        if (!$lock->acquire()) {
            return; // running elsewhere, or this minute already ran there
        }

        try {
            $this->logger->info('Cron Job Process: '.$cronClass);
            $cron();
            $this->logger->info('Cron Job Finish: '.$cronClass);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('Cron Job Failed: %s, exception: %s', $cronClass, $exception->getMessage()));
        } finally {
            // Expire a second early, so the next minute's run finds the lock free.
            $hold = $slot->modify('+1 minute')->getTimestamp() - time() - 1;

            try {
                $hold > 0 ? $lock->refresh($hold) : $lock->release();
            } catch (\Throwable $exception) {
                $this->logger->warning(sprintf('Cron Job Lock: %s, exception: %s', $cronClass, $exception->getMessage()));
            }
        }
    }

    /**
     * The job's next planned run after $from, or $from's own minute when it is due then and
     * $allowCurrent is set.
     */
    public function nextRunDate(AbstractCronJob $cron, \DateTimeImmutable $from, bool $allowCurrent = false): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromMutable(new CronExpression($cron->TIME)->getNextRunDate($from, 0, $allowCurrent));
    }

    /**
     * Get CRON Instance.
     */
    public function get(string $class): ?AbstractCronJob
    {
        if ($this->locator->has($class)) {
            /** @var AbstractCronJob $cron */
            $cron = $this->locator->get($class);

            $aliases = CronExpression::getAliases();
            $this->expression->setExpression($aliases[strtolower($cron->TIME)] ?? $cron->TIME);
            $cron->isDue = $this->expression->isDue();
            $cron->next = $this->expression->getNextRunDate();

            return $cron;
        }

        return null;
    }

    /**
     * All jobs, keyed by class.
     */
    public function getAll(): \Traversable
    {
        foreach ($this->locator->getProvidedServices() as $cronClass => $value) {
            yield $cronClass => $this->get($cronClass);
        }

        return null;
    }
}
