<?php

declare(strict_types=1);

namespace Kode\Http\Integration;

use Kode\Http\Kode;
use Kode\Http\Queue\Queue;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 队列派发中间件
 *
 * 在路由处理器执行期间，业务代码可通过 {@see Queue::push()} 收集后台任务，
 * 本中间件在处理器返回响应后统一把收集到的任务派发到 kode/queue，避免阻塞响应。
 *
 * - 任务收集基于 kode/context 的请求作用域，协程安全（Swoole / Fiber 下互不串号）。
 * - 未注入 QueueManager 时自动懒加载内存驱动，便于本地开发与测试。
 * - 可配置是否在响应头回显派发数量（默认开启，便于观测）。
 *
 * 用法：
 * ```php
 * $app->pipe(QueueMiddleware::fromContainer(Kode::container()));
 * ```
 */
final class QueueMiddleware implements MiddlewareInterface
{
    /** @var bool 是否在响应头回显派发数量 */
    private bool $exposeHeader;

    /** @var string 回显用的响应头名称 */
    private string $headerName;

    /**
     * @param bool   $exposeHeader 是否回显派发数量到响应头
     * @param string $headerName   回显头名称
     */
    public function __construct(bool $exposeHeader = true, string $headerName = 'X-Queue-Dispatched')
    {
        $this->exposeHeader = $exposeHeader;
        $this->headerName = $headerName;
    }

    /**
     * 从 PSR-11 容器构建（尝试解析 kode/queue 的 QueueManager）
     */
    public static function fromContainer(\Psr\Container\ContainerInterface $container, bool $exposeHeader = true): self
    {
        if (class_exists(Queue::class)) {
            Queue::setManagerFromContainer($container);
        }

        return new self($exposeHeader);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (class_exists(Kode::class) && Kode::hasService(\Kode\Queue\QueueManager::class)) {
            Queue::setManagerFromContainer(Kode::container(), \Kode\Queue\QueueManager::class);
        }

        $response = $handler->handle($request);

        $dispatched = Queue::flush();

        if ($this->exposeHeader) {
            $response = $response->withHeader($this->headerName, (string) $dispatched);
        }

        return $response;
    }
}
