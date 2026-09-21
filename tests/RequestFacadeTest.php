<?php

declare(strict_types=1);

namespace Kode\Http\Tests;

use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\Stream;
use Kode\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        Request::clear();
    }

    protected function tearDown(): void
    {
        Request::clear();
    }

    private function make(array $query = [], array $parsed = [], string $jsonBody = '', array $headers = [], array $serverParams = []): void
    {
        $body = $jsonBody === '' ? null : Stream::create($jsonBody);
        $request = new ServerRequest('POST', 'http://x.com/path?ignore=1', $serverParams, $headers, $body);
        $request = $request->withQueryParams($query)->withParsedBody($parsed);
        Request::setRequest($request);
    }

    public function testInputFromQuery(): void
    {
        $this->make(['name' => 'alice']);

        $this->assertSame('alice', Request::input('name'));
        $this->assertSame('default', Request::input('missing', 'default'));
    }

    public function testInputFromParsedBody(): void
    {
        $this->make([], ['title' => 'hello']);

        $this->assertSame('hello', Request::post('title'));
    }

    public function testInputFromJsonBody(): void
    {
        $this->make([], [], '{"field":"value"}', ['Content-Type' => 'application/json']);

        $this->assertSame('value', Request::json('field'));
        $this->assertSame('value', Request::input('field'));
    }

    public function testTypeSafeAccessors(): void
    {
        $this->make(['num' => '42', 'flag' => 'yes', 'name' => ' bob ']);

        $this->assertSame(42, Request::integer('num'));
        $this->assertTrue(Request::boolean('flag'));
        $this->assertSame('bob', Request::string('name'));
        $this->assertSame(0, Request::integer('absent'));
    }

    public function testOnlyAndExcept(): void
    {
        $this->make(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(['a' => 1, 'b' => 2], Request::only('a', 'b'));
        $this->assertSame(['c' => 3], Request::except('a', 'b'));
    }

    public function testBearerToken(): void
    {
        $this->make([], [], '', ['Authorization' => 'Bearer abc123token']);

        $this->assertSame('abc123token', Request::bearerToken());
    }

    public function testBearerTokenMissing(): void
    {
        $this->make();

        $this->assertNull(Request::bearerToken());
    }

    public function testClientIpIgnoresProxyHeadersByDefault(): void
    {
        $this->make(
            [], [], '',
            ['X-Forwarded-For' => '203.0.113.5, 70.41.3.18'],
            ['REMOTE_ADDR' => '198.51.100.9'],
        );

        $this->assertSame('198.51.100.9', Request::ip());
    }

    public function testClientIpFromProxyHeaderWithBlanketTrust(): void
    {
        $this->make(
            [], [], '',
            ['X-Forwarded-For' => '203.0.113.5, 70.41.3.18'],
            ['REMOTE_ADDR' => '198.51.100.9'],
        );

        $this->assertSame('203.0.113.5', Request::ip(true));
    }

    public function testClientIpFromTrustedProxyChain(): void
    {
        $this->make(
            [], [], '',
            ['X-Forwarded-For' => '203.0.113.5, 10.0.0.2', 'X-Real-IP' => '203.0.113.5'],
            ['REMOTE_ADDR' => '10.0.0.1'],
        );

        // 从右往左第一个非受信跳点即客户端
        $this->assertSame('203.0.113.5', Request::ip(['10.0.0.0/8']));
    }

    public function testClientIpUntrustedRemoteIgnoresHeaders(): void
    {
        $this->make(
            [], [], '',
            ['X-Forwarded-For' => '203.0.113.5', 'X-Real-IP' => '203.0.113.5'],
            ['REMOTE_ADDR' => '198.51.100.9'],
        );

        $this->assertSame('198.51.100.9', Request::ip(['10.0.0.0/8']));
    }

    public function testParamReadsRouteAttributes(): void
    {
        $request = new ServerRequest('GET', 'http://x.com/users/7');
        $request = $request->withAttribute('_route_params', ['id' => '7']);
        Request::setRequest($request);

        $this->assertSame('7', Request::param('id'));
    }

    public function testWantsJson(): void
    {
        $this->make([], [], '', ['Accept' => 'application/json']);

        $this->assertTrue(Request::wantsJson());
    }

    public function testMethodAndPath(): void
    {
        $this->make();

        $this->assertSame('POST', Request::method());
        $this->assertSame('/path', Request::path());
    }

    public function testTraceSyncKillSwitch(): void
    {
        // 默认开：入站链路头同步进追踪上下文。
        $request = new ServerRequest('GET', 'http://x.com/ping', [], ['X-Trace-Id' => 'trace-123']);
        Request::setRequest($request);
        $this->assertSame('trace-123', \Kode\Context\Context::get(\Kode\Context\Context::TRACE_ID));
        Request::clear();

        // 关闭后跳过嗅探与写入（追踪全局关闭部署的热路径优化）。
        Request::setTraceSyncEnabled(false);
        try {
            Request::setRequest($request);
            $this->assertNull(\Kode\Context\Context::get(\Kode\Context\Context::TRACE_ID));
            // 请求本体仍正常预置（门面不受开关影响）。
            $this->assertSame($request, Request::getRequest());
        } finally {
            Request::setTraceSyncEnabled(true);
            Request::clear();
        }
    }

    public function testClearWipesWholeRequestBoundary(): void
    {
        $this->make();

        // 模拟请求途中组件写入的瞬态键（locale / auth_user_id / 链路键等）
        \Kode\Context\Context::set('locale', 'zh_CN');
        \Kode\Context\Context::set('auth_user_id', '42');
        \Kode\Context\Context::set(\Kode\Context\Context::REQUEST_ID, 'req-9');

        Request::clear();

        $this->assertNull(\Kode\Context\Context::get('locale'), '请求边界必须回收所有瞬态键，杜绝跨请求泄漏');
        $this->assertNull(\Kode\Context\Context::get('auth_user_id'));
        $this->assertNull(\Kode\Context\Context::get(\Kode\Context\Context::REQUEST_ID));
        $this->assertNull(Request::getRequest());
    }
}
