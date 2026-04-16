<?php

namespace Cesurapp\SwooleBundle\Task;

use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManagerInterface;
use Cesurapp\SwooleBundle\Cron\AbstractCronJob;
use Psr\Log\NullLogger;
use Swoole\Server;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class FailedTaskCron extends AbstractCronJob
{
    private const BATCH_SIZE = 50;

    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly ParameterBagInterface $bag)
    {
        $this->TIME = $this->bag->get('swoole.failed_task_retry');
    }

    public function __invoke(): void
    {
        /** @var Server $server */
        $server = $GLOBALS['httpServer'];

        $connection = $this->entityManager->getConnection();
        $connection->getConfiguration()->setMiddlewares([new Middleware(new NullLogger())]);

        $attempt = (int) $this->bag->get('swoole.failed_task_attempt');

        do {
            $rows = $connection->fetchAllAssociative(
                'SELECT id, task, payload, attempt FROM failed_task WHERE attempt < ? LIMIT '.self::BATCH_SIZE,
                [$attempt]
            );

            foreach ($rows as $row) {
                $server->task([
                    'class' => $row['task'],
                    'payload' => $row['payload'],
                    'attempt' => $row['attempt'] + 1,
                ]);
                usleep(10000);
            }

            if ([] !== $rows) {
                $ids = array_column($rows, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $connection->executeStatement("DELETE FROM failed_task WHERE id IN ($placeholders)", $ids);
            }
        } while (self::BATCH_SIZE === count($rows));
    }
}
