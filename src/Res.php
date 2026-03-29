<?php

declare(strict_types=1);

namespace Kode\Http;

use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 统一响应构建器
 *
 * 提供简洁标准的响应构建方法，支持链式调用
 *
 * @example
 * ```php
 * // 基础响应
 * Res::text('Hello')->send();
 * Res::html('<h1>Title</h1>')->withHeader('Cache-Control', 'no-cache')->send();
 *
 * // JSON 响应
 * Res::json(['data' => 'value'])->send($request);
 * Res::success(['id' => 1], '操作成功')->send($request);
 * Res::fail('操作失败', 'E1001')->send($request);
 *
 * // 错误响应
 * Res::error(404, 'Not Found')->send($request);
 * ```
 */
class Res
{
    /** @var int HTTP 状态码 */
    protected int $statusCode = 200;

    /** @var array<string, string|string[]> 响应头 */
    protected array $headers = ['Content-Type' => 'application/json'];

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

        return (new self())->withBody($body)->withHeader('Content-Type', 'application/json');
    }

    /**
     * 创建成功响应
     */
    public static function success(mixed $data = null, string $message = 'OK', int $code = 0): self
    {
        $body = json_encode([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (new self())->withBody($body)->withStatus(200);
    }

    /**
     * 创建失败响应
     */
    public static function fail(string $message, string $errorCode = 'E1001', int $httpStatus = 400): self
    {
        $body = json_encode([
            'success' => false,
            'code' => $errorCode,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (new self())->withBody($body)->withStatus($httpStatus);
    }

    /**
     * 创建错误响应
     */
    public static function error(int $httpStatus, string $message, ?string $errorCode = null): self
    {
        $body = json_encode([
            'success' => false,
            'code' => $errorCode ?? 'E' . $httpStatus,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (new self())->withBody($body)->withStatus($httpStatus);
    }

    /**
     * 创建文本响应
     */
    public static function text(string $content): self
    {
        return (new self())->withBody($content)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * 创建 HTML 响应
     */
    public static function html(string $content): self
    {
        return (new self())->withBody($content)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 创建空响应（用于 204 等）
     */
    public static function empty(int $status = 204): self
    {
        return (new self())->withStatus($status);
    }

    /**
     * 重定向响应
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return (new self())->withStatus($status)->withHeader('Location', $url);
    }

    /**
     * 下载响应
     */
    public static function download(string $filepath, ?string $filename = null): self
    {
        $filename = $filename ?? basename($filepath);
        return (new self())
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) filesize($filepath))
            ->withBody(file_get_contents($filepath));
    }

    /**
     * 设置 HTTP 状态码
     */
    public function withStatus(int $code): self
    {
        $new = clone $this;
        $new->statusCode = $code;
        return $new;
    }

    /**
     * 设置响应头
     */
    public function withHeader(string $name, string $value): self
    {
        $new = clone $this;
        $new->headers[$name] = $value;
        return $new;
    }

    /**
     * 设置响应体
     */
    public function withBody(string $body): self
    {
        $new = clone $this;
        $new->body = $body;
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
     * 获取响应头
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * 获取响应体
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * 转换为 PSR-7 Response
     */
    public function toResponse(): Response
    {
        return new Response($this->statusCode, $this->headers, Stream::create($this->body));
    }

    /**
     * 发送响应（自动根据请求类型决定如何发送）
     */
    public function send(?ServerRequestInterface $request = null): Response
    {
        $response = $this->toResponse();

        if ($request !== null && Req::isAjax($request)) {
            $response = $response->withHeader('X-Requested-With', 'XMLHttpRequest');
        }

        return $response;
    }

    /**
     * 添加 CORS 头
     */
    public function withCors(?string $origin = '*'): self
    {
        return $this
            ->withHeader('Access-Control-Allow-Origin', $origin ?? '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    /**
     * 添加缓存头
     */
    public function withCache(int $maxAge = 3600, bool $isPublic = true): self
    {
        $visibility = $isPublic ? 'public' : 'private';
        return $this
            ->withHeader('Cache-Control', "{$visibility}, max-age={$maxAge}")
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
    }

    /**
     * 添加安全头
     */
    public function withSecurity(): self
    {
        return $this
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-XSS-Protection', '1; mode=block')
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
