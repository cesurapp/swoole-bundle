<?php

namespace Cesurapp\SwooleBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Cesurapp\SwooleBundle\Entity\FailedTask;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\UuidV7;

/**
 * The task store (see FailedTask for the states).
 *
 * Every write here is a single DQL statement, or an insert built from the entity's metadata — never
 * persist/flush. A flush would also write whatever the failed task left dirty in the shared
 * EntityManager, and it is refused outright once the task has closed the manager; neither must
 * keep a failure from being recorded. The metadata, not hard-coded names, keeps the SQL right under
 * any naming strategy.
 *
 * @method FailedTask|null find($id, $lockMode = null, $lockVersion = null)
 * @method FailedTask|null findOneBy(array $criteria, array $orderBy = null)
 * @method FailedTask[]    findAll()
 * @method FailedTask[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 *
 * @extends ServiceEntityRepository<FailedTask>
 */
class FailedTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FailedTask::class);
    }

    /**
     * Failed tasks at rest: the list an operator reads. Durable tasks that have not failed, and
     * retries still running, are not in it.
     */
    public function failedQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('q')
            ->andWhere("q.exception <> ''")
            ->andWhere('q.deliveredAt IS NULL');
    }

    /**
     * Get Failed Tasks.
     */
    public function getFailedTask(?FailedTask $nextTask = null, int $limit = 10): QueryBuilder
    {
        $query = $this->failedQuery()
            ->orderBy('q.id', \SortDirection::Descending)
            ->setMaxResults($limit);

        if ($nextTask) {
            $query->andWhere('q.id < :next')->setParameter('next', $nextTask->getId(), UuidType::NAME);
        }

        return $query;
    }

    /**
     * Deletes the failed tasks at rest; unfinished durable work stays.
     */
    public function clearFailed(): int
    {
        return $this->failedQuery()->delete()->getQuery()->execute();
    }

    /**
     * Gives every failed task at rest its attempts back: FailedTaskCron runs them within a minute.
     */
    public function retryFailed(): int
    {
        return $this->dql("UPDATE %s f SET f.attempt = 0, f.availableAt = NULL WHERE f.exception <> '' AND f.deliveredAt IS NULL")->execute();
    }

    /**
     * Resolve Task.
     */
    public function resolveTask(FailedTask $task): void
    {
        $this->getEntityManager()->remove($task);
        $this->getEntityManager()->flush();
        $this->getEntityManager()->detach($task);
    }

    /**
     * Records the failure of a task that had no row: its first run. $availableAt is when it may
     * be retried.
     */
    public function createTask(array $taskRequest, \Throwable $exception, ?\DateTimeImmutable $availableAt = null): void
    {
        $this->insert($taskRequest, (int) ($taskRequest['attempt'] ?? 1), null, self::message($exception), $availableAt);
    }

    /**
     * Writes a row and returns its id. $deliveredAt set means the attempt is being handed to a
     * worker right now; null leaves the row for FailedTaskCron, from $availableAt on.
     */
    public function insert(array $taskRequest, int $attempt = 0, ?\DateTimeImmutable $deliveredAt = null, string $exception = '', ?\DateTimeImmutable $availableAt = null): string
    {
        $metadata = $this->getClassMetadata();
        $id = UuidV7::v7();
        $payload = $taskRequest['payload'] ?? null;

        $data = $types = [];
        foreach ([
            'id' => $id,
            'task' => (string) ($taskRequest['class'] ?? ''),
            'payload' => is_string($payload) ? base64_encode($payload) : null, // see FailedTask::$payload
            'exception' => $exception,
            'attempt' => $attempt,
            'createdAt' => new \DateTime(),
            'deliveredAt' => $deliveredAt,
            'availableAt' => $availableAt,
        ] as $field => $value) {
            $column = $metadata->getColumnName($field);
            $data[$column] = $value;
            $types[$column] = $metadata->getTypeOfField($field);
        }

        $this->getEntityManager()->getConnection()->insert($metadata->getTableName(), $data, $types);

        return $id->toRfc4122();
    }

    /**
     * The task succeeded: its row goes, whichever attempt finished first.
     */
    public function complete(string $id): void
    {
        $this->dql('DELETE FROM %s f WHERE f.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->execute();
    }

    /**
     * The attempt failed: the row waits for a retry from $availableAt on. A stale attempt — one the
     * row has moved past after its lease ran out — changes nothing.
     */
    public function fail(string $id, int $attempt, \Throwable $exception, ?\DateTimeImmutable $availableAt = null): void
    {
        $this->dql('UPDATE %s f SET f.exception = :exception, f.deliveredAt = NULL, f.availableAt = :availableAt WHERE f.id = :id AND f.attempt = :attempt')
            ->setParameter('exception', self::message($exception))
            ->setParameter('availableAt', $availableAt, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('attempt', $attempt)
            ->execute();
    }

    /**
     * Swoole did not take the attempt: undo it, so it neither counts nor waits for its lease.
     */
    public function release(string $id, int $attempt): void
    {
        $this->dql('UPDATE %s f SET f.attempt = f.attempt - 1, f.deliveredAt = NULL WHERE f.id = :id AND f.attempt = :attempt')
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('attempt', $attempt)
            ->execute();
    }

    /**
     * Attempts handed over before $expiry are lost (the worker was stopped or killed): they become
     * failures, retried like any other — or shown, when that was the last attempt.
     */
    public function reap(\DateTimeImmutable $expiry, string $message): int
    {
        return $this->dql('UPDATE %s f SET f.exception = :message, f.deliveredAt = NULL WHERE f.deliveredAt <= :expiry')
            ->setParameter('message', $message)
            ->setParameter('expiry', $expiry, Types::DATETIME_IMMUTABLE)
            ->execute();
    }

    /**
     * Rows nothing runs, with attempts left and their retry delay over — in id order after $after,
     * so a sweep reads on from its last row instead of walking past the rows it already took.
     *
     * @return list<array{id: string, task: string, payload: ?string, attempt: int}>
     */
    public function due(int $maxRetries, int $limit, ?string $after = null): array
    {
        $query = $this->dql(sprintf(
            'SELECT f.id, f.task, f.payload, f.attempt FROM %%s f WHERE f.deliveredAt IS NULL AND f.attempt <= :retries AND (f.availableAt IS NULL OR f.availableAt <= :now)%s ORDER BY f.id',
            null === $after ? '' : ' AND f.id > :after',
        ))
            ->setParameter('retries', $maxRetries)
            ->setParameter('now', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setMaxResults($limit);

        if (null !== $after) {
            $query->setParameter('after', $after, UuidType::NAME);
        }

        $rows = $query->getArrayResult();

        return array_map(static function (array $row) {
            $payload = null === $row['payload'] ? null : base64_decode($row['payload'], true);

            return [
                'id' => (string) $row['id'],
                'task' => $row['task'],
                // Undecodable → null: TaskWorker refuses it with a clear error instead of running.
                'payload' => false === $payload ? null : $payload,
                'attempt' => (int) $row['attempt'],
            ];
        }, $rows);
    }

    /**
     * Takes a due row for its next attempt. False when someone else took it first.
     */
    public function claim(string $id, int $attempt): bool
    {
        return 1 === $this->dql('UPDATE %s f SET f.attempt = f.attempt + 1, f.deliveredAt = :now WHERE f.id = :id AND f.attempt = :attempt AND f.deliveredAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('attempt', $attempt)
            ->execute();
    }

    /**
     * Whether a write now would sit in an uncommitted transaction, out of a worker's sight.
     */
    public function inTransaction(): bool
    {
        return $this->getEntityManager()->getConnection()->isTransactionActive();
    }

    private function dql(string $dql): Query
    {
        return $this->getEntityManager()->createQuery(sprintf($dql, FailedTask::class));
    }

    private static function message(\Throwable $exception): string
    {
        // Never '' — that would read as "not failed".
        return $exception->getMessage() ?: $exception::class;
    }
}
