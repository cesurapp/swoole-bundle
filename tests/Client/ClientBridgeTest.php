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
            $this->assertSame(10, SwooleClient::create('http://127.0.0.1')->client->setting['timeout']);
            $this->assertSame(2.5, SwooleClient::create('http://127.0.0.1')->setTimeout(2.5)->client->setting['timeout']);

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
