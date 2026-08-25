<?php

declare(strict_types=1);

namespace Kode\Http;

use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * 统一响应构建器（同时是真实的 PSR-7 响应）
 *
 * 借鉴 Laravel/ThinkPHP 的链式设计，提供流畅的响应构建体验，
 * 完全兼容 PSR-7。所有 with/set 类方法均原地修改自身并返回 `$this`（自 v3.4 起为可变，仿 webman / hyperf）。
 *
 * 自 v3.3 起，`Kode\Http\Response` **直接继承**真实 PSR-7 实现
 * （{@see \Kode\Http\Psr7\Message\Response}），工厂方法与辅助方法都落在同一类上：
 * 因此 `Response::json()` / `error()` / `success()` / `fail()` 返回的就是真实 PSR-7
 * 响应，中间件里可直接 `return Response::json(...)`，无需再调用 `->send()`。
 * （`->send()` 保留为向后兼容的空操作。）
 *
 * v3.0 新增：Cookie、文件流式下载、分块流式输出、JSONP、禁用缓存、
 * 返回值归一化 {@see Response::resolve()}，并修复 json_encode 失败与
 * 大文件下载整体读入内存的问题。
 *
 * @example
 * ```php
 * Response::success(['id' => 1]);
 * return Response::json(['a' => 1])->withCors()->header('X-Trace', $id);   // 直接 return，无需 ->send()
 * Response::file('/data/report.pdf');                 // 流式下载，不占内存
 * Response::stream(fn() => yield from $rows);          // 分块输出
 * Response::success()->cookie('token', $jwt, httpOnly: true);
 * ```
 */
class Response extends Psr7\Message\Response
{
    /** @var int 默认 JSON 编码选项 */
    public const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * 预构建模板：默认 200 + Content-Type: application/json; charset=utf-8。
     *
     * 构造一次、永久复用——`json()` / `make()` 快路径 `clone` 模板后直接写 `rawBody`，
     * 跳过每请求 `new self()` → 构造函数 → `initializeHeaders()`（含 `strtolower` 规范化循环）
     * 的对象分配 + header 规范化开销。
     *
     * 线程安全：模板在首次访问时惰性构建，此后只读不改；`clone` 产生独立副本
     * （PHP 数组 COW），对模板的 `headers` / `headerNames` 无写回风险。
     *
     * @var self|null
     */
    private static ?Response $template = null;

    /**
     * 构建默认模板（200 + application/json）。
     */
    private static function buildTemplate(): self
    {
        return new self();
    }

    /**
     * 构造函数：默认 Content-Type 为 application/json
     *
     * 兼容 PSR-7 基类签名，仅在未显式指定 Content-Type 时填充 JSON 默认值，
     * 使 `Response::json()` 等工厂方法天然携带正确的内容类型。
     */
    public function __construct(
        int $statusCode = 200,
        array $headers = [],
        StreamInterface|string|null $body = null,
        string $protocolVersion = '1.1',
        string $reasonPhrase = ''
    ) {
        $headers['Content-Type'] ??= 'application/json; charset=utf-8';

        parent::__construct($statusCode, $headers, $body, $protocolVersion, $reasonPhrase);
    }

    /**
     * 创建空白响应
     *
     * 热路径优化：默认参数（200 + 无额外 headers）走 `clone` 模板快路径，
     * 跳过构造函数 + `initializeHeaders()` + `status()` + `body()` 方法调用链；
     * 非默认参数回落到完整构造路径。
     */
    public static function make(string $body = '', int $status = 200, array $headers = []): self
    {
        if ($status === 200 && $headers === []) {
            $response = clone (self::$template ??= self::buildTemplate());
            $response->rawBody = $body;
            return $response;
        }

        $response = (new self())->status($status)->body($body);
        foreach ($headers as $name => $value) {
            $response = $response->header($name, (string) $value);
        }
        return $response;
    }

    /**
     * 创建 JSON 响应
     *
     * 热路径优化：`clone` 预构建模板后直接写 `rawBody`，跳过每请求
     * `new self()` → `initializeHeaders()`（`strtolower` 规范化循环）开销。
     *
     * @param mixed $data 任意可 JSON 序列化的数据
     * @param int $code 业务错误码，大于 0 时包裹为 {code, data}
     * @throws \JsonException 数据无法编码时抛出
     */
    public static function json(mixed $data, int $code = 0): self
    {
        $payload = $code > 0 ? ['code' => $code, 'data' => $data] : $data;

        $response = clone (self::$template ??= self::buildTemplate());
        $response->rawBody = self::encode($payload);
        return $response;
    }

    /**
     * 创建 JSONP 响应
     */
    public static function jsonp(mixed $data, string $callback = 'callback'): self
    {
        if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$.]*$/', $callback) !== 1) {
            throw new \InvalidArgumentException('非法的 JSONP 回调函数名');
        }

        return (new self())
            ->body($callback . '(' . self::encode($data) . ');')
            ->type('application/javascript; charset=utf-8');
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
     * 创建分页响应
     *
     * @param list<mixed> $items 当前页数据
     */
    public static function paginate(array $items, int $total, int $page = 1, int $perPage = 20): self
    {
        return self::success([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_page' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ]);
    }

    /**
     * 创建纯文本响应
     */
    public static function text(string $content): self
    {
        return (new self())->body($content)->type('text/plain; charset=utf-8');
    }

    /**
     * 创建 HTML 响应
     */
    public static function html(string $content): self
    {
        return (new self())->body($content)->type('text/html; charset=utf-8');
    }

    /**
     * 创建 XML 响应
     */
    public static function xml(string $content): self
    {
        return (new self())->body($content)->type('application/xml; charset=utf-8');
    }

    /**
     * 创建空响应（204 等）
     */
    public static function empty(int $status = 204): self
    {
        return (new self())->status($status)->body('')->withoutHeader('Content-Type');
    }

    /**
     * 重定向响应
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return (new self())->status($status)->header('Location', $url)->body('');
    }

    /**
     * 文件下载响应（流式读取，不会把整个文件载入内存）
     *
     * @param string $filepath 文件路径
     * @param string|null $filename 下载文件名，默认取原文件名
     * @param bool $inline true 为浏览器内联预览，false 为附件下载
     * @throws \RuntimeException 文件不存在或不可读时抛出
     */
    public static function file(string $filepath, ?string $filename = null, bool $inline = false): self
    {
        if (!is_file($filepath) || !is_readable($filepath)) {
            throw new \RuntimeException('文件不存在或不可读: ' . $filepath);
        }

        $filename ??= basename($filepath);
        $size = filesize($filepath);
        $disposition = $inline ? 'inline' : 'attachment';

        return (new self())
            ->withStream(Stream::createFromFile($filepath))
            ->type(self::mimeTypeOf($filepath))
            ->header('Content-Disposition', sprintf(
                '%s; filename="%s"; filename*=UTF-8\'\'%s',
                $disposition,
                str_replace('"', '', $filename),
                rawurlencode($filename)
            ))
            ->header('Content-Length', (string) ($size === false ? 0 : $size));
    }

    /**
     * 文件下载响应（保留旧 API，等价于 file() 的附件模式）
     */
    public static function download(string $filepath, ?string $filename = null): self
    {
        return self::file($filepath, $filename)->type('application/octet-stream');
    }

    /**
     * 分块流式响应
     *
     * @param callable(): iterable<string> $producer 产出字符串块的生成器/回调
     */
    public static function stream(callable $producer, string $contentType = 'text/plain; charset=utf-8'): self
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new \RuntimeException('无法创建流式响应缓冲区');
        }

        foreach ($producer() as $chunk) {
            fwrite($resource, (string) $chunk);
        }
        rewind($resource);

        return (new self())
            ->withStream(Stream::createFromResource($resource))
            ->type($contentType);
    }

    /**
     * 服务端推送事件（SSE）响应
     *
     * @param iterable<array{event?: string, data: mixed, id?: string}> $events
     */
    public static function sse(iterable $events): self
    {
        $payload = '';
        foreach ($events as $event) {
            if (isset($event['id'])) {
                $payload .= 'id: ' . $event['id'] . "\n";
            }
            if (isset($event['event'])) {
                $payload .= 'event: ' . $event['event'] . "\n";
            }
            $data = is_string($event['data'] ?? '') ? $event['data'] : self::encode($event['data'] ?? null);
            $payload .= 'data: ' . $data . "\n\n";
        }

        return (new self())
            ->body($payload)
            ->type('text/event-stream')
            ->header('Cache-Control', 'no-cache')
            ->header('X-Accel-Buffering', 'no');
    }

    /**
     * 将处理器的任意返回值归一化为 PSR-7 响应
     */
    public static function resolve(mixed $result): ResponseInterface
    {
        return match (true) {
            $result instanceof ResponseInterface => $result,
            $result === null => self::empty(),
            is_string($result) => self::html($result),
            is_array($result), $result instanceof \JsonSerializable => self::json($result),
            is_scalar($result) => self::json($result),
            $result instanceof \Stringable => self::html((string) $result),
            default => self::json($result),
        };
    }

    /**
     * 设置响应体
     *
     * 直接缓存原始字符串，延迟到真正需要时才物化为 Stream（emit 快速路径可直接写出）。
     */
    public function body(string $body): self
    {
        $this->rawBody = $body;
        $this->body = null;
        return $this;
    }

    /**
     * 是否为 JSON Content-Type（轻量读取，不触发 PSR-7 header 规范化）
     *
     * 热路径守卫：JsonErrorHandler 每请求调用 isJsonContentType 判定，
     * 对 Kode 自研响应直接读内部 headers 数组（构造默认即
     * application/json; charset=utf-8），省去 getHeaderLine 的
     * normalizeHeaderName 全表遍历 + implode 开销（~1-2µs/请求）。
     *
     * 键名解析走 headerNames（小写→原始大小写）映射，与 getHeaderLine
     * 语义完全一致：无论构造时传入 'Content-Type' 还是 'content-type'
     * 均可命中，不会因大小写变体漏判。
     */
    public function isJsonContentType(): bool
    {
        $key = $this->headerNames['content-type'] ?? null;
        if ($key === null) {
            return false;
        }
        $ct = $this->headers[$key][0] ?? '';
        return str_contains(strtolower($ct), 'application/json');
    }

    /**
     * 获取响应体（字符串形式）
     */
    public function getBodyString(): string
    {
        return $this->rawBody ?? (string) parent::getBody();
    }

    /**
     * 是否持有未物化的原始字符串体（用于 Emitter 快速路径）
     */
    public function hasRawBody(): bool
    {
        return $this->rawBody !== null;
    }

    /**
     * 获取原始字符串体（未物化时直接返回，否则退回 getBody() 字符串形式）
     */
    public function getRawBody(): string
    {
        return $this->rawBody ?? (string) $this->getBody();
    }

    /**
     * 使用 PSR-7 流作为响应体
     */
    public function withStream(StreamInterface $stream): self
    {
        return $this->withBody($stream);
    }

    /**
     * 设置 HTTP 状态码
     */
    public function status(int $code): self
    {
        if ($code < 100 || $code > 599) {
            throw new \InvalidArgumentException('非法的 HTTP 状态码: ' . $code);
        }

        return $this->withStatus($code);
    }

    /**
     * 获取状态码（向后兼容别名）
     */
    public function getStatus(): int
    {
        return $this->getStatusCode();
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
        return $this->withHeader($name, $value);
    }

    /**
     * 批量设置响应头
     *
     * @param array<string, string> $headers
     */
    public function headers(array $headers): self
    {
        $new = $this;
        foreach ($headers as $name => $value) {
            $new = $new->header($name, (string) $value);
        }
        return $new;
    }

    /**
     * 设置 Cookie（写入 Set-Cookie 头，协程安全且可多次叠加）
     *
     * @param int $expires 过期时间戳，0 表示会话 Cookie
     * @param string $sameSite Lax / Strict / None
     */
    public function cookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        $parts = [rawurlencode($name) . '=' . rawurlencode($value)];

        if ($expires > 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT';
            $parts[] = 'Max-Age=' . max(0, $expires - time());
        }
        if ($path !== '') {
            $parts[] = 'Path=' . $path;
        }
        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }
        if ($secure) {
            $parts[] = 'Secure';
        }
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }
        if ($sameSite !== '') {
            $parts[] = 'SameSite=' . $sameSite;
        }

        return $this->withAddedHeader('Set-Cookie', implode('; ', $parts));
    }

    /**
     * 删除 Cookie
     */
    public function withoutCookie(string $name, string $path = '/', string $domain = ''): self
    {
        return $this->cookie($name, '', 1, $path, $domain);
    }

    /**
     * 添加 CORS 头
     */
    public function withCors(?string $origin = '*', bool $credentials = false): self
    {
        $response = $this
            ->header('Access-Control-Allow-Origin', $origin ?? '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->header('Access-Control-Max-Age', '86400');

        // 携带凭证时不允许通配来源
        if ($credentials && $origin !== null && $origin !== '*') {
            $response = $response
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Vary', 'Origin');
        }

        return $response;
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
     * 禁用缓存
     */
    public function noCache(): self
    {
        return $this
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * 添加安全响应头
     */
    public function withSecurity(): self
    {
        return $this
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /**
     * 设置 ETag，并可根据请求头判断 304
     */
    public function withEtag(?string $etag = null, bool $weak = false): self
    {
        $etag ??= md5((string) parent::getBody());
        $value = ($weak ? 'W/' : '') . '"' . trim($etag, '"') . '"';
        return $this->header('ETag', $value);
    }

    /**
     * 返回自身（已是 PSR-7 响应）
     */
    public function toResponse(): ResponseInterface
    {
        return $this;
    }

    /**
     * 发送响应（返回 PSR-7 响应对象）
     *
     * 历史遗留方法，保留以向后兼容。自 v3.3 起工厂方法已直接返回真实 PSR-7，
     * 中间件里 `return Response::json(...)` 即可，无需调用本方法。
     */
    public function send(): ResponseInterface
    {
        return $this;
    }

    /**
     * 直接输出到 SAPI 并结束构建
     */
    public function end(): void
    {
        Emitter::emit($this);
    }

    /**
     * 安全的 JSON 编码
     *
     * @throws \JsonException
     */
    private static function encode(mixed $data): string
    {
        return json_encode($data, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
    }

    /**
     * 根据扩展名推断 MIME 类型
     */
    private static function mimeTypeOf(string $filepath): string
    {
        static $map = [
            'css' => 'text/css',
            'csv' => 'text/csv',
            'gif' => 'image/gif',
            'html' => 'text/html; charset=utf-8',
            'ico' => 'image/x-icon',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'mp4' => 'video/mp4',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'txt' => 'text/plain; charset=utf-8',
            'webp' => 'image/webp',
            'woff2' => 'font/woff2',
            'xml' => 'application/xml',
            'zip' => 'application/zip',
        ];

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

        return $map[$ext] ?? 'application/octet-stream';
    }
}
