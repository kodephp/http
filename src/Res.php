<?php

declare(strict_types=1);

namespace Kode\Http;

use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;

/**
 * 统一响应构建器
 *
 * 借鉴 Laravel/ThinkPHP 的简洁链式设计，提供流畅的响应构建体验
 * 完全兼容 PSR-7 标准
 *
 * @example
 * ```php
 * // 最简使用
 * Res::json(['data' => 'value']);
 * Res::success(['id' => 1], '操作成功');
 *
 * // 链式调用
 * Res::success(['data' => $data])
 *     ->header('X-Custom', 'value')
 *     ->withCors()
 *     ->send();
 *
 * // 直接发送
 * Res::json(['code' => 0, 'data' => []])->send();
 * ```
 */
class Res
{
    /** @var int HTTP 状态码 */
    protected int $statusCode = 200;

    /** @var array<string, string|string[]> 响应头 */
    protected array $headers = ['Content-Type' => 'application/json; charset=utf-8'];

    /** @var string 响应体 */
    protected string $body = '';

    /**
     * 创建 JSON 响应
     */
    public static function json(array $data, int $code = 0): self
    {
        $body = $code > 0
            ? json_encode(['code' => $code, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (new self())->body($body);
    }

    /**
     * 创建成功响应（业务层面成功）
     */
    public static function success(mixed $data = null, string $message = 'OK', int $code = 0): self
    {
        return self::json([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 创建失败响应（业务层面失败）
     */
    public static function fail(string $message, string $errorCode = 'E1001', int $httpStatus = 400): self
    {
        return self::json([
            'success' => false,
            'code' => $errorCode,
            'message' => $message,
        ])->status($httpStatus);
    }

    /**
     * 创建错误响应（系统错误）
     */
    public static function error(int $httpStatus, string $message, ?string $errorCode = null): self
    {
        return self::json([
            'success' => false,
            'code' => $errorCode ?? 'E' . $httpStatus,
            'message' => $message,
        ])->status($httpStatus);
    }

    /**
     * 创建纯文本响应
     */
    public static function text(string $content): self
    {
        return (new self())->body($content)->type('text/plain');
    }

    /**
     * 创建 HTML 响应
     */
    public static function html(string $content): self
    {
        return (new self())->body($content)->type('text/html');
    }

    /**
     * 创建 XML 响应
     */
    public static function xml(string $content): self
    {
        return (new self())->body($content)->type('application/xml');
    }

    /**
     * 创建空响应（204 等）
     */
    public static function empty(int $status = 204): self
    {
        return (new self())->status($status);
    }

    /**
     * 重定向响应
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return (new self())->status($status)->header('Location', $url);
    }

    /**
     * 下载响应
     */
    public static function download(string $filepath, ?string $filename = null): self
    {
        $filename = $filename ?? basename($filepath);
        $filesize = filesize($filepath);
        return (new self())
            ->type('application/octet-stream')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Content-Length', (string) $filesize)
            ->body(file_get_contents($filepath));
    }

    /**
     * 设置响应体
     */
    public function body(string $body): self
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    /**
     * 获取响应体
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * 设置 HTTP 状态码
     */
    public function status(int $code): self
    {
        $new = clone $this;
        $new->statusCode = $code;
        return $new;
    }

    /**
     * 获取状态码
     */
    public function getStatus(): int
    {
        return $this->statusCode;
    }

    /**
     * 设置 Content-Type
     */
    public function type(string $contentType): self
    {
        return $this->header('Content-Type', $contentType);
    }

    /**
     * 设置响应头
     */
    public function header(string $name, string $value): self
    {
        $new = clone $this;
        $new->headers[$name] = $value;
        return $new;
    }

    /**
     * 获取响应头
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * 添加 CORS 头
     */
    public function withCors(?string $origin = '*'): self
    {
        return $this
            ->header('Access-Control-Allow-Origin', $origin ?? '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->header('Access-Control-Max-Age', '86400');
    }

    /**
     * 添加缓存控制头
     */
    public function withCache(int $maxAge = 3600, bool $isPublic = true): self
    {
        $visibility = $isPublic ? 'public' : 'private';
        return $this
            ->header('Cache-Control', "{$visibility}, max-age={$maxAge}")
            ->header('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
    }

    /**
     * 添加安全头
     */
    public function withSecurity(): self
    {
        return $this
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('X-XSS-Protection', '1; mode=block')
            ->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /**
     * 转换为 PSR-7 Response
     */
    public function toResponse(): Response
    {
        return new Response($this->statusCode, $this->headers, Stream::create($this->body));
    }

    /**
     * 发送响应
     */
    public function send(): Response
    {
        return $this->toResponse();
    }

    /**
     * 直接输出并终止（仅适用于 CLI）
     */
    public function end(): void
    {
        $response = $this->toResponse();
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("{$name}: {$value}");
            }
        }
        echo (string) $response->getBody();
    }
}
