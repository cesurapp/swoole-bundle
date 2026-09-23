<?php

namespace Cesurapp\SwooleBundle\Task;

use Cesurapp\SwooleBundle\Cron\AbstractCronJob;
use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;
use Swoole\Server;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Runs the store's unfinished tasks, every minute: failures whose `task_retry` delay is over and
 * that have retries left, durable tasks nothing could take yet, and attempts whose worker died — an
 * attempt handed over longer than `task_redeliver_timeout` ago is taken as lost. Each row is
 * claimed before it is queued and stays until the task succeeds, so a restart in between loses
 * nothing.
 */
class FailedTaskCron extends AbstractCronJob
{
    public string $TIME = '@EveryMinute';

    private const int BATCH_SIZE = 50;

    /** Between two queued tasks: the only brake on a backlog, since the task workers take everything. */
    private const int PACE_MICROSECONDS = 5000;

    public function __construct(private readonly FailedTaskRepository $store, private readonly ParameterBagInterface $bag)
    {
    }

    public function __invoke(): void
    {
        /** @var Server $server */
        $server = $GLOBALS['httpServer'];

        $retries = count($this->bag->get('swoole.task_retry'));
        $timeout = (int) $this->bag->get('swoole.task_redeliver_timeout');

        $this->store->reap(
            new \DateTimeImmutable("-$timeout seconds"),
            sprintf('Lost: not finished within %d seconds, its worker was stopped or killed.', $timeout),
        );

        $after = null;
        do {
            $rows = $this->store->due($retries, self::BATCH_SIZE, $after);

            foreach ($rows as $row) {
                $after = $row['id'];
                if (!$this->store->claim($row['id'], $row['attempt'])) {
                    continue; // taken by another instance
                }

                $attempt = $row['attempt'] + 1;
                $accepted = $server->task([
                    'class' => $row['task'],
                    'payload' => $row['payload'],
                    'id' => $row['id'],
                    'attempt' => $attempt,
                ]);

                // Swoole took nothing: hand the attempt back and leave the rest for the next run.
                if (false === $accepted) {
                    $this->store->release($row['id'], $attempt);

                    return;
                }

                usleep(self::PACE_MICROSECONDS);
            }
        } while (self::BATCH_SIZE === count($rows));
    }
}
