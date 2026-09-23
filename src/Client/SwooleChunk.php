<?php

namespace Cesurapp\SwooleBundle\Client;

use Symfony\Contracts\HttpClient\ChunkInterface;

readonly class SwooleChunk implements ChunkInterface
{
    public function __construct(
        private bool $first = false,
        private bool $last = false,
        private string $content = '',
        private int $offset = 0,
    ) {
    }

    public function isTimeout(): bool
    {
        return false;
    }

    public function isFirst(): bool
    {
        return $this->first;
    }

    public function isLast(): bool
    {
        return $this->last;
    }

    public function getInformationalStatus(): ?array
    {
        return null;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getError(): ?string
    {
        return null;
    }
}
