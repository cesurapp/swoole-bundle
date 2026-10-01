<?php

namespace Cesurapp\SwooleBundle\Task;

/**
 * The frames the task broker speaks over its unix socket, also the records of its queue.log: a
 * 4-byte big-endian length, then a one-byte type and the body. The length counts the type and the
 * body, so a frame is 4 + length bytes long.
 */
final class TaskFrame
{
    /** Producer → broker: a job, as its id and the serialized request. Nothing comes back. */
    public const string JOB = 'J';

    /** Executor → broker, its first frame: how many tasks it takes at once (pack N). */
    public const string HELLO = 'H';

    /** Executor → broker: room for n more tasks (pack N). */
    public const string READY = 'R';

    /** Broker → executor: a serialized request to run. */
    public const string TASK = 'T';

    /** Executor → broker: draining, send no more. Broker → executor: acknowledged, nothing follows. */
    public const string DRAIN = 'X';

    /** queue.log: a job arrived (id + serialized request). */
    public const string ADDED = 'A';

    /** queue.log: the job was handed to an executor (id). */
    public const string COMPLETED = 'C';

    /** Bytes of a job id, random and made by the producer. */
    public const int ID_LENGTH = 16;

    /** Bytes a serialized request may have. */
    public const int MAX_REQUEST = 16 * 1024 * 1024;

    /** Bytes a frame may have, the length included. */
    public const int MAX_FRAME = 4 + 1 + self::ID_LENGTH + self::MAX_REQUEST;

    /** Swoole's length check for these frames (Coroutine\Socket::setProtocol): recvPacket() returns whole frames. */
    public const array PROTOCOL = [
        'open_length_check' => true,
        'package_length_type' => 'N',
        'package_length_offset' => 0,
        'package_body_offset' => 4,
        'package_max_length' => self::MAX_FRAME,
    ];

    public static function encode(string $type, string $body = ''): string
    {
        return pack('N', 1 + strlen($body)).$type.$body;
    }

    /**
     * @return array{string, string} the type and the body
     */
    public static function decode(string $frame): array
    {
        return [$frame[4] ?? '', substr($frame, 5)];
    }

    /** The count a HELLO or READY frame carries. */
    public static function count(string $body): int
    {
        $value = strlen($body) >= 4 ? unpack('N', $body) : false;

        return false === $value ? 0 : (int) $value[1];
    }
}
