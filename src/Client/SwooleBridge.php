<?php

namespace Cesurapp\SwooleBundle\Client;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class SwooleBridge implements HttpClientInterface
{
    public static ?array $clients = null;

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $client = SwooleClient::create($url)->setMethod($method)->setOptions($options);
        $extra = $options['extra'] ?? [];

        if (isset($options['headers'])) {
            $client->setHeaders($options['headers']);
        }
        if (isset($options['json'])) {
            $client->setJsonData($options['json']);
        }
        if (isset($options['body'])) {
            $client->setData($options['body']);
        }
        if (isset($options['query'])) {
            $client->setQuery($options['query']);
        }
        if (isset($options['proxy']) && is_string($options['proxy'])) {
            $parsed = parse_url($options['proxy']);

            // Socket5
            if ('socks5' === $parsed['scheme']) {
                $client->setSock5Proxy($parsed['host'], $parsed['port'], $parsed['user'] ?? null, $parsed['pass'] ?? null);
            }

            // HTTP
            if ('http' === $parsed['scheme']) {
                $client->setProxy($parsed['host'], $parsed['port'], $parsed['user'] ?? null, $parsed['pass'] ?? null);
            }
        }
        if (isset($options['auth_bearer'])) {
            $client->setHeaders(['Authorization' => 'Bearer '.$options['auth_bearer']]);
        }
        if (isset($options['extra'])) {
            $client->setJsonData($options['json']);
        }

        $response = new SwooleResponse($client->execute());
        if (is_array(self::$clients)) {
            self::$clients[] = $response->getInfo();
        }

        $this->eventDispatcher->dispatch(new ClientResponseEvent($client->getUri(), $response, $extra));

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \Exception('Swoole bridge stream not configured!');
    }

    public function withOptions(array $options): static
    {
        return $this;
    }

    public function enableTrace(): void
    {
        self::$clients = [];
    }
}
