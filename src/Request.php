<?php

declare(strict_types=1);

namespace Kode\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 统一请求解析助手
 *
 * 借鉴 ThinkPHP/Laravel/webman 的简洁设计，提供无心智负担的请求解析方法
 * 完全兼容 PSR-7 标准
 *
 * @example
 * ```php
 * // 基础获取（类似 webman）
 * Request::get('name');           // GET 参数
 * Request::post('name');          // POST 参数
 * Request::json('name');          // JSON body 参数
 *
 * // 字段选择（类似 Laravel）
 * Request::only('name', 'email');          // 仅获取指定字段
 * Request::except('password', 'token');    // 排除指定字段
 *
 * // 判断存在
 * Request::has('name');           // 参数是否存在
 * Request::missing('token');      // 参数是否缺失
 *
 * // 便捷方法
 * Request::ip();                  // 客户端 IP
 * Request::isAjax();              // 是否 AJAX
 * ```
 */
class Request
{
    /** @var ServerRequestInterface|null 当前请求 */
    private static ?ServerRequestInterface $currentRequest = null;

    /**
     * 设置当前请求（通常由中间件或服务端适配器调用）
     */
    public static function setRequest(ServerRequestInterface $request): void
    {
        self::$currentRequest = $request;
    }

    /**
     * 获取当前请求
     */
    public static function getRequest(): ?ServerRequestInterface
    {
        return self::$currentRequest;
    }

    /**
     * 获取 GET 参数（等价于 query）
     */
    public static function get(?string $key = null, mixed $default = null): mixed
    {
        $request = self::getRequest();
        if (!$request) {
            return $default;
        }
        $params = $request->getQueryParams();
        if ($key === null) {
            return $params;
        }
        return $params[$key] ?? $default;
    }

    /**
     * 获取 POST 参数
     */
    public static function post(?string $key = null, mixed $default = null): mixed
    {
        $request = self::getRequest();
        if (!$request) {
            return $default;
        }
        $params = $request->getParsedBody() ?? [];
        if ($key === null) {
            return $params;
        }
        return $params[$key] ?? $default;
    }

    /**
     * 获取 JSON 参数（从 request body 解析）
     */
    public static function json(?string $key = null, mixed $default = null): mixed
    {
        $request = self::getRequest();
        if (!$request) {
            return $default;
        }
        $data = $request->getAttribute('_parsed_json');
        if ($data === null) {
            $body = (string) $request->getBody();
            $data = !empty($body) ? (json_decode($body, true) ?? []) : [];
            $request = $request->withAttribute('_parsed_json', $data);
            self::setRequest($request);
        }
        if ($key === null) {
            return $data;
        }
        return $data[$key] ?? $default;
    }

    /**
     * 获取所有参数（merge query + body）
     */
    public static function all(): array
    {
        $request = self::getRequest();
        if (!$request) {
            return [];
        }
        return array_merge($request->getQueryParams(), $request->getParsedBody() ?? []);
    }

    /**
     * 仅获取指定字段
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
     * 判断参数是否存在
     */
    public static function has(string $key): bool
    {
        $data = self::all();
        return isset($data[$key]) && $data[$key] !== '';
    }

    /**
     * 判断参数是否缺失
     */
    public static function missing(string $key): bool
    {
        return !self::has($key);
    }

    /**
     * 获取请求头
     */
    public static function header(string $name, ?string $default = null): ?string
    {
        $request = self::getRequest();
        if (!$request) {
            return $default;
        }
        return $request->getHeaderLine($name) ?: $default;
    }

    /**
     * 获取请求属性
     */
    public static function attr(string $name, mixed $default = null): mixed
    {
        $request = self::getRequest();
        if (!$request) {
            return $default;
        }
        return $request->getAttribute($name, $default);
    }

    /**
     * 获取请求方法
     */
    public static function method(): string
    {
        $request = self::getRequest();
        return $request ? $request->getMethod() : 'GET';
    }

    /**
     * 获取请求路径
     */
    public static function path(): string
    {
        $request = self::getRequest();
        return $request ? $request->getUri()->getPath() : '/';
    }

    /**
     * 获取完整 URI
     */
    public static function uri(): string
    {
        $request = self::getRequest();
        return $request ? (string) $request->getUri() : '/';
    }

    /**
     * 获取客户端 IP
     */
    public static function ip(): ?string
    {
        $request = self::getRequest();
        if (!$request) {
            return null;
        }
        return $request->getAttribute('client_ip')
            ?? $request->getHeaderLine('X-Forwarded-For')
            ?? $request->getHeaderLine('X-Real-IP')
            ?? null;
    }

    /**
     * 检查是否是 AJAX 请求
     */
    public static function isAjax(): bool
    {
        $request = self::getRequest();
        if (!$request) {
            return false;
        }
        return strtolower($request->getHeaderLine('X-Requested-With') ?? '') === 'xmlhttprequest';
    }

    /**
     * 检查是否是 JSON 请求
     */
    public static function isJson(): bool
    {
        $request = self::getRequest();
        if (!$request) {
            return false;
        }
        return str_contains(strtolower($request->getHeaderLine('Content-Type') ?? ''), 'application/json');
    }

    /**
     * 检查是否是 GET 请求
     */
    public static function isGet(): bool
    {
        return self::method() === 'GET';
    }

    /**
     * 检查是否是 POST 请求
     */
    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /**
     * 检查是否是 PUT 请求
     */
    public static function isPut(): bool
    {
        return self::method() === 'PUT';
    }

    /**
     * 检查是否是 DELETE 请求
     */
    public static function isDelete(): bool
    {
        return self::method() === 'DELETE';
    }

    /**
     * 检查是否是 PATCH 请求
     */
    public static function isPatch(): bool
    {
        return self::method() === 'PATCH';
    }

    /**
     * 检查是否是 OPTIONS 请求
     */
    public static function isOptions(): bool
    {
        return self::method() === 'OPTIONS';
    }

    /**
     * 检查请求是否来自移动端
     */
    public static function isMobile(): bool
    {
        $ua = self::header('User-Agent', '');
        return (bool) preg_match('/(android|bb\d+|meego)|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', strtolower($ua));
    }

    /**
     * 获取 Accept-Language
     */
    public static function language(?string $default = 'zh-CN'): string
    {
        $lang = self::header('Accept-Language', '');
        if (empty($lang)) {
            return $default;
        }
        preg_match('/([a-z]{1,8}(?:-[a-z]{1,8})?)/i', $lang, $matches);
        return $matches[1] ?? $default;
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
     * 获取请求时间戳
     */
    public static function time(): float
    {
        $request = self::getRequest();
        return $request ? (float) ($request->getAttribute('request_time') ?? microtime(true)) : microtime(true);
    }

    /**
     * 获取上传的文件
     */
    public static function file(string $name): ?array
    {
        $request = self::getRequest();
        if (!$request) {
            return null;
        }
        $files = $request->getUploadedFiles();
        return $files[$name] ?? null;
    }

    /**
     * 获取所有上传的文件
     */
    public static function files(): array
    {
        $request = self::getRequest();
        return $request ? $request->getUploadedFiles() : [];
    }

    /**
     * 获取 Cookie
     */
    public static function cookie(?string $name = null, mixed $default = null): mixed
    {
        $request = self::getRequest();
        if (!$request) {
            return $default;
        }
        $cookies = $request->getCookieParams();
        if ($name === null) {
            return $cookies;
        }
        return $cookies[$name] ?? $default;
    }

    /**
     * 获取服务器变量
     */
    public static function server(string $name, mixed $default = null): mixed
    {
        $request = self::getRequest();
        if (!$request) {
            return $default;
        }
        $server = $request->getServerParams();
        return $server[strtoupper($name)] ?? $default;
    }
}
