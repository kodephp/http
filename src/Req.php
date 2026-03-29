<?php

declare(strict_types=1);

namespace Kode\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 统一请求解析助手
 *
 * 提供简洁标准的请求数据解析方法
 *
 * @example
 * ```php
 * // 获取查询参数
 * $params = Req::query($request);
 * $name = Req::query($request, 'name', 'default');
 *
 * // 获取 JSON 数据
 * $data = Req::json($request);
 * $email = Req::json($request, 'email');
 *
 * // 便捷判断
 * if (Req::isAjax($request)) { ... }
 * ```
 */
class Req
{
    /**
     * 获取查询参数（GET）
     */
    public static function query(ServerRequestInterface $request, ?string $key = null, mixed $default = null): mixed
    {
        $params = $request->getQueryParams();
        if ($key === null) {
            return $params;
        }
        return $params[$key] ?? $default;
    }

    /**
     * 获取请求体参数（POST/PUT）
     */
    public static function body(ServerRequestInterface $request, ?string $key = null, mixed $default = null): mixed
    {
        $params = $request->getParsedBody() ?? [];
        if ($key === null) {
            return $params;
        }
        return $params[$key] ?? $default;
    }

    /**
     * 获取 JSON 请求体
     */
    public static function json(ServerRequestInterface $request, ?string $key = null, mixed $default = null): mixed
    {
        $data = $request->getAttribute('_parsed_json') ?? self::parseJson($request);
        if ($key === null) {
            return $data;
        }
        return $data[$key] ?? $default;
    }

    /**
     * 解析 JSON 请求体
     */
    public static function parseJson(ServerRequestInterface $request): array
    {
        $body = (string) $request->getBody();
        if (empty($body)) {
            return [];
        }
        $data = json_decode($body, true) ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * 获取请求头
     */
    public static function header(ServerRequestInterface $request, string $name, ?string $default = null): ?string
    {
        return $request->getHeaderLine($name) ?: $default;
    }

    /**
     * 获取请求属性
     */
    public static function attr(ServerRequestInterface $request, string $name, mixed $default = null): mixed
    {
        return $request->getAttribute($name, $default);
    }

    /**
     * 获取所有请求参数（合并 query 和 body）
     */
    public static function params(ServerRequestInterface $request): array
    {
        return array_merge($request->getQueryParams(), $request->getParsedBody() ?? []);
    }

    /**
     * 获取请求方法
     */
    public static function method(ServerRequestInterface $request): string
    {
        return $request->getMethod();
    }

    /**
     * 获取请求路径
     */
    public static function path(ServerRequestInterface $request): string
    {
        return $request->getUri()->getPath();
    }

    /**
     * 获取完整 URI
     */
    public static function uri(ServerRequestInterface $request): string
    {
        return (string) $request->getUri();
    }

    /**
     * 获取客户端 IP
     */
    public static function ip(ServerRequestInterface $request): ?string
    {
        return $request->getAttribute('client_ip')
            ?? $request->getHeaderLine('X-Forwarded-For')
            ?? $request->getHeaderLine('X-Real-IP')
            ?? null;
    }

    /**
     * 检查是否是 AJAX 请求
     */
    public static function isAjax(ServerRequestInterface $request): bool
    {
        return strtolower($request->getHeaderLine('X-Requested-With') ?? '') === 'xmlhttprequest';
    }

    /**
     * 检查是否是 JSON 请求
     */
    public static function isJson(ServerRequestInterface $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Content-Type') ?? ''), 'application/json');
    }

    /**
     * 检查是否是 GET 请求
     */
    public static function isGet(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'GET';
    }

    /**
     * 检查是否是 POST 请求
     */
    public static function isPost(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'POST';
    }

    /**
     * 检查是否是 PUT 请求
     */
    public static function isPut(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'PUT';
    }

    /**
     * 检查是否是 DELETE 请求
     */
    public static function isDelete(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'DELETE';
    }

    /**
     * 检查是否是 PATCH 请求
     */
    public static function isPatch(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'PATCH';
    }

    /**
     * 检查是否是 OPTIONS 请求
     */
    public static function isOptions(ServerRequestInterface $request): bool
    {
        return $request->getMethod() === 'OPTIONS';
    }

    /**
     * 检查请求是否来自移动端
     */
    public static function isMobile(ServerRequestInterface $request): bool
    {
        $ua = $request->getHeaderLine('User-Agent') ?? '';
        return (bool) preg_match('/(android|bb\d+|meego)|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', strtolower($ua));
    }

    /**
     * 获取 Accept-Language
     */
    public static function language(ServerRequestInterface $request, ?string $default = 'zh-CN'): string
    {
        $lang = $request->getHeaderLine('Accept-Language') ?? '';
        if (empty($lang)) {
            return $default;
        }
        preg_match('/([a-z]{1,8}(?:-[a-z]{1,8})?)/i', $lang, $matches);
        return $matches[1] ?? $default;
    }

    /**
     * 获取 User-Agent
     */
    public static function userAgent(ServerRequestInterface $request): ?string
    {
        return $request->getHeaderLine('User-Agent') ?: null;
    }

    /**
     * 获取 Referer
     */
    public static function referer(ServerRequestInterface $request): ?string
    {
        return $request->getHeaderLine('Referer') ?: null;
    }

    /**
     * 获取请求时间戳
     */
    public static function time(ServerRequestInterface $request): float
    {
        return (float) ($request->getAttribute('request_time') ?? microtime(true));
    }

    /**
     * 获取上传的文件
     */
    public static function file(ServerRequestInterface $request, string $name): ?array
    {
        $files = $request->getUploadedFiles();
        return $files[$name] ?? null;
    }

    /**
     * 获取所有上传的文件
     */
    public static function files(ServerRequestInterface $request): array
    {
        return $request->getUploadedFiles();
    }

    /**
     * 获取 Cookie
     */
    public static function cookie(ServerRequestInterface $request, ?string $name = null, mixed $default = null): mixed
    {
        $cookies = $request->getCookieParams();
        if ($name === null) {
            return $cookies;
        }
        return $cookies[$name] ?? $default;
    }

    /**
     * 获取服务器变量
     */
    public static function server(ServerRequestInterface $request, ?string $name = null, mixed $default = null): mixed
    {
        $server = $request->getServerParams();
        if ($name === null) {
            return $server;
        }
        return $server[strtoupper($name)] ?? $default;
    }
}
