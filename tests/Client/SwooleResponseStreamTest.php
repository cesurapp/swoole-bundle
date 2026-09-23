<?php

namespace Cesurapp\SwooleBundle\Tests\Client;

use Cesurapp\SwooleBundle\Client\SwooleBridge;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SwooleResponseStreamTest extends TestCase
{
    public function testEachResponseStreamsAsFirstDataAndLastChunk(): void
    {
        $hello = $this->response('Hello World');
        $empty = $this->response('');

        $this->assertSame([
            [$hello, true, false, '', 0],
            [$hello, false, false, 'Hello World', 0],
            [$hello, false, true, '', 11],
            [$empty, true, false, '', 0],
            [$empty, false, true, '', 0],
        ], $this->collect([$hello, $empty]));
    }

    public function testSingleResponseIsAccepted(): void
    {
        $response = $this->response('body');

        $this->assertSame('body', implode('', array_column($this->collect($response), 3)));
    }

    private function collect(ResponseInterface|iterable $responses): array
    {
        $bridge = new SwooleBridge($this->createStub(EventDispatcherInterface::class));

        $chunks = [];
        foreach ($bridge->stream($responses) as $response => $chunk) {
            $chunks[] = [$response, $chunk->isFirst(), $chunk->isLast(), $chunk->getContent(), $chunk->getOffset()];
        }

        return $chunks;
    }

    private function response(string $content): ResponseInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($content);

        return $response;
    }
}
