<?php

declare(strict_types=1);

namespace Kode\Http;

use Kode\Context\Context;
use Kode\Http\Psr7\Message\LazyServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * 统一请求解析助手
 *
 * 借鉴 ThinkPHP/Laravel/webman 的简洁设计，提供无心智负担的请求解析方法，
 * 完全兼容 PSR-7。
 *
 * v3.0 关键改进：
 * - 当前请求存放在 kode/context 上下文中，Swoole 协程 / Fiber 并发下互不串号；
 * - 修复 ip() 因 getHeaderLine() 返回空串导致 `??` 短路失效的问题；
 * - 新增 input()/integer()/boolean() 等类型安全取值与 bearerToken()、fullUrl() 等便捷方法。
 *
 * @example
 * ```php
 * Request::get('name');                 // GET 参数
 * Request::post('name');                // POST 参数
 * Request::input('page', 1);            // query + body + json 合并取值
 * Request::integer('page', 1);          // 强类型取值
 * Request::only('name', 'email');       // 字段筛选
 * Request::bearerToken();               // Authorization: Bearer xxx
 * Request::ip();                        // 客户端 IP（含代理头解析）
 * ```
 */
class Request
{
    /** @var string 上下文存储键 */
    private const string CONTEXT_KEY = '__kode_http_request';

    /** kode/context 3.x 链路追踪键，随请求写入/清理 */
    private const array TRACE_KEYS = [
        Context::REQUEST_ID,
        Context::TRACE_ID,
        Context::SPAN_ID,
        Context::CORRELATION_ID,
    ];

    /** 链路追踪来源头：守卫判定与（未来）取值共用同一份真相，杜绝清单漂移 */
    private const array TRACE_HEADERS = [
        'X-Request-Id',
        'X-Trace-Id',
        'traceparent',
        'X-Correlation-Id',
    ];

    /** 链路追踪来源头在 $_SERVER 中的键（与 TRACE_HEADERS 一一对应）；
     *  守卫直接扫 server params，避免触发懒加载请求的 header 规范化 */
    private const array TRACE_HEADERS_SERVER = [
        'HTTP_X_REQUEST_ID',
        'HTTP_X_TRACE_ID',
        'HTTP_TRACEPARENT',
        'HTTP_X_CORRELATION_ID',
    ];

    /** @var ServerRequestInterface|null 无上下文组件时的回退存储 */
    private static ?ServerRequestInterface $fallback = null;

    /** @var list<string> 代理来源 IP 头，按优先级排列 */
    public const array IP_HEADERS = [
        'X-Forwarded-For',
        'X-Real-IP',
        'CF-Connecting-IP',
        'True-Client-IP',
        'Client-IP',
    ];

    /**
     * 设置当前请求（由服务端适配器或中间件调用）
     */
    public static function setRequest(ServerRequestInterface $request): void
    {
        if (class_exists(Context::class)) {
            Context::set(self::CONTEXT_KEY, $request);
            self::syncTraceContext($request);
            return;
        }

        self::$fallback = $request;
    }

    /**
     * 获取当前请求
     */
    public static function getRequest(): ?ServerRequestInterface
    {
        if (class_exists(Context::class)) {
            $request = Context::get(self::CONTEXT_KEY);
            return $request instanceof ServerRequestInterface ? $request : null;
        }

        return self::$fallback;
    }

    /**
     * 清除当前请求（请求结束时调用，避免长驻进程内存泄漏）
     */
    public static function clear(): void
    {
        if (class_exists(Context::class)) {
            Context::delete(self::CONTEXT_KEY);

            foreach (self::TRACE_KEYS as $key) {
                Context::delete($key);
            }
        }

        self::$fallback = null;
    }

    /**
     * 将入站请求的链路标识写入 kode/context 3.x 追踪上下文
     *
     * 优先级：X-Request-Id → X-Trace-Id（请求 ID）；traceparent / X-Trace-Id（链路 ID）。
     * 使下游 KodeException、中间件可复用同一链路，实现分布式追踪对齐。
     *
     * 健壮性：绝大多数请求（压测 / 生产）不带任何链路头，先经 {@see hasTraceHeaders}
     * 守卫快速返回 —— 单次仅 4 次 server params 键查找（不调用 hasHeader，故不触发
     * 懒加载请求的 header 规范化）、无任何 Context 写入；对任意多次
     * setRequest 调用（App::handle / RouteRunner / Request::json 等）天然幂等，
     * 且只读写按协程隔离的 kode/context，无进程级共享状态，协程安全。
     */
    private static function syncTraceContext(ServerRequestInterface $request): void
    {
        if (!self::hasTraceHeaders($request)) {
            return;
        }

        $requestId = $request->getHeaderLine('X-Request-Id')
            ?: $request->getHeaderLine('X-Trace-Id')
            ?: '';
        if ($requestId !== '') {
            Context::set(Context::REQUEST_ID, $requestId);
        }

        $traceId = $request->getHeaderLine('traceparent')
            ?: $request->getHeaderLine('X-Trace-Id')
            ?: '';
        if ($traceId !== '') {
            Context::set(Context::TRACE_ID, $traceId);
        }

        $correlationId = $request->getHeaderLine('X-Correlation-Id') ?: '';
        if ($correlationId !== '') {
            Context::set(Context::CORRELATION_ID, $correlationId);
        }
    }

    /**
     * 请求是否携带任一链路追踪来源头。
     *
     * 直接扫描 server params（$_SERVER 的 HTTP_* 键），**不调用 hasHeader**，
     * 因此不会触发懒加载请求（LazyServerRequest）的 header 规范化，热路径零 header 成本。
     * 取值（syncTraceContext 内的 getHeaderLine）仅在确有链路头时发生，属低频路径。
     */
    private static function hasTraceHeaders(ServerRequestInterface $request): bool
    {
        $server = $request->getServerParams();
        foreach (self::TRACE_HEADERS_SERVER as $key) {
            if (isset($server[$key]) && $server[$key] !== '') {
                return true;
            }
        }

        // 兜底：链路头经 withHeader 程序化设置（如测试 / 手动注入）时不在 server params 中。
        // 对 LazyServerRequest，若尚未解析 header 则直接返回 false，避免强制规范化拖慢热路径；
        // 已解析（如已调用 withHeader）或非懒加载请求时，退回标准 hasHeader 判定。
        if ($request instanceof LazyServerRequest && !$request->isHeadersResolved()) {
            return false;
        }

        foreach (self::TRACE_HEADERS as $header) {
            if ($request->hasHeader($header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断参数是否存在且非空（兼容 v2 语义）
     */
    public static function has(string $key): bool
    {
        return self::filled($key);
    }

    /**
     * 是否已绑定当前请求
     */
    public static function bound(): bool
    {
        return self::getRequest() !== null;
    }

    /**
     * 获取 GET 查询参数
     */
    public static function get(?string $key = null, mixed $default = null): mixed
    {
        $request = self::getRequest();
        if ($request === null) {
            return $key === null ? [] : $default;
        }

        $params = $request->getQueryParams();

        return $key === null ? $params : ($params[$key] ?? $default);
    }

    /**
     * 获取 POST / 表单参数
     */
    public static function post(?string $key = null, mixed $default = null): mixed
    {
        $params = self::parsedBody();

        return $key === null ? $params : ($params[$key] ?? $default);
    }

    /**
     * 获取 JSON body 参数
     */
    public static function json(?string $key = null, mixed $default = null): mixed
    {
        $request = self::getRequest();
        if ($request === null) {
            return $key === null ? [] : $default;
        }

        $data = $request->getAttribute('_parsed_json');

        if (!is_array($data)) {
            $body = (string) $request->getBody();
            $data = ($body !== '' && json_validate($body)) ? (array) json_decode($body, true) : [];
            self::setRequest($request->withAttribute('_parsed_json', $data));
        }

        return $key === null ? $data : ($data[$key] ?? $default);
    }

    /**
     * 合并取值：query < parsedBody < json
     */
    public static function input(?string $key = null, mixed $default = null): mixed
    {
        $data = self::all();

        return $key === null ? $data : ($data[$key] ?? $default);
    }

    /**
     * 获取全部参数（query + body + json 合并）
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $request = self::getRequest();
        if ($request === null) {
            return [];
        }

        $merged = array_merge($request->getQueryParams(), self::parsedBody());

        $json = self::json();
        if (is_array($json) && $json !== []) {
            $merged = array_merge($merged, $json);
        }

        return $merged;
    }

    /**
     * 取整型值
     */
    public static function integer(string $key, int $default = 0): int
    {
        $value = self::input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * 取浮点值
     */
    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::input($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * 取布尔值（支持 "1"/"true"/"yes"/"on"）
     */
    public static function boolean(string $key, bool $default = false): bool
    {
        $value = self::input($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * 取字符串值（自动 trim）
     */
    public static function string(string $key, string $default = ''): string
    {
        $value = self::input($key);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * 取数组值
     *
     * @return array<mixed>
     */
    public static function array(string $key, array $default = []): array
    {
        $value = self::input($key);

        return is_array($value) ? $value : $default;
    }

    /**
     * 仅获取指定字段
     *
     * @return array<string, mixed>
     */
    public static function only(string ...$keys): array
    {
        $data = self::all();
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $result[$key] = $data[$key];
            }
        }

        return $result;
    }

    /**
     * 排除指定字段
     *
     * @return array<string, mixed>
     */
    public static function except(string ...$keys): array
    {
        $data = self::all();
        foreach ($keys as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * 判断参数是否存在且非空字符串
     */
    public static function filled(string $key): bool
    {
        $data = self::all();

        return isset($data[$key]) && $data[$key] !== '' && $data[$key] !== [];
    }

    /**
     * 判断参数键是否存在（允许空值）
     */
    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * 判断参数是否缺失
     */
    public static function missing(string $key): bool
    {
        return !self::filled($key);
    }

    /**
     * 获取请求头
     */
    public static function header(string $name, ?string $default = null): ?string
    {
        $request = self::getRequest();
        if ($request === null) {
            return $default;
        }

        $value = $request->getHeaderLine($name);

        return $value !== '' ? $value : $default;
    }

    /**
     * 获取全部请求头
     *
     * @return array<string, list<string>>
     */
    public static function headers(): array
    {
        return self::getRequest()?->getHeaders() ?? [];
    }

    /**
     * 获取 Bearer Token
     */
    public static function bearerToken(): ?string
    {
        $authorization = self::header('Authorization', '');
        if ($authorization === null || $authorization === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * 获取请求属性
     */
    public static function attr(string $name, mixed $default = null): mixed
    {
        return self::getRequest()?->getAttribute($name, $default) ?? $default;
    }

    /**
     * 获取路由参数
     */
    public static function param(string $name, mixed $default = null): mixed
    {
        $params = self::attr('_route_params', []);

        return is_array($params) ? ($params[$name] ?? $default) : $default;
    }

    /**
     * 获取请求方法
     */
    public static function method(): string
    {
        return strtoupper(self::getRequest()?->getMethod() ?? 'GET');
    }

    /**
     * 获取请求路径
     */
    public static function path(): string
    {
        return self::getRequest()?->getUri()->getPath() ?: '/';
    }

    /**
     * 获取完整 URI 字符串
     */
    public static function uri(): string
    {
        $request = self::getRequest();

        return $request !== null ? (string) $request->getUri() : '/';
    }

    /**
     * 获取不含查询串的完整 URL
     */
    public static function url(): string
    {
        $request = self::getRequest();
        if ($request === null) {
            return '/';
        }

        return (string) $request->getUri()->withQuery('')->withFragment('');
    }

    /**
     * 获取含查询串的完整 URL
     */
    public static function fullUrl(): string
    {
        return self::uri();
    }

    /**
     * 获取协议（http / https）
     */
    public static function scheme(): string
    {
        $request = self::getRequest();
        if ($request === null) {
            return 'http';
        }

        $proto = $request->getHeaderLine('X-Forwarded-Proto');
        if ($proto !== '') {
            return strtolower(explode(',', $proto)[0]);
        }

        return $request->getUri()->getScheme() ?: 'http';
    }

    /**
     * 是否为 HTTPS 请求
     */
    public static function isSecure(): bool
    {
        return self::scheme() === 'https';
    }

    /**
     * 获取主机名
     */
    public static function host(): string
    {
        return self::getRequest()?->getUri()->getHost() ?? '';
    }

    /**
     * 获取端口
     */
    public static function port(): ?int
    {
        return self::getRequest()?->getUri()->getPort();
    }

    /**
     * 获取客户端 IP
     *
     * 依次尝试：显式设置的 client_ip 属性 → 常见代理头 → REMOTE_ADDR。
     *
     * @param bool $trustProxy 是否信任代理头，生产环境应在网关侧收敛后再开启
     */
    public static function ip(bool $trustProxy = true): ?string
    {
        $request = self::getRequest();
        if ($request === null) {
            return null;
        }

        $attribute = $request->getAttribute('client_ip');
        if (is_string($attribute) && $attribute !== '') {
            return $attribute;
        }

        if ($trustProxy) {
            foreach (self::IP_HEADERS as $header) {
                $value = $request->getHeaderLine($header);
                if ($value === '') {
                    continue;
                }
                $ip = trim(explode(',', $value)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
        }

        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remote) && $remote !== '' ? $remote : null;
    }

    /**
     * 是否为 AJAX 请求
     */
    public static function isAjax(): bool
    {
        return strtolower(self::header('X-Requested-With', '') ?? '') === 'xmlhttprequest';
    }

    /**
     * 请求体是否为 JSON
     */
    public static function isJson(): bool
    {
        return str_contains(strtolower(self::header('Content-Type', '') ?? ''), 'json');
    }

    /**
     * 客户端是否期望 JSON 响应
     */
    public static function wantsJson(): bool
    {
        if (self::isJson() || self::isAjax()) {
            return true;
        }

        return str_contains(strtolower(self::header('Accept', '') ?? ''), 'json');
    }

    /**
     * 是否接受指定 MIME 类型
     */
    public static function accepts(string $mimeType): bool
    {
        $accept = strtolower(self::header('Accept', '') ?? '');

        return $accept === '' || str_contains($accept, '*/*') || str_contains($accept, strtolower($mimeType));
    }

    public static function isGet(): bool
    {
        return self::method() === 'GET';
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function isPut(): bool
    {
        return self::method() === 'PUT';
    }

    public static function isDelete(): bool
    {
        return self::method() === 'DELETE';
    }

    public static function isPatch(): bool
    {
        return self::method() === 'PATCH';
    }

    public static function isOptions(): bool
    {
        return self::method() === 'OPTIONS';
    }

    public static function isHead(): bool
    {
        return self::method() === 'HEAD';
    }

    /**
     * 是否为指定方法
     */
    public static function isMethod(string $method): bool
    {
        return self::method() === Method::normalize($method);
    }

    /**
     * 是否来自移动端
     */
    public static function isMobile(): bool
    {
        $ua = self::header('User-Agent', '') ?? '';

        return (bool) preg_match(
            '/(android|bb\d+|meego)|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i',
            strtolower($ua)
        );
    }

    /**
     * 获取首选语言
     */
    public static function language(string $default = 'zh-CN'): string
    {
        $lang = self::header('Accept-Language', '') ?? '';
        if ($lang === '') {
            return $default;
        }

        if (preg_match('/([a-z]{1,8}(?:-[a-zA-Z]{1,8})?)/i', $lang, $matches) === 1) {
            return $matches[1];
        }

        return $default;
    }

    /**
     * 获取 User-Agent
     */
    public static function userAgent(): ?string
    {
        return self::header('User-Agent');
    }

    /**
     * 获取 Referer
     */
    public static function referer(): ?string
    {
        return self::header('Referer');
    }

    /**
     * 获取请求开始时间戳（秒，含小数）
     */
    public static function time(): float
    {
        $request = self::getRequest();
        $time = $request?->getAttribute('request_time');

        return is_numeric($time) ? (float) $time : microtime(true);
    }

    /**
     * 获取单个上传文件
     */
    public static function file(string $name): ?UploadedFileInterface
    {
        $file = self::files()[$name] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    /**
     * 获取全部上传文件
     *
     * @return array<string, UploadedFileInterface|array>
     */
    public static function files(): array
    {
        return self::getRequest()?->getUploadedFiles() ?? [];
    }

    /**
     * 是否包含指定上传文件
     */
    public static function hasFile(string $name): bool
    {
        return self::file($name) !== null;
    }

    /**
     * 获取 Cookie
     */
    public static function cookie(?string $name = null, mixed $default = null): mixed
    {
        $request = self::getRequest();
        if ($request === null) {
            return $name === null ? [] : $default;
        }

        $cookies = $request->getCookieParams();

        return $name === null ? $cookies : ($cookies[$name] ?? $default);
    }

    /**
     * 获取服务器变量
     */
    public static function server(?string $name = null, mixed $default = null): mixed
    {
        $request = self::getRequest();
        if ($request === null) {
            return $name === null ? [] : $default;
        }

        $server = $request->getServerParams();

        return $name === null ? $server : ($server[strtoupper($name)] ?? $default);
    }

    /**
     * 获取解析后的请求体数组
     *
     * @return array<string, mixed>
     */
    private static function parsedBody(): array
    {
        $parsed = self::getRequest()?->getParsedBody();

        if (is_array($parsed)) {
            return $parsed;
        }

        if (is_object($parsed)) {
            return get_object_vars($parsed);
        }

        return [];
    }
}
