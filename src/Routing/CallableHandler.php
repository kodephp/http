<?php

declare(strict_types=1);

namespace Kode\Http\Routing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 可调用请求处理器
 *
 * 将闭包包装为 PSR-15 RequestHandlerInterface，常用作管道的最终处理器。
 *
 * @example
 * ```php
 * $handler = new CallableHandler(fn($req) => Response::success());
 * ```
 */
final class CallableHandler implements RequestHandlerInterface
{
    /** @var \Closure(ServerRequestInterface): ResponseInterface */
    private \Closure $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->callback)($request);
    }
}
