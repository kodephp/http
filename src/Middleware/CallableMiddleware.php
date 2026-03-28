<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 可调用中间件适配器
 *
 * 将任意可调用对象（Closure、回调函数）转换为符合 PSR-15 标准的中间件。
 * 这是使用最广泛的中间件封装方式，允许以简洁的闭包形式编写中间件逻辑。
 *
 * 工作原理：
 * 1. 接收一个可调用对象作为构造函数参数
 * 2. 实现 process() 方法，内部调用该可调用对象
 * 3. 可调用对象签名：function(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
 *
 * @example
 * ```php
 * // 使用闭包
 * $middleware = new CallableMiddleware(function ($request, $handler) {
 *     // 前置逻辑
 *     $response = $handler->handle($request);
 *     // 后置逻辑
 *     return $response;
 * });
 *
 * // 使用数组回调
 * $middleware = new CallableMiddleware([MyClass::class, 'handle']);
 *
 * // 使用实例方法
 * $middleware = new CallableMiddleware([$this, 'processRequest']);
 * ```
 */
class CallableMiddleware implements MiddlewareInterface
{
    /** @var \Closure 封装的闭包 */
    private \Closure $callback;

    /**
     * 构造函数
     *
     * @param callable $callback 要封装的可调用对象
     *                          签名：function(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
    }

    /**
     * 处理请求
     *
     * 实现 MiddlewareInterface::process() 方法。
     * 调用封装的回调函数并返回结果。
     *
     * @param ServerRequestInterface $request HTTP 请求
     * @param RequestHandlerInterface $handler 请求处理器
     * @return ResponseInterface HTTP 响应
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return ($this->callback)($request, $handler);
    }
}