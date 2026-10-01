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
     * @param array $defaultOptions applied to every request, as through withOptions()
     */
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher, private array $defaultOptions = [])
    {
    }

    /**
     * Symfony's options are translated; an option Symfony does not know is one of Swoole's own client
     * settings (connect_timeout, ssl_host_name, body_decompression…) and goes through as it is, last:
     * what the caller sets explicitly wins over what the bridge derives from Symfony's options.
     *
     * Timeouts, as in Symfony: `timeout` limits the time without data (default_socket_timeout when
     * unset), `max_duration` the whole request (0 for no limit), `max_connect_duration` the connection
     * (the idle timeout when unset). Swoole's own `connect_timeout` is honoured the same way.
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options = self::mergeOptions($this->defaultOptions, $options);
        $client = SwooleClient::create($url)
            ->setMethod($method)
            ->setIdleTimeout((float) ($options['timeout'] ?? ini_get('default_socket_timeout')))
            ->setTimeout((float) ($options['max_duration'] ?? 0))
            ->setConnectTimeout((float) ($options['max_connect_duration'] ?? 0));
        $extra = $options['extra'] ?? [];

        if (isset($options['headers'])) {
            $client->setHeaders($options['headers']);
        }
        if (isset($options['json'])) {
            $client->setJsonData($options['json']);
        }
        if (isset($options['body'])) {
            $client->setData(self::readBody($options['body']));
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
        // On unless turned off, as in Symfony: Swoole alone accepts any certificate
        $client->setRequiredSsl((bool) ($options['verify_peer'] ?? true));

        // Swoole's own settings, after everything derived from Symfony's options
        $client->setOptions(array_diff_key($options, HttpClientInterface::OPTIONS_DEFAULTS));

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

    /**
     * Symfony's body option also takes a resource, an iterable or a closure returning chunks until an
     * empty one (async-aws sends a file as an iterable). Swoole sends a string, so they are read into one.
     */
    private static function readBody(mixed $body): string|array
    {
        if (is_string($body) || is_array($body)) {
            return $body;
        }
        if (is_resource($body)) {
            return stream_get_contents($body);
        }

        $data = '';
        if ($body instanceof \Closure) {
            while ('' !== $chunk = $body(16372)) {
                $data .= $chunk;
            }
        } else {
            foreach ($body as $chunk) {
                $data .= $chunk;
            }
        }

        return $data;
    }

    public function enableTrace(): void
    {
        self::$clients = [];
    }
}
