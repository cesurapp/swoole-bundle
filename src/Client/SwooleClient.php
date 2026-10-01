<?php

namespace Cesurapp\SwooleBundle\Client;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Client\Exception;
use Swoole\Coroutine\Scheduler;
use Swoole\Runtime;

/**
 * Swoole Coroutine Based Http Client.
 *
 * @author  Cesur APAYDIN
 */
class SwooleClient
{
    /**
     * Coroutine Client.
     */
    public Client $client;

    /**
     * Parsed URI.
     */
    private string $requestUri;

    /**
     * Global Headers.
     */
    private array $headers = [
        'accept' => '*/*',
        'accept-encoding' => 'gzip',
    ];

    /**
     * Coroutine Client Options.
     */
    private array $options = [
        'method' => 'GET',
        'reconnect' => 1,
        'timeout' => 10,
        'defer' => false,
        'keep_alive' => false,
        'websocket_mask' => false,
        'websocket_compression' => false,
        'http_compression' => true,
        'body_decompression' => true,
    ];

    public function __construct(string $uri)
    {
        // Create Client
        $info = parse_url($uri);
        if ('http' === $info['scheme']) {
            $this->client = new Client($info['host'], $info['port'] ?? 80, false);
        } elseif ('https' === $info['scheme']) {
            $this->client = new Client($info['host'], $info['port'] ?? 443, true);
        } else {
            throw new Exception('unknown scheme "'.$info['scheme'].'"');
        }

        // Parse Request Uri
        $this->requestUri = $info['path'] ?? '/';
        if (!empty($info['query'])) {
            $this->requestUri .= '?'.$info['query'];
        }

        // Set Defaults
        $this->client->setHeaders($this->headers);
        $this->client->set($this->options);
    }

    public function get(?array $query = null): Client
    {
        if ($query) {
            $this->setQuery($query);
        }

        $this->client->setMethod('GET');

        return $this->execute();
    }

    public function post(string|array $data = []): Client
    {
        $this->client->setMethod('POST');
        $this->setData($data);

        return $this->execute();
    }

    public function put(string|array $data = []): Client
    {
        $this->client->setMethod('PUT');
        $this->setData($data);

        return $this->execute();
    }

    public function patch(string|array $data = []): Client
    {
        $this->client->setMethod('PATCH');
        $this->setData($data);

        return $this->execute();
    }

    public function delete(?array $query = null): Client
    {
        if ($query) {
            $this->setQuery($query);
        }

        $this->client->setMethod('DELETE');

        return $this->execute();
    }

    public function setQuery(array $query = [], bool $clearCurrent = false): self
    {
        if ($clearCurrent) {
            $this->requestUri = '/?'.urldecode(http_build_query($query));

            return $this;
        }

        $parsed = parse_url($this->requestUri);
        parse_str($parsed['query'] ?? '', $currentQuery);
        $this->requestUri = $parsed['path'].'?'.urldecode(http_build_query(array_merge_recursive($currentQuery, $query)));

        return $this;
    }

    public function setMethod(string $method): self
    {
        $this->client->setMethod($method);

        return $this;
    }

    public function setData(string|array $data): self
    {
        if (is_array($data)) {
            foreach ($data as $name => $value) {
                if (is_resource($value)) {
                    $fileName = $data[$name.'_filename'] ?? null;
                    $this->client->addFile(stream_get_meta_data($value)['uri'], $name, null, $fileName);
                    unset($data[$name]);
                }
            }
        }

        $this->client->setData($data);

        return $this;
    }

    public function setJsonData(string|array $reqData): self
    {
        $data = is_array($reqData) ? json_encode($reqData, JSON_THROW_ON_ERROR) : $reqData;

        $this
            ->setHeaders([
                'content-type' => 'application/json',
                'content-length' => strlen($data),
            ])
            ->setData($data);

        return $this;
    }

    public function setBasicAuth(string $username, string $password): self
    {
        $this->client->setBasicAuth($username, $password);

        return $this;
    }

    public function setHeaders(array $headers, bool $reset = false): self
    {
        $this->client->setHeaders(
            $reset ? $headers : [
                ...$this->client->requestHeaders,
                ...$headers,
            ]
        );

        return $this;
    }

    public function setCookies(array $cookies): self
    {
        $this->client->setCookies($cookies);

        return $this;
    }

    public function setDefer(bool $defer): self
    {
        $this->client->setDefer($defer);

        return $this;
    }

    public function setOptions(array $options): self
    {
        $this->client->set($options);

        return $this;
    }

    /**
     * Seconds the whole request may take, connect and response included (10 by default). -1 waits without limit.
     */
    public function setTimeout(float $seconds): self
    {
        $this->client->set(['timeout' => $seconds]);

        return $this;
    }

    /**
     * Verifies the server's certificate, and that it is issued for the host. Off by default: without
     * it any certificate is accepted.
     */
    public function setRequiredSsl(bool $required = true): self
    {
        // ssl_verify_peer checks the certificate chain only; the host name is checked against ssl_host_name.
        $this->client->set([
            'ssl_verify_peer' => $required,
            'ssl_host_name' => $this->client->host,
        ]);

        return $this;
    }

    public function setProxy(string $host, int $port, ?string $username, ?string $password): self
    {
        $this->client->set([
            'http_proxy_host' => $host,
            'http_proxy_port' => $port,
            'http_proxy_user' => $username,
            'http_proxy_password' => $password,
        ]);

        return $this;
    }

    public function setSock5Proxy(string $host, int $port, ?string $username, ?string $password): self
    {
        $this->client->set([
            'socks5_host' => $host,
            'socks5_port' => $port,
            'socks5_username' => $username,
            'socks5_password' => $password,
        ]);

        return $this;
    }

    public function getUri(): string
    {
        $port = in_array($this->client->port, [80, 443], true) ? '' : ':'.$this->client->port;

        return ($this->client->ssl ? 'https://' : 'http://').$this->client->host.$port.$this->requestUri;
    }

    /**
     * Execute Request.
     *
     * Outside a coroutine (a console command) Swoole would end the process with a fatal error that
     * cannot be caught, so the request runs in a coroutine of its own there, with the runtime hooks
     * left as they are.
     */
    public function execute(): Client
    {
        if (Coroutine::getCid() > 0) {
            $this->client->execute($this->requestUri);

            return $this->client;
        }

        $scheduler = new Scheduler();
        $scheduler->set(['hook_flags' => Runtime::getHookFlags()]);
        $scheduler->add(fn () => $this->client->execute($this->requestUri));
        $scheduler->start();

        return $this->client;
    }

    /**
     * Create Static.
     */
    public static function create(string $uri): self
    {
        return new self($uri);
    }
}
