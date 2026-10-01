<?php

namespace Cesurapp\SwooleBundle\Tests\Client;

use Cesurapp\SwooleBundle\Client\SwooleBridge;
use Cesurapp\SwooleBundle\Client\SwooleClient;
use Cesurapp\SwooleBundle\Tests\Kernel;
use Swoole\Coroutine;
use Swoole\Coroutine\Scheduler;
use Swoole\Runtime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ClientBridgeTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $_SERVER['KERNEL_CLASS'] = Kernel::class;
    }

    public function testBrideClientDecorate(): void
    {
        $client = self::getContainer()->get('http_client');
        $this->assertInstanceOf(SwooleBridge::class, $client);
    }

    public function testClient(): void
    {
        /** @var SwooleBridge $client */
        $client = self::getContainer()->get('http_client');

        $scheduler = new Scheduler();
        $scheduler->add(function () use ($client) {
            $req = $client->request('GET', 'https://www.google.com');
            $this->assertSame(200, $req->getStatusCode());

            // Test Query String Parameters
            $req = $client->request('GET', 'https://www.google.com', [
                'query' => ['test' => 'value'],
            ]);
            $this->assertSame(200, $req->getStatusCode());
            $this->assertStringContainsString('test=value', urldecode($req->getContent()));
        });
        $scheduler->start();
    }

    /**
     * Outside a coroutine (a console command) Swoole would end the process with a fatal error: the
     * request runs in a coroutine of its own, and the runtime hooks stay as they were.
     */
    public function testClientOutsideACoroutine(): void
    {
        /** @var SwooleBridge $bridge */
        $bridge = self::getContainer()->get('http_client');
        $hooks = Runtime::getHookFlags();

        // One response, from another process: this one waits on the request.
        $server = proc_open([PHP_BINARY, '-r', <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0');
            echo parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
            $connection = stream_socket_accept($server, 5);
            fread($connection, 65536);
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
            fclose($connection);
            PHP], [1 => ['pipe', 'w']], $pipes);
        $port = (int) fgets($pipes[1]);

        $response = $bridge->request('GET', 'http://127.0.0.1:'.$port.'/');
        fclose($pipes[1]);
        proc_close($server);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertSame(SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED, $bridge->request('GET', 'http://127.0.0.1:1/')->getStatusCode());
        $this->assertSame($hooks, Runtime::getHookFlags());
    }

    public function testClientStream(): void
    {
        /** @var SwooleBridge $client */
        $client = self::getContainer()->get('http_client');

        $scheduler = new Scheduler();
        $scheduler->add(function () use ($client) {
            $req = $client->request('GET', 'https://www.google.com');

            $body = '';
            foreach ($client->stream($req) as $response => $chunk) {
                $this->assertSame($req, $response);
                $body .= $chunk->getContent();
            }

            $this->assertTrue($chunk->isLast());
            $this->assertSame($req->getContent(), $body);
        });
        $scheduler->start();
    }

    public function testClientRequiredSsl(): void
    {
        /** @var SwooleBridge $bridge */
        $bridge = self::getContainer()->get('http_client');

        $scheduler = new Scheduler();
        $scheduler->add(function () use ($bridge) {
            Coroutine::set(['log_level' => SWOOLE_LOG_ERROR]); // each refused handshake logs a warning

            $this->assertSame(200, SwooleClient::create('https://www.google.com')->setRequiredSsl()->get()->statusCode);

            // A self-signed certificate, and one issued for another host
            foreach (['https://self-signed.badssl.com', 'https://wrong.host.badssl.com'] as $url) {
                $this->assertSame(SWOOLE_ERROR_SSL_VERIFY_FAILED, SwooleClient::create($url)->setRequiredSsl()->get()->errCode);
            }

            // Symfony's own option, through the bridge
            $this->assertSame(-1, $bridge->request('GET', 'https://wrong.host.badssl.com', ['verify_peer' => true])->getStatusCode());
        });
        $scheduler->start();
    }

    public function testClientTimeout(): void
    {
        /** @var SwooleBridge $bridge */
        $bridge = self::getContainer()->get('http_client');

        $scheduler = new Scheduler();
        $scheduler->add(function () use ($bridge) {
            // A direct client's timeouts reach Swoole as it sends: 10 seconds in all, connecting included
            $client = SwooleClient::create($this->recordingServer(new Coroutine\Channel(1)));
            $client->get();
            $this->assertSame([10.0, 10.0], [$client->client->setting['timeout'], $client->client->setting['connect_timeout']]);

            $client = SwooleClient::create($this->recordingServer(new Coroutine\Channel(1)))->setTimeout(2.5);
            $client->get();
            $this->assertSame([2.5, 2.5], [$client->client->setting['timeout'], $client->client->setting['connect_timeout']]);

            // Connections wait in the backlog unaccepted, so the request is sent and never answered
            $server = new Coroutine\Socket(AF_INET, SOCK_STREAM);
            $server->bind('127.0.0.1');
            $server->listen();

            // Symfony's own option, through the bridge
            $started = microtime(true);
            $response = $bridge->request('GET', 'http://127.0.0.1:'.$server->getsockname()['port'], ['timeout' => 0.5]);
            $this->assertSame(SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT, $response->getStatusCode());
            $this->assertLessThan(2, microtime(true) - $started);

            $server->close();
        });
        $scheduler->start();
    }

    /**
     * Swoole's `timeout` does not cover the connection, so it gets a limit of its own: the idle timeout,
     * or the one the caller gives — which nothing the bridge derives overwrites — never more than the
     * whole request. And Symfony's `timeout` (time without data) never becomes Swoole's `timeout`.
     */
    public function testClientConnectTimeout(): void
    {
        /** @var SwooleBridge $bridge */
        $bridge = self::getContainer()->get('http_client');

        $scheduler = new Scheduler();
        $scheduler->add(function () use ($bridge) {
            $setting = fn (array $options): array => $bridge->request('GET', $this->recordingServer(new Coroutine\Channel(1)), $options)->getInfo('setting');

            $this->assertSame([-1, 0.5], array_values(array_intersect_key($setting(['timeout' => 0.5]), ['timeout' => 0, 'connect_timeout' => 0])));

            // Symfony's max_connect_duration, and Swoole's own connect_timeout, outlive the idle timeout
            $this->assertSame(0.3, $setting(['timeout' => 5, 'max_connect_duration' => 0.3])['connect_timeout']);
            $this->assertSame(0.3, $setting(['timeout' => 5, 'connect_timeout' => 0.3])['connect_timeout']);

            // ... but not the whole request
            $limited = $setting(['timeout' => 5, 'connect_timeout' => 4, 'max_duration' => 2]);
            $this->assertSame([2.0, 2.0], [$limited['timeout'], $limited['connect_timeout']]);

            // A Swoole setting the caller gives wins over one the bridge derives (verify_peer's host name)
            $this->assertSame('example.com', $setting(['verify_peer' => true, 'ssl_host_name' => 'example.com'])['ssl_host_name']);
        });
        $scheduler->start();
    }

    public function testClientWithOptions(): void
    {
        /** @var SwooleBridge $bridge */
        $bridge = self::getContainer()->get('http_client');

        $scheduler = new Scheduler();
        $scheduler->add(function () use ($bridge) {
            // Connections wait in the backlog unaccepted, so each request times out
            $server = new Coroutine\Socket(AF_INET, SOCK_STREAM);
            $server->bind('127.0.0.1');
            $server->listen();
            $url = 'http://127.0.0.1:'.$server->getsockname()['port'];

            $client = $bridge->withOptions(['timeout' => 30, 'headers' => ['x-a' => 'default', 'x-b' => 'default']]);
            $this->assertNotSame($bridge, $client);

            // The request's own options win, headers are merged
            $started = microtime(true);
            $response = $client->request('GET', $url, ['timeout' => 0.5, 'headers' => ['x-b' => 'request']]);
            $this->assertSame(SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT, $response->getStatusCode());
            $this->assertLessThan(2, microtime(true) - $started);
            $this->assertSame(['default', 'request'], [$response->getInfo('requestHeaders')['x-a'], $response->getInfo('requestHeaders')['x-b']]);

            // A later withOptions() wins over the earlier one
            $started = microtime(true);
            $response = $client->withOptions(['timeout' => 0.5])->request('GET', $url);
            $this->assertSame(SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT, $response->getStatusCode());
            $this->assertLessThan(2, microtime(true) - $started);
            $this->assertSame('default', $response->getInfo('requestHeaders')['x-b']);

            // The original bridge keeps no defaults
            $response = $bridge->request('GET', $url, ['timeout' => 0.5]);
            $this->assertArrayNotHasKey('x-a', $response->getInfo('requestHeaders'));

            $server->close();
        });
        $scheduler->start();
    }

    public function testClientIdleTimeout(): void
    {
        /** @var SwooleBridge $bridge */
        $bridge = self::getContainer()->get('http_client');

        $scheduler = new Scheduler();
        $scheduler->add(function () use ($bridge) {
            // A body that keeps coming runs past the timeout, which only counts the time without data
            $started = microtime(true);
            $response = $bridge->request('GET', $this->slowServer(4, 0.3), ['timeout' => 0.5]);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(str_repeat('abcdefghij', 2000), $response->getContent());
            $this->assertGreaterThan(1, microtime(true) - $started);
            $this->assertArrayNotHasKey('write_func', $response->getInfo('setting'));

            // A pause longer than the timeout ends the request
            $started = microtime(true);
            $response = $bridge->request('GET', $this->slowServer(2, 1), ['timeout' => 0.5]);
            $this->assertSame(SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT, $response->getStatusCode());
            $this->assertLessThan(0.9, microtime(true) - $started);

            // max_duration limits the whole request
            $started = microtime(true);
            $response = $bridge->request('GET', $this->slowServer(4, 0.3), ['timeout' => 0.5, 'max_duration' => 0.7]);
            $this->assertSame(SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT, $response->getStatusCode());
            $this->assertLessThan(1, microtime(true) - $started);
        });
        $scheduler->start();
    }

    public function testClientDefaultTimeout(): void
    {
        // As the bundle's http_client_timeout sets it
        $bridge = new SwooleBridge(self::getContainer()->get('event_dispatcher'), ['timeout' => 0.5]);

        $scheduler = new Scheduler();
        $scheduler->add(function () use ($bridge) {
            // Connections wait in the backlog unaccepted, so each request times out
            $server = new Coroutine\Socket(AF_INET, SOCK_STREAM);
            $server->bind('127.0.0.1');
            $server->listen();
            $url = 'http://127.0.0.1:'.$server->getsockname()['port'];

            $started = microtime(true);
            $response = $bridge->request('GET', $url, ['timeout' => 1]);
            $this->assertSame(SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT, $response->getStatusCode());
            $this->assertGreaterThan(0.9, microtime(true) - $started);

            // A request's own timeout does not carry over to the next one
            $started = microtime(true);
            $response = $bridge->request('GET', $url);
            $this->assertSame(SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT, $response->getStatusCode());
            $this->assertLessThan(0.9, microtime(true) - $started);

            $server->close();
        });
        $scheduler->start();
    }

    public function testClientBody(): void
    {
        /** @var SwooleBridge $bridge */
        $bridge = self::getContainer()->get('http_client');

        $scheduler = new Scheduler();
        $scheduler->add(function () use ($bridge) {
            $resource = fopen('php://memory', 'r+');
            fwrite($resource, 'resource body');
            rewind($resource);
            $chunks = ['closure ', 'body', ''];

            // Symfony's body types besides a string, sent as one; a Content-Length of the caller's (as
            // async-aws sets) is not doubled
            foreach ([
                'resource body' => $resource,
                'iterable body' => (static fn () => yield from ['iterable ', 'body'])(),
                'closure body' => static function (int $size) use (&$chunks): string {
                    return array_shift($chunks);
                },
            ] as $expected => $body) {
                $requests = new Coroutine\Channel(1);
                $response = $bridge->request('PUT', $this->recordingServer($requests), [
                    'body' => $body,
                    'headers' => ['Content-Length' => (string) strlen($expected)],
                ]);
                $this->assertSame(200, $response->getStatusCode());

                $request = $requests->pop(1);
                $this->assertStringEndsWith("\r\n\r\n".$expected, $request);
                $this->assertSame(1, substr_count(strtolower($request), 'content-length:'));
            }
        });
        $scheduler->start();
    }

    public function testClientIdleTimeoutUpload(): void
    {
        /** @var SwooleBridge $bridge */
        $bridge = self::getContainer()->get('http_client');
        // Beyond the socket buffers, so the upload lasts until the server reads it
        $body = str_repeat('a', 16 * 1024 * 1024);

        $scheduler = new Scheduler();
        $scheduler->add(function () use ($bridge, $body) {
            // The upload and the wait for the response each stay under the timeout, together they don't
            $started = microtime(true);
            $response = $bridge->request('PUT', $this->recordingServer(new Coroutine\Channel(1), 0.6, 0.6), ['body' => $body, 'timeout' => 1]);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertGreaterThan(1.2, microtime(true) - $started);

            // An upload the server never reads ends with the timeout
            $server = new Coroutine\Socket(AF_INET, SOCK_STREAM);
            $server->bind('127.0.0.1');
            $server->listen();

            $started = microtime(true);
            $response = $bridge->request('PUT', 'http://127.0.0.1:'.$server->getsockname()['port'], ['body' => $body, 'timeout' => 0.5]);
            $this->assertSame(SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT, $response->getStatusCode());
            $this->assertLessThan(0.9, microtime(true) - $started);

            $server->close();
        });
        $scheduler->start();
    }

    /**
     * Serves one request: reads it $readDelay seconds after the connection, answers $replyDelay seconds
     * after reading it and hands the raw request over $requests.
     */
    private function recordingServer(Coroutine\Channel $requests, float $readDelay = 0.001, float $replyDelay = 0.001): string
    {
        $server = new Coroutine\Socket(AF_INET, SOCK_STREAM);
        $server->bind('127.0.0.1');
        $server->listen();

        Coroutine::create(static function () use ($server, $requests, $readDelay, $replyDelay) {
            $connection = $server->accept();
            Coroutine::sleep($readDelay);

            $request = '';
            while (false === $end = strpos($request, "\r\n\r\n")) {
                $request .= $connection->recv();
            }
            preg_match('/^content-length: *(\d+)/im', substr($request, 0, $end), $length);
            while (strlen($request) < $end + 4 + (int) ($length[1] ?? 0)) {
                $request .= $connection->recv(1024 * 1024);
            }

            Coroutine::sleep($replyDelay);
            $connection->send("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
            $connection->close();
            $server->close();
            $requests->push($request);
        });

        return 'http://127.0.0.1:'.$server->getsockname()['port'];
    }

    /**
     * Serves one request with a gzip body sent in $chunks parts, $gap seconds apart.
     */
    private function slowServer(int $chunks, float $gap): string
    {
        $server = new Coroutine\Socket(AF_INET, SOCK_STREAM);
        $server->bind('127.0.0.1');
        $server->listen();

        Coroutine::create(static function () use ($server, $chunks, $gap) {
            $connection = $server->accept();
            $connection->recv();
            $body = gzencode(str_repeat('abcdefghij', 2000));
            $connection->send("HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n");
            foreach (str_split($body, (int) ceil(strlen($body) / $chunks)) as $part) {
                Coroutine::sleep($gap);
                $connection->send($part);
            }
            $connection->close();
            $server->close();
        });

        return 'http://127.0.0.1:'.$server->getsockname()['port'];
    }

    public function testClientStatic(): void
    {
        $scheduler = new Scheduler();
        $scheduler->add(function () {
            $req = SwooleClient::create('https://www.google.com')->get();
            $this->assertSame(200, $req->getStatusCode());
        });
        $scheduler->start();
    }
}
