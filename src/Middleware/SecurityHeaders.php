<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 安全响应头中间件
 *
 * 为响应补充常见的安全防护头（X-Content-Type-Options、X-Frame-Options、
 * Referrer-Policy 等），并可选开启 HSTS。仅对缺失的头做补充，不覆盖既有配置。
 *
 * @example
 * ```php
 * $app->pipe(new SecurityHeaders());                       // 默认安全头
 * $app->pipe(new SecurityHeaders(hsts: true));             // 额外开启 HSTS
 * $app->pipe(new SecurityHeaders(headers: ['X-Frame-Options' => 'SAMEORIGIN']));
 * ```
 */
final class SecurityHeaders implements MiddlewareInterface
{
    /** 默认安全头集合 */
    public const array DEFAULTS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), camera=(), microphone=()',
    ];

    public const string HSTS_HEADER = 'Strict-Transport-Security';

    /**
     * @param array<string, string> $headers 自定义安全头（覆盖默认值）
     * @param bool                  $hsts    是否注入 HSTS 头
     * @param int                   $hstsMaxAge HSTS 有效期（秒）
     */
    public function __construct(
        private array $headers = self::DEFAULTS,
        private bool $hsts = false,
        private int $hstsMaxAge = 31536000,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        if ($this->hsts) {
            $response = $response->withHeader(
                self::HSTS_HEADER,
                sprintf('max-age=%d; includeSubDomains', $this->hstsMaxAge)
            );
        }

        return $response;
    }
}
