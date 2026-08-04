<?php

declare(strict_types=1);

namespace Kode\Http;

/**
 * HTTP 请求方法枚举
 *
 * @example
 * ```php
 * Method::GET->value;             // 'GET'
 * Method::POST->isIdempotent();   // false
 * Method::normalize('get');       // 'GET'
 * ```
 */
enum Method: string
{
    case GET = 'GET';
    case HEAD = 'HEAD';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case OPTIONS = 'OPTIONS';
    case TRACE = 'TRACE';
    case CONNECT = 'CONNECT';

    /** @var list<string> 常用于路由 any() 注册的方法集合 */
    public const array ROUTABLE = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * 规范化方法名（大写）
     */
    public static function normalize(string $method): string
    {
        return strtoupper(trim($method));
    }

    /**
     * 是否为安全方法（不修改服务端状态）
     */
    public function isSafe(): bool
    {
        return in_array($this, [self::GET, self::HEAD, self::OPTIONS, self::TRACE], true);
    }

    /**
     * 是否为幂等方法
     */
    public function isIdempotent(): bool
    {
        return in_array($this, [self::GET, self::HEAD, self::PUT, self::DELETE, self::OPTIONS, self::TRACE], true);
    }

    /**
     * 该方法是否通常携带请求体
     */
    public function hasBody(): bool
    {
        return in_array($this, [self::POST, self::PUT, self::PATCH, self::DELETE], true);
    }
}
