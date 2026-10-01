<?php

namespace Cesurapp\SwooleBundle\Client;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
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

    /**
     * Seconds the request may go without receiving data, null for no limit.
     */
    private ?float $idleTimeout = null;

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
     * Seconds the request may go without receiving data, as Symfony's timeout option: connect and the
     * wait for the response count, a body that keeps coming does not. 0 or less waits without limit.
     * Uploads count as idle time too, Swoole does not report their progress.
     */
    public function setIdleTimeout(float $seconds): self
    {
        $this->idleTimeout = $seconds > 0 ? $seconds : null;
        if (null !== $this->idleTimeout) {
            $this->client->set(['connect_timeout' => $seconds]);
        }

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
            $this->send();

            return $this->client;
        }

        $scheduler = new Scheduler();
        $scheduler->set(['hook_flags' => Runtime::getHookFlags()]);
        $scheduler->add(fn () => $this->send());
        $scheduler->start();

        return $this->client;
    }

    /**
     * With an idle timeout the body comes through write_func, which marks each chunk's arrival, and a
     * watchdog cancels the request once nothing has come for that long. Swoole leaves the body to
     * write_func then, so gzip and deflate are inflated here. Swoole does not report an upload's
     * progress, so the request must be sent within the idle time; the wait for the response counts
     * from the end of the upload.
     */
    private function send(): void
    {
        if (null === $idle = $this->idleTimeout) {
            $this->client->execute($this->requestUri);

            return;
        }

        // Static closures, so the client holds no reference back to this object
        $state = (object) ['body' => '', 'lastActivity' => microtime(true), 'inflate' => null];
        $decompress = (bool) ($this->client->setting['body_decompression'] ?? true);
        $this->client->set(['write_func' => static function (Client $client, string $chunk) use ($state, $decompress) {
            $state->lastActivity = microtime(true);
            $encoding = ['gzip' => ZLIB_ENCODING_GZIP, 'deflate' => ZLIB_ENCODING_DEFLATE][$client->headers['content-encoding'] ?? ''] ?? null;
            if ($decompress && null !== $encoding) {
                $state->inflate ??= inflate_init($encoding);
                $chunk = inflate_add($state->inflate, $chunk);
            }
            $state->body .= $chunk;

            return true;
        }]);

        $done = new Channel(1);
        $cid = Coroutine::getCid();
        $timedOut = false;
        Coroutine::create(static function () use ($done, $state, $idle, $cid, &$timedOut) {
            // Sleeps until the request ends or the idle time since the last chunk runs out (pop waits
            // without limit for 0, hence the floor)
            while (false === $done->pop(max($state->lastActivity + $idle - microtime(true), 0.001))) {
                if (microtime(true) - $state->lastActivity >= $idle) {
                    $timedOut = true;
                    Coroutine::cancel($cid);

                    return;
                }
            }
        });

        // Deferred, so execute() returns once the request is sent and recv() waits for the response
        $started = microtime(true);
        $total = (float) ($this->client->setting['timeout'] ?? 0);
        $this->client->set(['defer' => true]);
        if ($this->client->execute($this->requestUri)) {
            $state->lastActivity = microtime(true);
            // What the upload left of the whole request's time, -1 without a limit
            $this->client->recv($total > 0 ? max($total - (microtime(true) - $started), 0.001) : -1);
        }
        $done->push(true);

        // The closure stays out of getInfo(), which the profiler serializes
        unset($this->client->setting['write_func']);
        $this->client->body = $state->body;
        if ($timedOut) {
            // Reported as Swoole reports its own timeouts
            $this->client->statusCode = SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT;
            $this->client->errCode = SOCKET_ETIMEDOUT;
            $this->client->errMsg = swoole_strerror(SOCKET_ETIMEDOUT);
        }
    }

    /**
     * Create Static.
     */
    public static function create(string $uri): self
    {
        return new self($uri);
    }
}
