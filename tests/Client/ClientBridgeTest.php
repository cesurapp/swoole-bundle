<?php

namespace Cesurapp\SwooleBundle\Tests\Client;

use Cesurapp\SwooleBundle\Client\SwooleBridge;
use Cesurapp\SwooleBundle\Client\SwooleClient;
use Cesurapp\SwooleBundle\Tests\Kernel;
use Swoole\Coroutine;
use Swoole\Coroutine\Scheduler;
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
