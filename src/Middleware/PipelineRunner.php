<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 管道执行游标
 *
 * 一次请求对应一个实例，持有本次执行的中间件索引。
 * 这样管道本身保持无状态，天然支持并发与嵌套调用。
 *
 * @internal 由 MiddlewarePipeline 创建，不建议直接使用
 */
final class PipelineRunner implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $middlewares */
    public function __construct(
        private readonly array $middlewares,
        private readonly RequestHandlerInterface $finalHandler,
        private readonly int $index = 0,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middlewares[$this->index])) {
            return $this->finalHandler->handle($request);
        }

        $middleware = $this->middlewares[$this->index];
        $next = new self($this->middlewares, $this->finalHandler, $this->index + 1);

        return $middleware->process($request, $next);
    }

    /**
     * 剩余待执行中间件数量
     */
    public function getRemainingCount(): int
    {
        return max(0, count($this->middlewares) - $this->index);
    }

    /**
     * 是否还有后续中间件
     */
    public function hasNext(): bool
    {
        return isset($this->middlewares[$this->index]);
    }
}
