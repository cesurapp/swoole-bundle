<?php

namespace Cesurapp\SwooleBundle\Task;

use Psr\Log\LoggerInterface;

/**
 * The broker's queue.log. Every job it takes is appended (A) before anything else happens to it,
 * and marked (C) once it has been handed to an executor; the jobs without a C are the queue. What a
 * crash, a restart or a server stop leaves behind is read back on the next start (open()).
 *
 * Records are frames (TaskFrame), each written with a single unbuffered write: a crash loses at most
 * the record being written, and open() drops a torn last record.
 *
 * A producer that cannot reach the broker appends its job itself (append()), under an exclusive
 * flock. The broker reads what others appended (poll()) every second; its own records and theirs
 * land in the file in any order, so it tells them apart by id.
 *
 * The file is rewritten every `log_rotate` records: the pending jobs go to a new file, renamed over
 * the old one under the old one's lock, so the file never grows past what is pending plus one round
 * of records, and no producer's append is lost to the swap.
 */
final class TaskLog
{
    /** @var resource|null */
    private $handle;

    /** @var array<string, string> the jobs without a C: serialized request by id, oldest first */
    private array $pending = [];

    /** @var array<string, true> ids of the jobs this broker appended since its last read */
    private array $own = [];

    /** Bytes of the file read so far: whatever lies beyond is new. */
    private int $read = 0;

    /** Records in the file since it was last rewritten. */
    private int $records = 0;

    /** A write failed: the file may hold a torn record, the next poll() rewrites it. */
    private bool $damaged = false;

    /**
     * @param \Closure(string, string): void $arrived takes each job a producer appended: its id and request
     */
    public function __construct(
        private readonly string $path,
        private readonly int $rotate,
        private readonly LoggerInterface $logger,
        private readonly \Closure $arrived,
    ) {
    }

    /**
     * Appends a job from outside the broker: a producer that could not reach it. The lock keeps the
     * append out of a file the broker is replacing; a file replaced while this waited for its lock
     * is left for the new one.
     */
    public static function append(string $path, string $id, string $request): bool
    {
        $record = TaskFrame::encode(TaskFrame::ADDED, $id.$request);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $handle = @fopen($path, 'ab');
            if (false === $handle) {
                return false;
            }

            if (!flock($handle, LOCK_EX)) {
                fclose($handle);

                return false;
            }

            clearstatcache(true, $path);
            $current = @stat($path);
            $opened = fstat($handle);
            if (false !== $current && false !== $opened && $current['ino'] === $opened['ino']) {
                $written = @fwrite($handle, $record);
                fclose($handle); // releases the lock

                return strlen($record) === $written;
            }

            fclose($handle);
        }

        return false;
    }

    /**
     * Opens the file and returns the jobs it holds, oldest first. The file is rewritten with only
     * those, which also drops a torn last record and anything after a damaged one.
     *
     * @return array<string, string> serialized request by id
     */
    public function open(): array
    {
        if (!is_dir(dirname($this->path))) {
            @mkdir(dirname($this->path), 0777, true);
        }

        $handle = @fopen($this->path, 'c+b');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Task log %s cannot be opened.', $this->path));
        }

        flock($handle, LOCK_EX);
        [$records] = self::parse((string) stream_get_contents($handle));
        foreach ($records as [$type, $id, $request]) {
            if (TaskFrame::ADDED === $type) {
                $this->pending[$id] = $request;
            } else {
                unset($this->pending[$id]);
            }
        }

        $this->handle = $handle;
        if (!$this->rewrite()) {
            throw new \RuntimeException(sprintf('Task log %s cannot be rewritten.', $this->path));
        }

        return $this->pending;
    }

    /**
     * Appends a job; false when it is already pending (sent twice).
     */
    public function add(string $id, string $request): bool
    {
        if (isset($this->pending[$id])) {
            return false;
        }

        $this->pending[$id] = $request;
        $this->own[$id] = true;
        $this->write(TaskFrame::encode(TaskFrame::ADDED, $id.$request));

        return true;
    }

    /**
     * Marks a job handed to an executor.
     */
    public function complete(string $id): void
    {
        if (!isset($this->pending[$id])) {
            return;
        }

        unset($this->pending[$id]);
        $this->write(TaskFrame::encode(TaskFrame::COMPLETED, $id));
    }

    /**
     * Takes in what producers appended since the last look, and rewrites the file when it is due.
     */
    public function poll(): void
    {
        if (null === $this->handle) {
            return;
        }

        $this->read();
        if ($this->damaged || $this->due()) {
            $this->rotate();
        }
    }

    public function close(): void
    {
        if (null !== $this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * @return array<string, string>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    private function write(string $record): void
    {
        if (null === $this->handle) {
            return;
        }

        if (strlen($record) !== @fwrite($this->handle, $record)) {
            $this->damaged = true;
            $this->logger->critical(sprintf('Task log write failed: %s. The waiting tasks are kept in memory; the file is rewritten on the next try.', $this->path));

            return;
        }

        ++$this->records;
        if ($this->due()) {
            $this->rotate();
        }
    }

    /**
     * A rewrite costs a record per pending job, so it waits for at least as many new records: a
     * long queue is rewritten less often, never once per record.
     */
    private function due(): bool
    {
        return $this->records >= $this->rotate && $this->records >= count($this->pending);
    }

    /**
     * Rewrites the file under its lock, taking in the producers' appends first.
     */
    private function rotate(): void
    {
        if (null === $this->handle || !flock($this->handle, LOCK_EX)) {
            return;
        }

        $this->read();
        if (!$this->rewrite()) {
            flock($this->handle, LOCK_UN);
            $this->records = 0; // try again a round later, not on every record
        }
    }

    /**
     * Reads the new records past $read. A torn last record is left for the next read: a producer
     * may be in the middle of it. A malformed one (a failed write of the broker's) damages the file.
     */
    private function read(): void
    {
        $data = stream_get_contents($this->handle, -1, $this->read);
        if (false === $data || '' === $data) {
            return;
        }

        [$records, $length, $malformed] = self::parse($data);
        $this->read += $length;
        $this->damaged = $this->damaged || $malformed;

        foreach ($records as [$type, $id, $request]) {
            // Its own records, and a job sent twice.
            if (TaskFrame::ADDED !== $type || isset($this->own[$id]) || isset($this->pending[$id])) {
                continue;
            }

            $this->pending[$id] = $request;
            ++$this->records;
            ($this->arrived)($id, $request);
        }

        $this->own = [];
    }

    /**
     * Writes the pending jobs to a new file and moves it over the old one, whose handle (and lock)
     * goes with it. False when the new file could not be written; the old one stays.
     */
    private function rewrite(): bool
    {
        $temp = $this->path.'.tmp';
        $out = @fopen($temp, 'wb');
        $length = 0;
        $buffer = '';
        $ok = false !== $out;
        foreach ($ok ? $this->pending : [] as $id => $request) {
            $buffer .= TaskFrame::encode(TaskFrame::ADDED, $id.$request);
            if (strlen($buffer) >= 1048576) {
                $ok = strlen($buffer) === @fwrite($out, $buffer);
                $length += strlen($buffer);
                $buffer = '';
                if (!$ok) {
                    break;
                }
            }
        }

        if ($ok) {
            $ok = strlen($buffer) === @fwrite($out, $buffer) && fflush($out) && fsync($out);
            $length += strlen($buffer);
        }

        if (false !== $out) {
            fclose($out);
        }

        $handle = $ok && rename($temp, $this->path) ? @fopen($this->path, 'a+b') : false;
        if (false === $handle) {
            @unlink($temp);
            $this->logger->critical(sprintf('Task log rewrite failed: %s. The waiting tasks are kept in memory.', $this->path));

            return false;
        }

        stream_set_write_buffer($handle, 0);
        if (null !== $this->handle) {
            fclose($this->handle);
        }

        $this->handle = $handle;
        $this->read = $length;
        $this->records = 0;
        $this->own = [];
        $this->damaged = false;

        return true;
    }

    /**
     * @return array{list<array{string, string, string}>, int, bool} the whole records (type, id,
     *                                                               request), the bytes they span,
     *                                                               and whether a malformed one
     *                                                               ended the read
     */
    private static function parse(string $data): array
    {
        $records = [];
        $offset = 0;
        $size = strlen($data);
        $idEnd = 1 + TaskFrame::ID_LENGTH;

        while ($offset + 5 <= $size) {
            $header = unpack('N', $data, $offset);
            $length = false === $header ? 0 : (int) $header[1];
            $type = $data[$offset + 4];
            $valid = match ($type) {
                TaskFrame::ADDED => $length > $idEnd && $length <= $idEnd + TaskFrame::MAX_REQUEST,
                TaskFrame::COMPLETED => $length === $idEnd,
                default => false,
            };

            if (!$valid) {
                return [$records, $offset, true];
            }

            if ($offset + 4 + $length > $size) {
                break;
            }

            $records[] = [$type, substr($data, $offset + 5, TaskFrame::ID_LENGTH), substr($data, $offset + 4 + $idEnd, $length - $idEnd)];
            $offset += 4 + $length;
        }

        return [$records, $offset, false];
    }
}
