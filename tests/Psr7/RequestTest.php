<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Psr7;

use Kode\Http\Psr7\Message\Request;
use Kode\Http\Psr7\Uri;
use Kode\Http\Psr7\Stream;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testCreateRequest(): void
    {
        $request = new Request('GET', '/');
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('/', $request->getUri()->getPath());
    }

    public function testGetRequestTarget(): void
    {
        $request = new Request('GET', '/path?query=value');
        $this->assertEquals('/path?query=value', $request->getRequestTarget());
    }

    public function testGetRequestTargetRoot(): void
    {
        $request = new Request('GET', new Uri('/'));
        $this->assertEquals('/', $request->getRequestTarget());
    }

    public function testGetProtocolVersion(): void
    {
        $request = new Request('GET', '/', [], null, '1.0');
        $this->assertEquals('1.0', $request->getProtocolVersion());
    }

    public function testWithMethod(): void
    {
        $request = new Request('GET', '/');
        $newRequest = $request->withMethod('POST');
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('POST', $newRequest->getMethod());
    }

    public function testWithUri(): void
    {
        $request = new Request('GET', '/');
        $newUri = new Uri('/new-path');
        $newRequest = $request->withUri($newUri);
        $this->assertEquals('/', $request->getUri()->getPath());
        $this->assertEquals('/new-path', $newRequest->getUri()->getPath());
    }

    public function testGetHeaders(): void
    {
        $request = new Request('GET', '/', ['Content-Type' => 'application/json']);
        $headers = $request->getHeaders();
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals(['application/json'], $headers['Content-Type']);
    }

    public function testGetHeader(): void
    {
        $request = new Request('GET', '/', ['Content-Type' => 'application/json']);
        $this->assertEquals(['application/json'], $request->getHeader('Content-Type'));
    }

    public function testGetHeaderCaseInsensitive(): void
    {
        $request = new Request('GET', '/', ['Content-Type' => 'application/json']);
        $this->assertEquals(['application/json'], $request->getHeader('content-type'));
    }

    public function testGetHeaderLine(): void
    {
        $request = new Request('GET', '/', ['Accept' => ['text/html', 'application/json']]);
        $this->assertEquals('text/html, application/json', $request->getHeaderLine('Accept'));
    }

    public function testWithHeader(): void
    {
        $request = new Request('GET', '/');
        $newRequest = $request->withHeader('X-Custom', 'value');
        $this->assertTrue($newRequest->hasHeader('X-Custom'));
        $this->assertEquals(['value'], $newRequest->getHeader('X-Custom'));
    }

    public function testWithAddedHeader(): void
    {
        $request = new Request('GET', '/', ['Accept' => 'text/html']);
        $newRequest = $request->withAddedHeader('Accept', 'application/json');
        $this->assertEquals(['text/html', 'application/json'], $newRequest->getHeader('Accept'));
    }

    public function testWithoutHeader(): void
    {
        $request = new Request('GET', '/', ['X-Custom' => 'value']);
        $newRequest = $request->withoutHeader('X-Custom');
        $this->assertTrue($request->hasHeader('X-Custom'));
        $this->assertFalse($newRequest->hasHeader('X-Custom'));
    }

    public function testGetBody(): void
    {
        $body = Stream::create('Hello');
        $request = new Request('GET', '/', [], $body);
        $this->assertSame($body, $request->getBody());
    }

    public function testWithProtocolVersion(): void
    {
        $request = new Request('GET', '/');
        $newRequest = $request->withProtocolVersion('2.0');
        $this->assertEquals('1.1', $request->getProtocolVersion());
        $this->assertEquals('2.0', $newRequest->getProtocolVersion());
    }

    public function testImmutability(): void
    {
        $request = new Request('GET', '/', ['Content-Type' => 'application/json']);
        $newRequest = $request->withHeader('X-Custom', 'value');

        $this->assertNotSame($request, $newRequest);
        $this->assertTrue($request !== $newRequest);
    }

    public function testUriWithHostUpdatesHostHeader(): void
    {
        $request = new Request('GET', 'http://example.com');
        $this->assertEquals(['example.com'], $request->getHeader('Host'));
    }
}