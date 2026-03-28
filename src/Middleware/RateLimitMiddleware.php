<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * 限流中间件
 *
 * 基于客户端 IP 的请求限流实现。
 * 使用滑动时间窗口算法控制请求频率。
 *
 * 功能：
 * - 基于客户端 IP 的限流
 * - 可配置最大请求数和时间窗口
 * - 自动添加限流相关响应头
 * - 支持在超出限制时返回 429 状态码
 *
 * 注意：此实现使用内存存储，适用于单进程场景。
 * 生产环境建议使用 Redis 等外部存储。
 *
 * @example
 * ```php
 * // 每分钟最多 100 次请求
 * $rateLimit = new RateLimitMiddleware(100, 60);
 * $pipeline->pipe($rateLimit);
 * ```
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var int 最大请求数 */
    private int $maxRequests;

    /** @var int 时间窗口（秒） */
    private int $windowSeconds;

    /** @var array 客户端请求记录存储 */
    private array $storage = [];

    /**
     * 构造函数
     *
     * @param int $maxRequests 时间窗口内允许的最大请求数
     * @param int $windowSeconds 时间窗口大小（秒）
     */
    public function __construct(int $maxRequests = 60, int $windowSeconds = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * 处理请求
     *
     * @param ServerRequestInterface $request HTTP 请求
     * @param RequestHandlerInterface $handler 下一个处理器
     * @return Response HTTP 响应
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): Response
    {
        $key = $this->getClientKey($request);
        $now = time();

        // 初始化客户端记录
        if (!isset($this->storage[$key])) {
            $this->storage[$key] = ['requests' => [], 'blocked_until' => 0];
        }

        $clientData = &$this->storage[$key];

        // 检查是否被临时封禁
        if ($clientData['blocked_until'] > $now) {
            return $this->createRateLimitResponse(
                $clientData['blocked_until'] - $now,
                $this->maxRequests,
                0
            );
        }

        // 清理过期的请求记录
        $clientData['requests'] = array_filter(
            $clientData['requests'],
            fn($timestamp) => $timestamp > $now - $this->windowSeconds
        );

        // 检查是否超出限制
        if (count($clientData['requests']) >= $this->maxRequests) {
            $clientData['blocked_until'] = $now + $this->windowSeconds;
            $retryAfter = $this->windowSeconds;

            return $this->createRateLimitResponse($retryAfter, $this->maxRequests, 0);
        }

        // 记录当前请求
        $clientData['requests'][] = $now;
        $remaining = $this->maxRequests - count($clientData['requests']);

        // 处理请求
        $response = $handler->handle($request);

        // 添加限流响应头
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withHeader('X-RateLimit-Reset', (string) ($now + $this->windowSeconds));
    }

    /**
     * 获取客户端唯一标识键
     *
     * 优先使用 X-Forwarded-For（代理环境），其次 X-Real-IP，最后使用默认值。
     *
     * @param ServerRequestInterface $request HTTP 请求
     * @return string 客户端键（MD5 哈希）
     */
    private function getClientKey(ServerRequestInterface $request): string
    {
        $ip = $request->getHeaderLine('X-Forwarded-For')
            ?: $request->getHeaderLine('X-Real-IP')
            ?: 'unknown';

        return md5($ip);
    }

    /**
     * 创建限流响应
     *
     * @param int $retryAfter 距离下次可请求的秒数
     * @param int $limit 最大请求数
     * @param int $remaining 剩余请求数
     * @return Response 429 Too Many Requests 响应
     */
    private function createRateLimitResponse(int $retryAfter, int $limit, int $remaining): Response
    {
        return new Response(
            429,
            [
                'Content-Type' => 'application/json',
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => (string) $remaining,
                'X-RateLimit-Reset' => (string) (time() + $retryAfter),
            ],
            Stream::create(json_encode([
                'error' => 'Too Many Requests',
                'retry_after' => $retryAfter,
            ], JSON_UNESCAPED_UNICODE))
        );
    }

    /**
     * 重置限流记录
     *
     * @param string|null $key 客户端键，null 表示重置所有
     */
    public function reset(string $key = null): void
    {
        if ($key === null) {
            $this->storage = [];
        } elseif (isset($this->storage[$key])) {
            unset($this->storage[$key]);
        }
    }

    /**
     * 获取最大请求数
     *
     * @return int 最大请求数
     */
    public function getMaxRequests(): int
    {
        return $this->maxRequests;
    }

    /**
     * 获取时间窗口大小
     *
     * @return int 时间窗口（秒）
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }
}