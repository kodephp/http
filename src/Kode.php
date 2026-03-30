<?php

declare(strict_types=1);

namespace Kode\Http;

/**
 * Kode\Http 框架入口类
 *
 * 提供 HTTP 服务端的统一入口和快捷方法
 * 协调 PSR-7/15/17 组件的初始化和运行
 *
 * @method static \Kode\Http\Psr7\Message\Response ok(string $body = '', array $headers = [])
 * @method static \Kode\Http\Psr7\Message\Response json(array $data, int $status = 200)
 * @method static \Kode\Http\Exception\HttpException badRequest(string $message = 'Bad Request')
 * @method static \Kode\Http\Exception\HttpException notFound(string $message = 'Not Found')
 * @method static \Kode\Http\Exception\HttpException internalError(string $message = 'Internal Server Error')
 */
class Kode
{
    /** @var string 版本号 */
    public const VERSION = '2.1.0';

    /** @var array<string, mixed> 全局配置 */
    protected static array $config = [];

    /** @var array<string, object> 已注册的服务实例 */
    protected static array $services = [];

    /**
     * 获取框架版本号
     */
    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * 设置全局配置
     *
     * @param array<string, mixed> $config
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * 获取全局配置项
     *
     * @param string|null $key 配置键，为空则返回全部配置
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return self::$config;
        }
        return self::$config[$key] ?? $default;
    }

    /**
     * 注册服务实例
     *
     * @param string $name 服务名称
     * @param object $service 服务实例
     */
    public static function register(string $name, object $service): void
    {
        self::$services[$name] = $service;
    }

    /**
     * 获取已注册的服务实例
     *
     * @param string $name 服务名称
     * @return object|null
     */
    public static function service(string $name): ?object
    {
        return self::$services[$name] ?? null;
    }

    /**
     * 检查服务是否已注册
     */
    public static function hasService(string $name): bool
    {
        return isset(self::$services[$name]);
    }

    /**
     * 创建 PSR-7 Response 的快捷响应
     *
     * @param int $status HTTP 状态码
     * @param string $body 响应体
     * @param array<string, string|string[]> $headers 响应头
     * @return \Kode\Http\Psr7\Message\Response
     */
    public static function response(int $status = 200, string $body = '', array $headers = []): \Kode\Http\Psr7\Message\Response
    {
        $stream = \Kode\Http\Psr7\Stream::create($body);
        return new \Kode\Http\Psr7\Message\Response($status, $headers, $stream);
    }

    /**
     * 创建 JSON 响应的快捷方法
     *
     * @param array $data JSON 数据
     * @param int $status HTTP 状态码
     * @return \Kode\Http\Psr7\Message\Response
     */
    public static function json(array $data, int $status = 200): \Kode\Http\Psr7\Message\Response
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return self::response($status, $json, ['Content-Type' => 'application/json']);
    }

    /**
     * 创建成功响应的快捷方法
     *
     * @param mixed $data 响应数据
     * @param string $message 成功消息
     * @return \Kode\Http\Psr7\Message\Response
     */
    public static function ok(mixed $data = null, string $message = 'OK'): \Kode\Http\Psr7\Message\Response
    {
        return self::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 创建错误响应的快捷方法
     *
     * @param string $message 错误消息
     * @param int $code 错误码
     * @param int $status HTTP 状态码
     * @return \Kode\Http\Psr7\Message\Response
     */
    public static function error(string $message, int $code = 500, int $status = 500): \Kode\Http\Psr7\Message\Response
    {
        return self::json([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ], $status);
    }

    /**
     * 重置框架状态（主要用于测试）
     */
    public static function reset(): void
    {
        self::$config = [];
        self::$services = [];
    }
}
