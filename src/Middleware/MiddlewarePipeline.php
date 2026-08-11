<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Kode\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 中间件管道（无状态、可重入）
 *
 * 每次 handle() 都会创建一个独立的执行游标（Runner），管道对象本身不持有
 * 任何请求级可变状态。因此同一个管道实例可以被重复调用，
 * 也可以在 Swoole 协程 / Fiber 并发场景下安全共享。
 *
 * @example
 * ```php
 * $pipeline = new MiddlewarePipeline($finalHandler);
 * $pipeline->pipe($cors)->pipe($auth);
 *
 * $response = $pipeline->handle($request);   // 可重复调用，互不干扰
 * ```
 */
class MiddlewarePipeline implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> 中间件栈 */
    protected array $middlewares = [];

    /** @var RequestHandlerInterface 最终处理器 */
    protected RequestHandlerInterface $finalHandler;

    public function __construct(RequestHandlerInterface $finalHandler)
    {
        $this->finalHandler = $finalHandler;
    }

    /**
     * 追加中间件（可调用对象会自动包装为 PSR-15 中间件）
     */
    public function pipe(MiddlewareInterface|callable $middleware): static
    {
        $this->middlewares[] = $middleware instanceof MiddlewareInterface
            ? $middleware
            : new CallableMiddleware($middleware);

        return $this;
    }

    /**
     * 批量追加中间件
     *
     * @param iterable<MiddlewareInterface|callable> $middlewares
     */
    public function pipeAll(iterable $middlewares): static
    {
        foreach ($middlewares as $middleware) {
            $this->pipe($middleware);
        }

        return $this;
    }

    /**
     * 在栈首插入中间件（优先执行）
     */
    public function prepend(MiddlewareInterface|callable $middleware): static
    {
        array_unshift(
            $this->middlewares,
            $middleware instanceof MiddlewareInterface ? $middleware : new CallableMiddleware($middleware)
        );

        return $this;
    }

    /**
     * 处理请求：为本次调用创建独立游标
     *
     * 无论中间件或最终处理器返回的是工厂 {@see Response}、PSR-7 响应、
     * 数组还是字符串，出管道时都会被 {@see Response::resolve()} 归一化为
     * 真实的 PSR-7 响应。因此中间件里 `return Response::json(...)` 即可，
     * 无需再调用 `->send()`（保留亦可，向后兼容）。
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::resolve(
            (new PipelineRunner($this->middlewares, $this->finalHandler))->handle($request)
        );
    }

    /**
     * @return list<MiddlewareInterface>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function getFinalHandler(): RequestHandlerInterface
    {
        return $this->finalHandler;
    }

    /**
     * 中间件数量
     */
    public function count(): int
    {
        return count($this->middlewares);
    }

    /**
     * 返回一个替换了最终处理器的副本
     */
    public function withFinalHandler(RequestHandlerInterface $handler): static
    {
        $clone = clone $this;
        $clone->finalHandler = $handler;
        return $clone;
    }
}
