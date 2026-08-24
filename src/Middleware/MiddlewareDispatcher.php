<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 中间件调度器
 *
 * MiddlewarePipeline 的语义化封装，实现 PSR-15 RequestHandlerInterface，
 * 按洋葱模型依次执行中间件。
 *
 * v3.0 起为无状态实现：不再持有请求级的可变索引，
 * 同一实例可重复 dispatch，也可在协程并发下安全共享。
 *
 * @example
 * ```php
 * $dispatcher = new MiddlewareDispatcher($finalHandler);
 * $dispatcher->pipe(new CorsMiddleware())
 *            ->pipe(fn($req, $next) => $next->handle($req));
 *
 * $response = $dispatcher->dispatch($request);
 * ```
 */
class MiddlewareDispatcher extends MiddlewarePipeline
{
    /**
     * 分发请求
     */
    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request);
    }

    /**
     * 是否配置了中间件
     */
    public function hasNext(): bool
    {
        return $this->middlewares !== [];
    }

    /**
     * 中间件总数
     */
    public function getRemainingCount(): int
    {
        return count($this->middlewares);
    }

    /**
     * 是否为「仅默认异常中间件」的最小栈（无用户注册的全局中间件）。
     */
    public function isBare(): bool
    {
        return count($this->middlewares) <= 1;
    }
}
