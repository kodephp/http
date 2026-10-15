<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Exception;

use Kode\Exception\KodeException;
use Kode\Http\Exception\HttpException;
use Kode\Http\Kode;
use Kode\Http\Middleware\JsonErrorHandlerMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HttpException 回归测试
 *
 * 背景：本类的静态工厂曾以「request 在前」的签名覆写父类 KodeException 的
 * 「message, context, previous」契约，触发 PHP 继承兼容致命错误——类一旦被引用
 * 即 "Declaration ... must be compatible" 致命，JsonErrorHandler 的 instanceof
 * 分支与 Kode 门面文档承诺的 notFound/badRequest/internalError 全部落空。
 * 本组测试锁死：类可加载、工厂与父类契约兼容、context/previous/headers 透传、
 * 门面快捷方法真实存在且返回 HttpException。
 */
final class HttpExceptionTest extends TestCase
{
    protected function tearDown(): void
    {
        Kode::reset();
    }

    public function test异常类可加载且工厂返回正确错误码(): void
    {
        $e = HttpException::notFound('nope');
        self::assertSame('E1004', $e->getErrorCode());
        self::assertSame('nope', $e->getErrorMsg());
        self::assertSame(404, $e->getHttpStatusCode());
    }

    public function test工厂与父类契约兼容的消息优先签名(): void
    {
        // 父类契约：notFound(string $msg, array $context, ?Throwable $previous)
        // 子类：同前三参 + 追加可选 $request——按父类方式调用必须可用
        $previous = new \RuntimeException('boom');
        $e = HttpException::methodNotAllowed('nope', ['route' => '/admin'], $previous);

        self::assertSame('nope', $e->getErrorMsg());
        self::assertSame(['route' => '/admin'], $e->getContext());
        self::assertSame($previous, $e->getPrevious());
    }

    public function test工厂第四参透传请求对象(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $e = HttpException::notFound('gone', [], null, $request);

        self::assertSame($request, $e->getRequest());
        self::assertArrayHasKey('request', $e->toArray());
    }

    public function testwithRequest事后附加(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $e = HttpException::badRequest('bad')->withRequest($request);

        self::assertSame($request, $e->getRequest());
        self::assertNull(HttpException::badRequest('bad')->getRequest());
    }

    public function test构造器headers不再被克隆体吞掉(): void
    {
        // 旧实现试图 clone $this 到 $new 后即丢弃，且 Throwable 在 PHP 8.5+ 根本不可 clone
        $e = new HttpException('E1004', 'nope', null, null, [], ['X-Foo' => 'bar']);
        self::assertSame(['X-Foo' => 'bar'], $e->getHeaders());
    }

    public function testwithRequest走replicate而非clone(): void
    {
        // Throwable 不可 clone（PHP 8.5+ 全局禁止）；不可变派生必须走 replicate()
        $request = $this->createStub(ServerRequestInterface::class);
        $e = HttpException::badRequest('bad')->withRequest($request);
        self::assertSame($request, $e->getRequest());
        self::assertSame('bad', $e->getErrorMsg());
    }

    public function test父类withContext派生不丢请求与响应头(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $e = HttpException::notFound('gone', [], null, $request)
            ->withHeaders(['X-Foo' => 'bar'])
            ->withContext(['route' => '/api']);

        self::assertSame($request, $e->getRequest(), '父类 withContext 经 replicate 派生必须携带 $request');
        self::assertSame(['X-Foo' => 'bar'], $e->getHeaders(), '父类 withContext 派生必须保留 _http_headers');
        self::assertSame('gone', $e->getErrorMsg());
    }

    public function test门面快捷方法返回HttpException(): void
    {
        $notFound = Kode::notFound('missing');
        self::assertInstanceOf(HttpException::class, $notFound);
        self::assertSame(404, $notFound->getHttpStatusCode());

        $badRequest = Kode::badRequest('bad input');
        self::assertInstanceOf(HttpException::class, $badRequest);
        self::assertSame(400, $badRequest->getHttpStatusCode());

        $internal = Kode::internalError('oops');
        self::assertInstanceOf(HttpException::class, $internal);
        self::assertSame(500, $internal->getHttpStatusCode());
    }

    public function testJsonErrorHandler走HttpException分支(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw HttpException::notFound('route gone');
            }
        };

        $mw = new JsonErrorHandlerMiddleware(false);
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $mw->process($request, $handler);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertSame('E1004', $payload['error']['code']);
        self::assertSame('route gone', $payload['error']['message']);
        self::assertSame('http_error', $payload['error']['type']);
    }

    public function test多态调用与父类签名一致的工厂参数顺序(): void
    {
        // static 引用子类 = 直接绑定子类工厂；签名必须逐位兼容父类前三参
        $ref = new \ReflectionMethod(HttpException::class, 'notFound');
        $params = $ref->getParameters();
        self::assertSame('message', $params[0]->getName());
        self::assertTrue($params[0]->getType() instanceof \ReflectionNamedType && $params[0]->getType()->getName() === 'string');
        self::assertSame('context', $params[1]->getName());
        self::assertTrue($params[1]->getType() instanceof \ReflectionNamedType && $params[1]->getType()->getName() === 'array');
        self::assertSame('previous', $params[2]->getName());
        self::assertSame('request', $params[3]->getName());
        self::assertTrue($ref->getReturnType() instanceof \ReflectionNamedType && $ref->getReturnType()->getName() === 'static');
    }
}
