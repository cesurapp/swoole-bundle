<?php

namespace Cesurapp\SwooleBundle\Client;

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The Swoole client reads the whole body inside request(), so each response
 * streams as a first chunk, one data chunk holding the body, and a last chunk.
 */
class SwooleResponseStream implements ResponseStreamInterface
{
    private \Generator $chunks;

    /**
     * @param iterable<ResponseInterface> $responses
     */
    public function __construct(iterable $responses)
    {
        $this->chunks = (static function () use ($responses) {
            foreach ($responses as $response) {
                $content = $response->getContent(false);

                yield $response => new SwooleChunk(first: true);
                if ('' !== $content) {
                    yield $response => new SwooleChunk(content: $content);
                }
                yield $response => new SwooleChunk(last: true, offset: \strlen($content));
            }
        })();
    }

    public function key(): ResponseInterface
    {
        return $this->chunks->key();
    }

    public function current(): ChunkInterface
    {
        return $this->chunks->current();
    }

    public function next(): void
    {
        $this->chunks->next();
    }

    public function rewind(): void
    {
        $this->chunks->rewind();
    }

    public function valid(): bool
    {
        return $this->chunks->valid();
    }
}
