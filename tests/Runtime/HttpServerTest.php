<?php

namespace Cesurapp\SwooleBundle\Tests\Runtime;

use Cesurapp\SwooleBundle\Runtime\SwooleServer\HttpServer;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class HttpServerTest extends TestCase
{
    public function testEachCookieGoesOnLineOfItsOwn(): void
    {
        $sfResponse = new SymfonyResponse('ok');
        $sfResponse->headers->setCookie(Cookie::create('session', 'abc'));
        $sfResponse->headers->setCookie(Cookie::create('remember', 'xyz'));

        $headers = [];
        $response = $this->createStub(Response::class);
        $response->method('header')->willReturnCallback(static function (string $name, array|string $value) use (&$headers) {
            $headers[$name] = $value;

            return true;
        });

        // Only the conversion is under test: no server is bound.
        $server = new \ReflectionClass(HttpServer::class)->newInstanceWithoutConstructor();
        new \ReflectionMethod($server, 'toSwooleResponse')->invoke($server, $sfResponse, $response);

        $this->assertCount(2, $headers['set-cookie']);
        $this->assertStringStartsWith('session=abc;', $headers['set-cookie'][0]);
        $this->assertStringStartsWith('remember=xyz;', $headers['set-cookie'][1]);
    }

    public function testATlsListenerMarksTheRequestSecure(): void
    {
        $this->assertSame('https://api.test/x', $this->toSymfonyRequest(SWOOLE_SOCK_TCP | SWOOLE_SSL)->getUri());
        $this->assertSame('http://api.test/x', $this->toSymfonyRequest(SWOOLE_SOCK_TCP)->getUri());
    }

    private function toSymfonyRequest(int $sockType): SymfonyRequest
    {
        $request = Request::create();
        $request->parse("GET /x HTTP/1.1\r\nHost: api.test\r\n\r\n");

        // Only the conversion is under test: no server is bound.
        $server = new \ReflectionClass(HttpServer::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(HttpServer::class, 'options')->setValue($server, ['http' => ['sock_type' => $sockType]]);

        return new \ReflectionMethod($server, 'toSymfonyRequest')->invoke($server, $request);
    }
}
