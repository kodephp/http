<?php

declare(strict_types=1);

namespace Kode\Http\Tests;

use Kode\Http\Psr7\Message\Response as Psr7Response;
use Kode\Http\Psr7\Stream;
use Kode\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ResponseBuilderTest extends TestCase
{
    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    public function testJsonEncodesData(): void
    {
        $resp = Response::json(['a' => 1])->send();

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertJsonStringEqualsJsonString('{"a":1}', $this->body($resp));
        $this->assertStringContainsString('application/json', $resp->getHeaderLine('Content-Type'));
    }

    public function testSuccessEnvelope(): void
    {
        $resp = Response::success(['id' => 1])->send();

        $this->assertJsonStringEqualsJsonString(
            '{"success":true,"code":0,"message":"OK","data":{"id":1}}',
            $this->body($resp)
        );
    }

    public function testPaginateStructure(): void
    {
        $resp = Response::paginate(['x'], 10, 1, 5)->send();
        $data = json_decode($this->body($resp), true);

        $this->assertSame(['x'], $data['data']['items']);
        $this->assertSame(10, $data['data']['total']);
        $this->assertSame(2, $data['data']['total_page']);
    }

    public function testErrorAndFailCarryCode(): void
    {
        $resp = Response::error(404, 'Not Found', 'E1004')->send();
        $this->assertSame(404, $resp->getStatusCode());

        $resp2 = Response::fail('bad', 'E1001', 400)->send();
        $data = json_decode($this->body($resp2), true);
        $this->assertSame('E1001', $data['code']);
        $this->assertSame(400, $resp2->getStatusCode());
    }

    public function testCookieHeader(): void
    {
        $resp = Response::json([])->cookie('token', 'abc', httpOnly: true)->send();

        $this->assertTrue($resp->hasHeader('Set-Cookie'));
        $this->assertStringContainsString('token=abc', $resp->getHeaderLine('Set-Cookie'));
        $this->assertStringContainsString('HttpOnly', $resp->getHeaderLine('Set-Cookie'));
    }

    public function testResolveNormalizesVariousReturns(): void
    {
        $fromArray = Response::resolve(['x' => 1]);
        $this->assertInstanceOf(ResponseInterface::class, $fromArray);

        $fromString = Response::resolve('<h1>hi</h1>');
        $this->assertStringContainsString('text/html', $fromString->getHeaderLine('Content-Type'));

        $fromNull = Response::resolve(null);
        $this->assertSame(204, $fromNull->getStatusCode());
    }

    public function testResolveAcceptsPsr7Directly(): void
    {
        $psr7 = new Psr7Response(201, [], Stream::create('created'));
        $result = Response::resolve($psr7);

        $this->assertSame($psr7, $result);
        $this->assertSame('created', $this->body($result));
    }

    public function testWithSecurityHeaders(): void
    {
        $resp = Response::json([])->withSecurity()->send();

        $this->assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $resp->getHeaderLine('X-Frame-Options'));
        $this->assertStringContainsString('strict-origin', $resp->getHeaderLine('Referrer-Policy'));
    }

    public function testJsonpEscapesCallback(): void
    {
        $resp = Response::jsonp(['a' => 1], 'cb')->send();

        $this->assertStringContainsString('cb({"a":1});', $this->body($resp));
    }

    public function testJsonpRejectsInvalidCallback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::jsonp(['a' => 1], 'bad-name!');
    }
}
