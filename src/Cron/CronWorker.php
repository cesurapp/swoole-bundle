<?php

namespace Cesurapp\SwooleBundle\Cron;

use Cron\CronExpression;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;

class CronWorker
{
    private CronExpression $expression;
    private array $timerCrons = [];

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

    public function run(): void
    {
        foreach ($this->locator->getProvidedServices() as $cronClass => $value) {
            $cron = $this->get($cronClass);

            if (!isset($cron->isDue) || !$cron->ENABLE || !$cron->isDue) {
                continue;
            }

            // Lock
            $lock = $this->lockFactory->createLock($cronClass, 1200);
            if (!$lock->acquire()) {
                continue;
            }

            go(function () use ($cron, $lock, $cronClass) {
                try {
                    $this->logger->info('Cron Job Process: '.$cronClass);
                    $cron();
                    $this->logger->info('Cron Job Finish: '.$cronClass);
                } catch (\Exception $exception) {
                    $this->logger->error(
                        sprintf('Cron Job Failed: %s, exception: %s', $cronClass, $exception->getMessage())
                    );
                } finally {
                    $lock->release();
                }
            });
        }
    }

    /**
     * Get CRON Instance.
     */
    public function get(string $class): ?AbstractCronJob
    {
        if ($this->locator->has($class)) {
            /** @var AbstractCronJob $cron */
            $cron = $this->locator->get($class);
            if (is_numeric($cron->TIME)) {
                return $cron;
            }

            $aliases = CronExpression::getAliases();
            $this->expression->setExpression($aliases[strtolower($cron->TIME)] ?? $cron->TIME);
            $cron->isDue = $this->expression->isDue();
            $cron->next = $this->expression->getNextRunDate();

            return $cron;
        }

        return null;
    }

    public function getAll(bool $onlyExpression = false): \Traversable
    {
        foreach ($this->locator->getProvidedServices() as $cronClass => $value) {
            yield $this->get($cronClass);
        }

        return null;
    }

    public function runTimer(int $interval): void
    {
        foreach ($this->timerCrons as $cronClass => $time) {
            $this->timerCrons[$cronClass] = $time - $interval;
            if ($this->timerCrons[$cronClass] <= 0) {
                $cron = $this->get($cronClass);

                // Reset Timer
                $this->timerCrons[$cronClass] = (int) $cron->TIME;

                // Lock
                $lock = $this->lockFactory->createLock($cronClass, 1200);
                if (!$lock->acquire()) {
                    continue;
                }

                go(function () use ($cron, $lock, $cronClass) {
                    try {
                        $this->logger->info('Cron Job Process: '.$cronClass);
                        $cron();
                        $this->logger->info('Cron Job Finish: '.$cronClass);
                    } catch (\Exception $exception) {
                        $this->logger->error(
                            sprintf('Cron Job Failed: %s, exception: %s', $cronClass, $exception->getMessage())
                        );
                    } finally {
                        $lock->release();
                    }
                });
            }
        }
    }

    public function initTimerCron(): bool
    {
        foreach ($this->locator->getProvidedServices() as $cronClass => $value) {
            $cron = $this->locator->get($cronClass);
            if (is_numeric($cron->TIME) && $cron->ENABLE) {
                $this->timerCrons[$cronClass] = (int) $cron->TIME;
            }
        }

        return count($this->timerCrons) > 0;
    }
}
