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
    public CoroutineHttpClient $client;

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
     * Coroutine Client Options. The timeouts are not among them: see applyTimeouts().
     */
    private array $options = [
        'method' => 'GET',
        'reconnect' => 1,
        'defer' => false,
        'keep_alive' => false,
        'websocket_mask' => false,
        'websocket_compression' => false,
        'http_compression' => true,
        'body_decompression' => true,
    ];

    /**
     * Seconds the whole request may take, connect included; null for no limit.
     */
    private ?float $timeout = 10.0;

    /**
     * Seconds the connection may take; null takes the idle timeout.
     */
    private ?float $connectTimeout = null;

    /**
     * Seconds the request may go without receiving data, null for no limit.
     */
    private ?float $idleTimeout = null;

    /**
     * An https proxy (TLS to the proxy itself): Swoole's client speaks to plain HTTP and SOCKS5 proxies alone, so the
     * request goes through libcurl (sendThroughCurl()).
     *
     * @var array{host: string, port: int, username: ?string, password: ?string}|null
     */
    private ?array $httpsProxy = null;

    public function __construct(string $uri)
    {
        // Create Client
        $info = parse_url($uri);
        if ('http' === $info['scheme']) {
            $this->client = new CoroutineHttpClient($info['host'], $info['port'] ?? 80, false);
        } elseif ('https' === $info['scheme']) {
            $this->client = new CoroutineHttpClient($info['host'], $info['port'] ?? 443, true);
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
        // A header, as Swoole sends it, so a request through libcurl (an https proxy) carries it too
        return $this->setHeaders(['authorization' => 'Basic '.base64_encode($username.':'.$password)]);
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

    /**
     * Swoole's own client settings. Its `timeout` and `connect_timeout` go to setTimeout() and
     * setConnectTimeout(): the timeouts are written together (applyTimeouts()), so none overwrites another.
     */
    public function setOptions(array $options): self
    {
        if (isset($options['timeout'])) {
            $this->setTimeout((float) $options['timeout']);
        }
        if (isset($options['connect_timeout'])) {
            $this->setConnectTimeout((float) $options['connect_timeout']);
        }
        unset($options['timeout'], $options['connect_timeout']);

        $this->client->set($options);

        return $this;
    }

    /**
     * Seconds the whole request may take, connect and response included (10 by default). 0 or less
     * waits without limit.
     */
    public function setTimeout(float $seconds): self
    {
        $this->timeout = $seconds > 0 ? $seconds : null;

        return $this;
    }

    /**
     * Seconds the connection may take. Unset, it is the idle timeout: connecting is time without data.
     * 0 or less unsets it. Never more than setTimeout() allows the whole request.
     */
    public function setConnectTimeout(float $seconds): self
    {
        $this->connectTimeout = $seconds > 0 ? $seconds : null;

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

    /**
     * TLS to the proxy itself, which Swoole's client cannot speak: the request goes through libcurl (sendThroughCurl()).
     */
    public function setHttpsProxy(string $host, int $port, ?string $username, ?string $password): self
    {
        $this->httpsProxy = ['host' => $host, 'port' => $port, 'username' => $username, 'password' => $password];

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
     * watchdog cancels the request once nothing has come for that long. Swoole's own decompression is
     * off meanwhile, so every chunk reaches write_func as it arrives (since 6.3 Swoole would decode
     * first and skip the chunks that decode to nothing, which the watchdog would take for silence), and
     * gzip and deflate are inflated here. Swoole does not report an upload's progress, so the request
     * must be sent within the idle time; the wait for the response counts from the end of the upload.
     */
    private function send(): void
    {
        $this->applyTimeouts();

        if (null !== $this->httpsProxy) {
            $this->client->closed = false; // an earlier read's close() ends none of this one
            $this->sendThroughCurl($this->httpsProxy);

            return;
        }

        if (null === $idle = $this->idleTimeout) {
            $this->client->execute($this->requestUri);

            return;
        }

        // Static closures, so the client holds no reference back to this object
        $state = (object) ['body' => '', 'lastActivity' => microtime(true), 'inflate' => null];
        $decompress = (bool) ($this->client->setting['body_decompression'] ?? true);
        $this->client->set(['body_decompression' => false, 'write_func' => static function (Client $client, string $chunk) use ($state, $decompress) {
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
        $this->client->set(['defer' => true]);
        if ($this->client->execute($this->requestUri)) {
            $state->lastActivity = microtime(true);
            // What the upload left of the whole request's time, -1 without a limit
            $this->client->recv(null !== $this->timeout ? max($this->timeout - (microtime(true) - $started), 0.001) : -1);
        }
        $done->push(true);

        // The closure stays out of getInfo(), which the profiler serializes; the caller's setting comes back
        unset($this->client->setting['write_func']);
        $this->client->set(['body_decompression' => $decompress]);
        $this->client->body = $state->body;
        if ($timedOut) {
            // Reported as Swoole reports its own timeouts
            $this->client->statusCode = SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT;
            $this->client->errCode = SOCKET_ETIMEDOUT;
            $this->client->errMsg = swoole_strerror(SOCKET_ETIMEDOUT);
        }
    }

    /**
     * Through an https proxy libcurl makes the request (a coroutine's wait under SWOOLE_HOOK_NATIVE_CURL, as the
     * server's runtime sets it), always through a CONNECT tunnel, and writes its result where Swoole writes its own:
     * statusCode, headers, body, errCode (libcurl's number here) and errMsg, a failure as Swoole's negative status. The
     * client's settings keep their meaning:
     * - ssl_verify_peer and ssl_cafile cover the proxy's certificate as well as the server's;
     * - an ssl_host_name other than the URL's host (an address the caller resolved and checked) names the server for
     *   SNI, its certificate and Host, while the tunnel goes to that address;
     * - a write_func takes the body as it comes, and closing the client in it ends the read (a reset, as with Swoole);
     * - with body_decompression off the body stays as it came;
     * - the timeouts as applyTimeouts() wrote them, the idle one in whole seconds (libcurl's low speed limit).
     * A file upload has no way through here, and the response's cookies stay in its headers alone (not in cookies).
     *
     * @param array{host: string, port: int, username: ?string, password: ?string} $proxy
     */
    private function sendThroughCurl(array $proxy): void
    {
        $client = $this->client;
        $setting = $client->setting ?? [];
        if (!empty($client->uploadFiles)) {
            throw new Exception('a file upload cannot go through an https proxy');
        }

        $address = static fn (string $host): string => str_contains($host, ':') ? '['.$host.']' : $host;
        $name = (string) ($setting['ssl_host_name'] ?? $client->host);
        $verify = (bool) ($setting['ssl_verify_peer'] ?? false);
        $handle = curl_init(($client->ssl ? 'https://' : 'http://').$address($name).':'.$client->port.$this->requestUri);
        curl_setopt_array($handle, [
            CURLOPT_PROXY => 'https://'.$address($proxy['host']).':'.$proxy['port'],
            CURLOPT_PROXYTYPE => CURLPROXY_HTTPS,
            CURLOPT_HTTPPROXYTUNNEL => true,
            CURLOPT_SUPPRESS_CONNECT_HEADERS => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_PROXY_SSL_VERIFYPEER => $verify,
            CURLOPT_PROXY_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_NOSIGNAL => true,
        ]);
        // An empty list proxies every host: the environment's no_proxy never sends a request around the proxy
        // @phpstan-ignore argument.type (libcurl documents the empty list; the stub asks for a host)
        curl_setopt($handle, CURLOPT_NOPROXY, '');
        if (null !== $proxy['username']) {
            curl_setopt($handle, CURLOPT_PROXYUSERNAME, $proxy['username']);
            curl_setopt($handle, CURLOPT_PROXYPASSWORD, (string) $proxy['password']);
        }
        if (isset($setting['ssl_cafile'])) {
            curl_setopt($handle, CURLOPT_CAINFO, $setting['ssl_cafile']);
            curl_setopt($handle, CURLOPT_PROXY_CAINFO, $setting['ssl_cafile']);
        }
        if ($name !== $client->host) {
            curl_setopt($handle, CURLOPT_CONNECT_TO, [$address($name).':'.$client->port.':'.$address($client->host).':'.$client->port]);
        }

        // As applyTimeouts() wrote them: Swoole's -1 is no limit
        if (($timeout = (float) ($setting['timeout'] ?? -1)) > 0) {
            curl_setopt($handle, CURLOPT_TIMEOUT_MS, (int) ceil($timeout * 1000));
        }
        if (($connect = (float) ($setting['connect_timeout'] ?? 0)) > 0) {
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, (int) ceil($connect * 1000));
        }
        if (null !== $this->idleTimeout) {
            curl_setopt($handle, CURLOPT_LOW_SPEED_LIMIT, 1);
            curl_setopt($handle, CURLOPT_LOW_SPEED_TIME, (int) ceil($this->idleTimeout));
        }

        // The caller's headers, and no 100-continue round trip before a body
        $headers = ['Expect:'];
        $decompress = (bool) ($setting['body_decompression'] ?? true);
        foreach ($client->requestHeaders ?? [] as $header => $value) {
            $value = is_array($value) ? implode(', ', $value) : (string) $value;
            if ($decompress && 'accept-encoding' === strtolower((string) $header)) {
                curl_setopt($handle, CURLOPT_ACCEPT_ENCODING, $value); // sent, and the body it brings decoded
            } else {
                $headers[] = $header.': '.$value;
            }
        }
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        if (!empty($client->cookies)) {
            curl_setopt($handle, CURLOPT_COOKIE, http_build_query($client->cookies, '', '; ', PHP_QUERY_RFC3986));
        }

        // A body makes it a POST unless a method is set, as with Swoole
        $body = is_array($client->requestBody) ? http_build_query($client->requestBody) : $client->requestBody;
        $method = $client->requestMethod ?: (null === $body ? 'GET' : 'POST');
        if ('HEAD' === $method) {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        } else {
            curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
            if (null !== $body) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }
        }

        $client->statusCode = 0;
        $client->headers = [];
        $client->errCode = 0;
        $client->errMsg = '';
        // Each response's own status and headers: a 100 Continue may come first
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use ($client): int {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $status)) {
                $client->statusCode = (int) $status[1];
                $client->headers = [];
            } elseif (false !== $colon = strpos($line, ':')) {
                $client->headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }

            return strlen($line);
        });
        $writeFunc = $setting['write_func'] ?? null;
        $body = '';
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use ($client, $writeFunc, &$body): int {
            if (null === $writeFunc) {
                $body .= $chunk;

                return strlen($chunk);
            }
            $writeFunc($client, $chunk);

            return $client->closed ? 0 : strlen($chunk); // 0 ends the transfer
        });

        curl_exec($handle);
        $client->body = $body;
        if (0 !== $errno = curl_errno($handle)) {
            [$client->statusCode, $client->errCode, $client->errMsg] = match (true) {
                CURLE_OPERATION_TIMEDOUT === $errno => [SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT, SOCKET_ETIMEDOUT, swoole_strerror(SOCKET_ETIMEDOUT)],
                $client->closed => [SWOOLE_HTTP_CLIENT_ESTATUS_SERVER_RESET, 0, ''],
                CURLE_SEND_ERROR === $errno => [SWOOLE_HTTP_CLIENT_ESTATUS_SEND_FAILED, $errno, curl_error($handle)],
                // Cut off once the response began
                $client->statusCode > 0 => [SWOOLE_HTTP_CLIENT_ESTATUS_SERVER_RESET, $errno, curl_error($handle)],
                default => [SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED, $errno, curl_error($handle)],
            };
        }
    }

    /**
     * Writes the timeouts to Swoole's settings, once, right before the request: the setters only
     * record them, so whichever order they came in, none overwrites another. Swoole's `timeout` does
     * not cover the connection — without its own `connect_timeout` Swoole waits up to 10 seconds to
     * connect, whatever `timeout` says — so the connection gets the one given, else the idle timeout,
     * and never more than the whole request may take.
     */
    private function applyTimeouts(): void
    {
        $connect = $this->connectTimeout ?? $this->idleTimeout;
        if (null !== $this->timeout) {
            $connect = min($connect ?? $this->timeout, $this->timeout);
        }

        $this->client->set(['timeout' => $this->timeout ?? -1, ...(null === $connect ? [] : ['connect_timeout' => $connect])]);
    }

    /**
     * Create Static.
     */
    public static function create(string $uri): self
    {
        return new self($uri);
    }
}
