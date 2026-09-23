<?php

namespace Cesurapp\SwooleBundle\Tests\Runtime;

use Cesurapp\SwooleBundle\Runtime\SwooleServer\HttpServer;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;
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
}
