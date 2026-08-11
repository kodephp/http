<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Integration;

use Kode\Context\Context;
use Kode\Exception\KodeException;
use Kode\Http\Integration\FiberCoroutineMiddleware;
use Kode\Http\Integration\ParallelMiddleware;
use Kode\Http\Integration\ProcessWorkerMiddleware;
use Kode\Http\Middleware\JsonErrorHandlerMiddleware;
use Kode\Http\Psr7\Message\Response as Psr7Response;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\Stream;
use Kode\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 验证 Integration 中间件与最新版 kode 包（fibers / parallel / process / exception / context）的集成。
 *
 * 中间件均采用"有则用之、无则降级"策略：kode/fibers 等可选依赖存在时走最新调度器，
 * 否则回退到原生实现，保证在任意环境下都能运行并通过测试。
 */
final class IntegrationMiddlewareTest extends TestCase
{
    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // 把注入的任务结果透传回响应体，便于断言
                $payload = [];
                foreach (['_fiber_results', '_parallel_results'] as $key) {
                    if ($request->getAttribute($key) !== null) {
                        $payload[$key] = $request->getAttribute($key);
                    }
                }
                return new Psr7Response(200, [], Stream::create(json_encode($payload)));
            }
        };
    }

    public function testFiberMiddlewareExecutesTasksViaKodeFibers(): void
    {
        $mw = new FiberCoroutineMiddleware(4, 2048, ['timeout' => 5]);
        $request = (new ServerRequest('GET', 'http://x/'))
            ->withAttribute('_fiber_tasks', [
                'a' => fn () => 'A',
                'b' => fn () => 'B',
                'c' => fn () => throw new \RuntimeException('boom'),
            ]);

        $response = $mw->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('A', $body['_fiber_results']['a']);
        self::assertSame('B', $body['_fiber_results']['b']);
        // 失败任务被捕获为错误结果，不会中断整体并发
        self::assertArrayHasKey('error', $body['_fiber_results']['c']);
    }

    public function testParallelMiddlewareExecutesTasks(): void
    {
        $mw = new ParallelMiddleware(4, ['timeout' => 5]);
        $request = (new ServerRequest('GET', 'http://x/'))
            ->withAttribute('_parallel_tasks', [
                'x' => fn () => 'X',
                'y' => fn () => 'Y',
            ]);

        $response = $mw->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('X', $body['_parallel_results']['x']);
        self::assertSame('Y', $body['_parallel_results']['y']);
    }

    public function testProcessMiddlewareReportsProcessSupport(): void
    {
        $mw = new ProcessWorkerMiddleware(0, false, ['pool_size' => 2]);

        // 已安装 kode/process 时应声明支持
        self::assertTrue($mw->hasProcessSupport());
        self::assertInstanceOf(ProcessWorkerMiddleware::class, $mw);

        $stats = $mw->getStats();
        self::assertArrayHasKey('process_support', $stats);
        self::assertTrue($stats['process_support']);

        // 未初始化进程池时不应在请求处理中自动拉起（getStats 仍安全返回）
        $response = $mw->process(new ServerRequest('GET', 'http://x/'), $this->okHandler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testJsonErrorHandlerPropagatesTraceHeaders(): void
    {
        $mw = new JsonErrorHandlerMiddleware(true);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw KodeException::notFound('用户不存在');
            }
        };

        $response = $mw->process(new ServerRequest('GET', 'http://x/'), $handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertNotEmpty($response->getHeaderLine('X-Trace-Id'));
        self::assertNotEmpty($response->getHeaderLine('X-Span-Id'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('E1004', $body['error']['code']);
        self::assertSame($response->getHeaderLine('X-Trace-Id'), $body['error']['trace_id']);
    }

    public function testRequestPropagatesTraceContextIntoKodeContext(): void
    {
        $request = (new ServerRequest('GET', 'http://x/'))
            ->withHeader('X-Request-Id', 'req-123')
            ->withHeader('X-Trace-Id', 'trace-456');

        Request::setRequest($request);

        try {
            self::assertSame('req-123', Context::get(Context::REQUEST_ID));
            self::assertSame('trace-456', Context::get(Context::TRACE_ID));
            self::assertSame($request, Request::getRequest());
        } finally {
            Request::clear();
        }

        // 清理后链路键应被移除
        self::assertNull(Context::get(Context::REQUEST_ID));
        self::assertNull(Context::get(Context::TRACE_ID));
        self::assertNull(Request::getRequest());
    }
}
