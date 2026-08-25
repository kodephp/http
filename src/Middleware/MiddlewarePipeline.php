<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Kode\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 中间件管道（无状态、可重入、零逐请求分配）
 *
 * 管道对象本身只持有「中间件栈 + 最终处理器」，不持有任何请求级可变状态，
 * 因此同一个实例可以被重复调用，也可以在 Swoole 协程 / Fiber 并发场景下安全共享。
 *
 * 自 v3.4 起，管道在首次 {@see handle()} 时将中间件栈**预编译**为一个内部的
 * {@see RequestHandlerInterface} 闭包链（洋葱模型）：编译只发生一次，之后每请求
 * 直接 `$compiled->handle($request)`，不再逐层 new 游标、不再有递归调用栈，
 * 把每请求的中间件调度开销降到最低。
 *
 * 任一 `pipe` / `prepend` / `pipeAll` / `withFinalHandler` 改变栈结构时，
 * 缓存的编译结果会被置空，下次 handle() 重新编译。
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

    /**
     * 预编译后的闭包链（首次 handle 时构建，之后复用）。
     *
     * 编译结果只捕获「中间件栈 + 最终处理器」这两个不可变引用，
     * 因此管道在编译后依旧无状态，可安全跨协程共享。
     */
    private ?RequestHandlerInterface $compiled = null;

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

        $this->compiled = null;

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

        $this->compiled = null;

        return $this;
    }

    /**
     * 处理请求：首次调用预编译闭包链，之后直接复用
     *
     * 无论中间件或最终处理器返回的是工厂 {@see Response}、PSR-7 响应、
     * 数组还是字符串，出管道时都会被 {@see Response::resolve()} 归一化为
     * 真实的 PSR-7 响应。因此中间件里 `return Response::json(...)` 即可，
     * 无需再调用 `->send()`（保留亦可，向后兼容）。
     *
     * 热路径优化：管道出口对已为 {@see ResponseInterface} 的结果跳过
     * `Response::resolve()` 的 `match(true)` 分发，直接透传——绝大多数请求
     * （CallableHandler 返回 Response / JsonErrorHandler 短路）命中此快路径。
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->compiled === null) {
            $this->compile();
        }

        $response = $this->compiled->handle($request);

        return $response instanceof ResponseInterface
            ? $response
            : Response::resolve($response);
    }

    /**
     * 将中间件栈预编译为一个内部 RequestHandler 闭包链。
     *
     * 从最内层（最终处理器）向外逐层包裹：最后一个注册的中间件在最外层。
     * 编译产物只持有中间件与 next 的引用，无请求级状态。
     */
    private function compile(): void
    {
        /** @var RequestHandlerInterface $handler */
        $handler = $this->finalHandler;

        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = $handler;
            $handler = new class($middleware, $next) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        $this->compiled = $handler;
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
     * 返回一个替换了最终处理器的副本（编译缓存一并重置）
     */
    public function withFinalHandler(RequestHandlerInterface $handler): static
    {
        $clone = clone $this;
        $clone->finalHandler = $handler;
        $clone->compiled = null;
        return $clone;
    }
}
