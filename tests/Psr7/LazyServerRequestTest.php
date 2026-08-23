<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Psr7;

use Kode\Context\Context;
use Kode\Http\Psr7\Message\LazyServerRequest;
use Kode\Http\Psr7\Uri;
use Kode\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * 验证 LazyServerRequest 的延迟 header 提取：路由（method + path）不触发 header 规范化，
 * 仅当首次访问 header 时才从 server params 提取，且与旧 eager 逻辑结果一致。
 */
class LazyServerRequestTest extends TestCase
{
    private function headersResolved(LazyServerRequest $request): bool
    {
        $ref = new \ReflectionProperty($request, 'headersResolved');
        $ref->setAccessible(true);

        return $ref->getValue($request) === true;
    }

    private function makeRequest(array $server, string $path = '/'): LazyServerRequest
    {
        return new LazyServerRequest('GET', new Uri($path), $server, [], '', '1.1');
    }

    public function testHeadersNotResolvedUntilAccessed(): void
    {
        $request = $this->makeRequest([
            'REQUEST_METHOD' => 'GET',
            'HTTP_X_FOO_BAR' => 'baz',
        ]);

        // 构造后尚未访问 header
        self::assertFalse($this->headersResolved($request), '构造后不应急切规范化 header');

        $headers = $request->getHeaders();

        self::assertArrayHasKey('X-Foo-Bar', $headers);
        self::assertSame(['baz'], $headers['X-Foo-Bar']);
        self::assertTrue($this->headersResolved($request), 'getHeaders 后应已规范化');
    }

    public function testHeaderNormalizationMatchesExpected(): void
    {
        $request = $this->makeRequest([
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '10',
        ]);

        self::assertSame('example.com', $request->getHeaderLine('Host'));
        self::assertSame('en-US', $request->getHeaderLine('Accept-Language'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('10', $request->getHeaderLine('Content-Length'));
    }

    public function testRoutingDoesNotForceHeaderResolution(): void
    {
        $request = $this->makeRequest(
            ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'HTTP_X_SECRET' => 'should-not-be-read'],
            '/api/users?page=1',
        );

        // 路由阶段只取 method + path
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        self::assertSame('GET', $method);
        self::assertSame('/api/users', $path);

        // 关键点：路由未触碰任何 header 访问 → 不触发规范化
        self::assertFalse(
            $this->headersResolved($request),
            '路由（method + path）不应触发 header 提取，否则热路径优化失效',
        );
    }

    public function testSetRequestWithoutTraceHeadersKeepsHeadersUnresolved(): void
    {
        $request = $this->makeRequest([
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com',
        ]);

        Request::setRequest($request);

        try {
            // 无链路头：hasTraceHeaders 走 server params 扫描，不调用 hasHeader → 不规范化
            self::assertFalse(
                $this->headersResolved($request),
                '无链路头时 syncTrace 守卫不应触发 header 规范化',
            );
        } finally {
            Request::clear();
        }
    }

    public function testSetRequestWithTraceHeaderResolvesAndWritesContext(): void
    {
        $request = $this->makeRequest([
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com',
            'HTTP_X_REQUEST_ID' => 'abc-123',
        ]);

        Request::setRequest($request);

        try {
            // 有链路头：取值经 getHeaderLine 触发一次规范化，并写入 context
            self::assertTrue($this->headersResolved($request));
            self::assertSame('abc-123', Context::get(Context::REQUEST_ID));
        } finally {
            Request::clear();
        }
    }

    public function testWithHeaderBeforeReadResolvesThenMerges(): void
    {
        $request = $this->makeRequest([
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'example.com',
        ]);

        $request = $request->withHeader('X-Foo', 'bar');

        self::assertTrue($this->headersResolved($request), '写 header 前应已解析 server 派生 header');
        self::assertSame('bar', $request->getHeaderLine('X-Foo'));
        self::assertSame('example.com', $request->getHeaderLine('Host'));
    }

    /**
     * 适配器场景（Swoole / Workerman）：header 已由 server 对象预解析并构造期传入，
     * 而非来自 $_SERVER 的 HTTP_* 键。必须保留且不回源覆盖，且仍保持懒加载（未读不解析）。
     */
    public function testAdapterPassedHeadersPreservedAndLazy(): void
    {
        $server = [
            'request_uri' => '/api/users',
            'query_string' => 'page=1',
            'request_method' => 'GET',
            // 注意：Swoole/Workerman 的 server 参数不含 HTTP_* 键，header 在 $headers 中
        ];
        $passedHeaders = [
            'content-type' => ['application/json'],
            'x-request-id' => ['req-xyz'],
        ];

        $request = new LazyServerRequest('GET', new Uri('/api/users'), $server, $passedHeaders, '', '1.1');

        // 构造期已传入 header → headerNames 已填充，resolveHeaders 应直接保留（不回源）
        self::assertFalse($this->headersResolved($request), '构造期传入 header 时不应急切回源 $_SERVER');
        self::assertInstanceOf(LazyServerRequest::class, $request);

        // 读取到的是传入的 header，而非被空 server 回源清空
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('req-xyz', $request->getHeaderLine('X-Request-Id'));

        // 首次读取后仍标记为已解析，且结果等价于传入值（getHeaders 键为原始传入的小写名）
        self::assertTrue($this->headersResolved($request));
        self::assertArrayHasKey('content-type', $request->getHeaders());
        self::assertArrayHasKey('x-request-id', $request->getHeaders());

        // 路由所需的 method + path 不受影响
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/users', $request->getUri()->getPath());
    }
}
