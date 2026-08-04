<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 响应耗时中间件
 *
 * 记录请求处理耗时并写入响应头，便于性能观测。
 * 使用 hrtime() 纳秒级计时，精度远高于 microtime()。
 *
 * @example
 * ```php
 * $app->pipe(new ResponseTime());                 // 默认 X-Response-Time: 1.23ms
 * $app->pipe(new ResponseTime(inMilliseconds: false)); // 微秒
 * ```
 */
final class ResponseTime implements MiddlewareInterface
{
    public const string HEADER = 'X-Response-Time';

    /**
     * @param string $header          响应头名称
     * @param bool   $inMilliseconds  true 输出毫秒（默认），false 输出微秒
     */
    public function __construct(
        private string $header = self::HEADER,
        private bool $inMilliseconds = true,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = hrtime(true);

        $response = $handler->handle($request);

        $elapsed = hrtime(true) - $start;

        $value = $this->inMilliseconds
            ? sprintf('%.2fms', $elapsed / 1_000_000)
            : sprintf('%.3fµs', $elapsed / 1_000);

        return $response->withHeader($this->header, $value);
    }
}
