<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Psr7;

use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testCreateResponse(): void
    {
        $response = new Response();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getReasonPhrase());
    }

    public function testCreateResponseWithStatus(): void
    {
        $response = new Response(404);
        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals('Not Found', $response->getReasonPhrase());
    }

    public function testCreateResponseWithCustomReason(): void
    {
        $response = new Response(200, [], null, '1.1', 'Custom OK');
        $this->assertEquals('Custom OK', $response->getReasonPhrase());
    }

    public function testGetProtocolVersion(): void
    {
        $response = new Response(200, [], null, '2.0');
        $this->assertEquals('2.0', $response->getProtocolVersion());
    }

    public function testWithStatus(): void
    {
        $response = new Response();
        $newResponse = $response->withStatus(404);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(404, $newResponse->getStatusCode());
        $this->assertEquals('Not Found', $newResponse->getReasonPhrase());
    }

    public function testWithStatusAndCustomReason(): void
    {
        $response = new Response();
        $newResponse = $response->withStatus(418, 'I\'m a teapot');
        $this->assertEquals(418, $newResponse->getStatusCode());
        $this->assertEquals('I\'m a teapot', $newResponse->getReasonPhrase());
    }

    public function testGetHeaders(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json']);
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals(['application/json'], $headers['Content-Type']);
    }

    public function testGetHeader(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json']);
        $this->assertEquals(['application/json'], $response->getHeader('Content-Type'));
    }

    public function testGetHeaderCaseInsensitive(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json']);
        $this->assertEquals(['application/json'], $response->getHeader('content-type'));
    }

    public function testGetHeaderLine(): void
    {
        $response = new Response(200, ['Accept' => ['text/html', 'application/json']]);
        $this->assertEquals('text/html, application/json', $response->getHeaderLine('Accept'));
    }

    public function testWithHeader(): void
    {
        $response = new Response();
        $newResponse = $response->withHeader('X-Custom', 'value');
        $this->assertFalse($response->hasHeader('X-Custom'));
        $this->assertTrue($newResponse->hasHeader('X-Custom'));
        $this->assertEquals(['value'], $newResponse->getHeader('X-Custom'));
    }

    public function testWithAddedHeader(): void
    {
        $response = new Response(200, ['Accept' => 'text/html']);
        $newResponse = $response->withAddedHeader('Accept', 'application/json');
        $this->assertEquals(['text/html', 'application/json'], $newResponse->getHeader('Accept'));
    }

    public function testWithoutHeader(): void
    {
        $response = new Response(200, ['X-Custom' => 'value']);
        $newResponse = $response->withoutHeader('X-Custom');
        $this->assertTrue($response->hasHeader('X-Custom'));
        $this->assertFalse($newResponse->hasHeader('X-Custom'));
    }

    public function testGetBody(): void
    {
        $body = Stream::create('Hello');
        $response = new Response(200, [], $body);
        $this->assertSame($body, $response->getBody());
    }

    public function testWithProtocolVersion(): void
    {
        $response = new Response();
        $newResponse = $response->withProtocolVersion('2.0');
        $this->assertEquals('1.1', $response->getProtocolVersion());
        $this->assertEquals('2.0', $newResponse->getProtocolVersion());
    }

    public function testImmutability(): void
    {
        $response = new Response();
        $newResponse = $response->withHeader('X-Custom', 'value');

        $this->assertNotSame($response, $newResponse);
        $this->assertTrue($response !== $newResponse);
    }

    public function testAllStandardStatusCodes(): void
    {
        $statusCodes = [200, 201, 204, 301, 302, 400, 401, 403, 404, 500, 502, 503];

        foreach ($statusCodes as $code) {
            $response = new Response($code);
            $this->assertEquals($code, $response->getStatusCode());
        }
    }
}