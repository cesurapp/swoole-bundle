<?php

namespace Cesurapp\SwooleBundle\Client;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

class SwooleBridge implements HttpClientInterface
{
    public static ?array $clients = null;

    /**
     * Applied to every request, set through withOptions().
     */
    private array $defaultOptions = [];

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options = self::mergeOptions($this->defaultOptions, $options);
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
        if (isset($options['verify_peer'])) {
            $client->setRequiredSsl((bool) $options['verify_peer']);
        }
        // Symfony's timeout limits idle time, Swoole's the whole request
        if (isset($options['timeout'])) {
            $client->setTimeout((float) $options['timeout']);
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
        // Bodies are already complete, so there is nothing to wait on and $timeout never applies
        return new SwooleResponseStream($responses instanceof ResponseInterface ? [$responses] : $responses);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->defaultOptions = self::mergeOptions($this->defaultOptions, $options);

        return $clone;
    }

    /**
     * The request's options win over the defaults; headers, query and extra are merged key by key.
     */
    private static function mergeOptions(array $defaults, array $options): array
    {
        foreach (['headers', 'query', 'extra'] as $key) {
            if (isset($defaults[$key], $options[$key])) {
                $options[$key] = [...$defaults[$key], ...$options[$key]];
            }
        }

        return $options + $defaults;
    }

    public function enableTrace(): void
    {
        self::$clients = [];
    }
}
