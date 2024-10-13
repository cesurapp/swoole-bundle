<?php

namespace Cesurapp\SwooleBundle\Client;

use Swoole\Coroutine\Http\Client;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Contracts\HttpClient\ResponseInterface;

readonly class SwooleResponse implements ResponseInterface
{
    public function __construct(private Client $client)
    {
    }

    public function getStatusCode(): int
    {
        return $this->client->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        return array_map(static fn ($c) => [$c], $this->client->getHeaders() ?? []);
    }

    public function getContent(bool $throw = true): string
    {
        return $this->client->getBody();
    }

    public function toArray(bool $throw = true): array
    {
        if ('' === $content = $this->getContent($throw)) {
            throw new JsonException('Response body is empty.');
        }

        try {
            $content = json_decode($content, true, 512, \JSON_BIGINT_AS_STRING | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new JsonException($e->getMessage().sprintf(' for "%s"', $content), $e->getCode());
        }

        if (!\is_array($content)) {
            throw new JsonException(sprintf('JSON content was expected to decode to an array, "%s" returned for "%s".', get_debug_type($content), $this->getInfo('url')));
        }

        return $content;
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        if ('debug' === $type) {
            return $this->client->getBody();
        }

        $info = get_object_vars($this->client);

        return $info[$type] ?? $info;
    }

    public function __toString(): string
    {
        return $this->client->body ?? '';
    }
}
