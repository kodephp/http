<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Middleware;

use Kode\Http\Middleware\MiddlewarePipeline;
use Kode\Http\Psr7\Message\Response as Psr7Response;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class PipelineNormalizationTest extends TestCase
{
    private function finalHandler(): \Psr\Http\Server\RequestHandlerInterface
    {
        return new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
    }

    /**
     * 核心：Response::json() 现在直接返回真实 PSR-7，
     * 因此中间件无需 ->send() 即可 return。
     */
    public function testJsonReturnsRealPsr7(): void
    {
        $this->assertInstanceOf(ResponseInterface::class, Response::json(['a' => 1]));
        $this->assertInstanceOf(Psr7Response::class, Response::json(['a' => 1]));
    }

    public function testMiddlewareCanReturnResponseWithoutSend(): void
    {
        $middleware = new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(
                \Psr\Http\Message\ServerRequestInterface $request,
                \Psr\Http\Server\RequestHandlerInterface $handler
            ): \Psr\Http\Message\ResponseInterface {
                // 关键：直接返回工厂 PSR-7，不调用 ->send()
                return Response::json(['ok' => true]);
            }
        };

        $pipeline = (new MiddlewarePipeline($this->finalHandler()))
            ->pipe($middleware);

        $response = $pipeline->handle(new ServerRequest('GET', 'http://x/'));

        $this->assertInstanceOf(Psr7Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"ok":true}', (string) $response->getBody());
    }

    /**
     * 即使保留 ->send()（向后兼容），也能得到真实 PSR-7。
     */
    public function testSendStillReturnsPsr7(): void
    {
        $this->assertInstanceOf(Psr7Response::class, Response::json(['a' => 1])->send());
        $this->assertInstanceOf(Psr7Response::class, Response::success(['id' => 1])->send());
    }

    public function testChainedHelpersStillWorkOnPsr7(): void
    {
        $resp = Response::json([])
            ->withCors()
            ->cookie('token', 'abc', httpOnly: true)
            ->withSecurity();

        $this->assertInstanceOf(Psr7Response::class, $resp);
        $this->assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('token=abc', $resp->getHeaderLine('Set-Cookie'));
    }

    public function testRealPsr7PassedThroughUnchanged(): void
    {
        $middleware = new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(
                \Psr\Http\Message\ServerRequestInterface $request,
                \Psr\Http\Server\RequestHandlerInterface $handler
            ): \Psr\Http\Message\ResponseInterface {
                return (new Psr7Response(201))->withHeader('X-Mw', '1');
            }
        };

        $pipeline = (new MiddlewarePipeline($this->finalHandler()))->pipe($middleware);
        $response = $pipeline->handle(new ServerRequest('GET', 'http://x/'));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('1', $response->getHeaderLine('X-Mw'));
    }

    public function testResolveAcceptsMergedResponse(): void
    {
        $factory = Response::success(['id' => 7])->cookie('sid', 'abc');
        $psr7 = Response::resolve($factory);

        $this->assertInstanceOf(Psr7Response::class, $psr7);
        $this->assertSame(200, $psr7->getStatusCode());
        $this->assertStringContainsString('sid=abc', $psr7->getHeaderLine('Set-Cookie'));
    }
}
