<?php

declare(strict_types=1);

namespace Kode\Http;

use Kode\Exception\KodeException;
use Kode\Http\Exception\HttpException;
use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 统一请求响应助手
 *
 * 提供简洁标准的请求解析和响应构建方法
 *
 * @example
 * ```php
 * // 解析请求数据
 * $params = Req::params($request);
 * $json = Req::json($request);
 *
 * // 构建响应
 * Res::ok(['data' => 'value']);
 * Res::error('操作失败', 500);
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
}
