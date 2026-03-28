<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 中间件调度器
 *
 * 实现了 PSR-15 RequestHandlerInterface，是中间件管道的核心组件。
 * 负责管理中间件栈并按照洋葱模型执行调度。
 *
 * 工作原理：
 * 1. 中间件通过 pipe() 方法添加到调度器
 * 2. dispatch() 方法启动调度流程
 * 3. 每个中间件的 process() 方法被依次调用
 * 4. 中间件可以决定是否调用下一个中间件（短路）
 *
 * @example
 * ```php
 * $dispatcher = new MiddlewareDispatcher($finalHandler);
 * $dispatcher->pipe(new CallableMiddleware(fn($req, $h) => $h->handle($req)));
 * $response = $dispatcher->dispatch($request);
 * ```
 */
class MiddlewareDispatcher implements RequestHandlerInterface
{
    /** @var array<MiddlewareInterface> 中间件栈 */
    private array $middlewares = [];

    /** @var RequestHandlerInterface 最终处理器 */
    private RequestHandlerInterface $finalHandler;

    /** @var int 当前中间件索引 */
    private int $index = 0;

    /**
     * 构造函数
     *
     * @param RequestHandlerInterface $finalHandler 最终处理器，当所有中间件处理完成后调用
     */
    public function __construct(RequestHandlerInterface $finalHandler)
    {
        $this->finalHandler = $finalHandler;
    }

    /**
     * 添加中间件到管道
     *
     * 中间件按照添加顺序执行，形成洋葱模型的从外到内。
     *
     * @param MiddlewareInterface $middleware 要添加的中间件
     * @return self 返回自身，支持链式调用
     *
     * @example
     * ```php
     * $dispatcher->pipe($middleware1)->pipe($middleware2);
     * ```
     */
    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * 处理请求
     *
     * 实现 RequestHandlerInterface::handle() 方法。
     * 根据当前索引获取对应的中间件并执行。
     *
     * @param ServerRequestInterface $request HTTP 请求
     * @return ResponseInterface HTTP 响应
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->index >= count($this->middlewares)) {
            return $this->finalHandler->handle($request);
        }

        $middleware = $this->middlewares[$this->index++];
        return $middleware->process($request, $this);
    }

    /**
     * 分发请求（重置索引后处理）
     *
     * 与 handle() 类似，但会先重置中间件索引，
     * 确保从管道开头开始处理。
     *
     * @param ServerRequestInterface $request HTTP 请求
     * @return ResponseInterface HTTP 响应
     */
    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $this->index = 0;
        return $this->handle($request);
    }

    /**
     * 获取所有中间件
     *
     * @return array<MiddlewareInterface> 中间件数组
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * 获取最终处理器
     *
     * @return RequestHandlerInterface 最终处理器
     */
    public function getFinalHandler(): RequestHandlerInterface
    {
        return $this->finalHandler;
    }

    /**
     * 检查是否还有未处理的中间件
     *
     * @return bool 如果还有中间件返回 true
     */
    public function hasNext(): bool
    {
        return $this->index < count($this->middlewares);
    }

    /**
     * 获取剩余中间件数量
     *
     * @return int 剩余中间件数量
     */
    public function getRemainingCount(): int
    {
        return count($this->middlewares) - $this->index;
    }
}