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

    /**
     * getBody() 必须非破坏性：物化 Stream 后保留 rawBody 作为字符串真相源，
     * 否则 hasRawBody()/Emitter 快速路径/getRawBody() 会在任意次 getBody() 后失效
     * （kode/process::toHttp11 每请求走 getBody() 的代价）。
     */
    public function testGetBodyIsNonDestructiveForRawBody(): void
    {
        $resp = Response::json(['a' => 1])->send();

        // 初始持有原始字符串体
        $this->assertTrue($resp->hasRawBody());
        $this->assertSame('{"a":1}', $resp->getRawBody());

        // 消费者（如 toHttp11）读取一次 getBody()
        $stream = $resp->getBody();
        $this->assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $stream);
        $this->assertSame('{"a":1}', (string) $stream);

        // 关键不变量：rawBody 未被销毁，快速路径与 getRawBody() 仍可用
        $this->assertTrue($resp->hasRawBody(), 'getBody() 不应销毁 rawBody');
        $this->assertSame('{"a":1}', $resp->getRawBody(), 'getRawBody() 应直接返回原串，无二次物化');

        // 再次 getBody() 返回同一缓存 Stream（幂等）
        $this->assertSame($stream, $resp->getBody());
    }

    /**
     * withBody()（真正的变更入口）仍应清掉 rawBody，保持字符串/Stream 单一真相源。
     */
    public function testWithBodyClearsRawBody(): void
    {
        $resp = Response::json(['a' => 1])->send();
        $this->assertTrue($resp->hasRawBody());

        $resp->withBody(Stream::create('replaced'));
        $this->assertFalse($resp->hasRawBody(), 'withBody() 应清掉 rawBody');
        $this->assertSame('replaced', (string) $resp->getBody());
    }
}
