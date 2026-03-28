<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Psr7;

use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\Uri;
use Kode\Http\Psr7\Stream;
use PHPUnit\Framework\TestCase;

class ServerRequestTest extends TestCase
{
    public function testCreateServerRequest(): void
    {
        $request = new ServerRequest('GET', '/', ['REQUEST_TIME' => time()]);
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('/', $request->getUri()->getPath());
    }

    public function testGetServerParams(): void
    {
        $params = ['REQUEST_TIME' => 1234567890, 'SERVER_NAME' => 'localhost'];
        $request = new ServerRequest('GET', '/', $params);
        $this->assertEquals($params, $request->getServerParams());
    }

    public function testGetCookieParams(): void
    {
        $request = new ServerRequest('GET', '/');
        $request = $request->withCookieParams(['session' => 'abc123']);
        $this->assertEquals(['session' => 'abc123'], $request->getCookieParams());
    }

    public function testWithCookieParams(): void
    {
        $request = new ServerRequest('GET', '/');
        $newRequest = $request->withCookieParams(['token' => 'xyz']);
        $this->assertEquals([], $request->getCookieParams());
        $this->assertEquals(['token' => 'xyz'], $newRequest->getCookieParams());
    }

    public function testGetQueryParams(): void
    {
        $request = new ServerRequest('GET', '/?foo=bar');
        $request = $request->withQueryParams(['foo' => 'bar']);
        $this->assertEquals('bar', $request->getQueryParams()['foo'] ?? null);
    }

    public function testWithQueryParams(): void
    {
        $request = new ServerRequest('GET', '/');
        $newRequest = $request->withQueryParams(['page' => '1', 'limit' => '10']);
        $this->assertEquals(['page' => '1', 'limit' => '10'], $newRequest->getQueryParams());
    }

    public function testGetUploadedFiles(): void
    {
        $request = new ServerRequest('GET', '/');
        $this->assertEquals([], $request->getUploadedFiles());
    }

    public function testWithUploadedFiles(): void
    {
        $files = [
            'avatar' => [
                'tmp_name' => '/tmp/php123',
                'size' => 1234,
                'error' => 0,
            ]
        ];
        $request = new ServerRequest('GET', '/');
        $newRequest = $request->withUploadedFiles($files);
        $this->assertEquals($files, $newRequest->getUploadedFiles());
    }

    public function testGetParsedBody(): void
    {
        $request = new ServerRequest('POST', '/');
        $this->assertNull($request->getParsedBody());
    }

    public function testWithParsedBody(): void
    {
        $body = ['name' => 'John', 'email' => 'john@example.com'];
        $request = new ServerRequest('POST', '/');
        $newRequest = $request->withParsedBody($body);
        $this->assertEquals($body, $newRequest->getParsedBody());
    }

    public function testGetAttributes(): void
    {
        $request = new ServerRequest('GET', '/');
        $this->assertEquals([], $request->getAttributes());
    }

    public function testGetAttribute(): void
    {
        $request = new ServerRequest('GET', '/');
        $request = $request->withAttribute('user_id', 123);
        $this->assertEquals(123, $request->getAttribute('user_id'));
    }

    public function testGetAttributeWithDefault(): void
    {
        $request = new ServerRequest('GET', '/');
        $this->assertEquals('default', $request->getAttribute('missing', 'default'));
    }

    public function testWithAttribute(): void
    {
        $request = new ServerRequest('GET', '/');
        $newRequest = $request->withAttribute('key', 'value');
        $this->assertNull($request->getAttribute('key'));
        $this->assertEquals('value', $newRequest->getAttribute('key'));
    }

    public function testWithoutAttribute(): void
    {
        $request = new ServerRequest('GET', '/');
        $request = $request->withAttribute('temp', 'data');
        $newRequest = $request->withoutAttribute('temp');
        $this->assertEquals('data', $request->getAttribute('temp'));
        $this->assertNull($newRequest->getAttribute('temp'));
    }

    public function testImmutability(): void
    {
        $request = new ServerRequest('GET', '/', [], ['Content-Type' => 'text/plain']);
        $newRequest = $request->withHeader('X-Custom', 'value');

        $this->assertNotSame($request, $newRequest);
        $this->assertFalse($request->hasHeader('X-Custom'));
        $this->assertTrue($newRequest->hasHeader('X-Custom'));
    }
}