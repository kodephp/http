<?php

declare(strict_types=1);

namespace Kode\Http;

use Kode\Facade\Facade;
use Kode\Facade\FacadeProxy;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

/**
 * Kode\Http 框架入口类
 *
 * 提供 HTTP 服务端的统一入口和快捷方法
 * 协调 PSR-7/15/17 组件的初始化和运行
 *
 * 同时实现 PSR-11 容器接口，可无缝接入 {@see FacadeProxy}（kode/facade），
 * 在 Swoole / Fiber 等协程环境下通过 context-safe 模式实现按请求隔离的服务解析。
 *
 * @method static \Kode\Http\Psr7\Message\Response ok(string $body = '', array $headers = [])
 * @method static \Kode\Http\Psr7\Message\Response json(array $data, int $status = 200)
 * @method static \Kode\Http\Exception\HttpException badRequest(string $message = 'Bad Request')
 * @method static \Kode\Http\Exception\HttpException notFound(string $message = 'Not Found')
 * @method static \Kode\Http\Exception\HttpException internalError(string $message = 'Internal Server Error')
 */
class Kode implements ContainerInterface
{
    /** @var string 版本号（与 composer.json 的 version 保持同步） */
    public const VERSION = '3.4.18';

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
     * PSR-11: 检查容器中是否存在指定标识符
     */
    public function has(string $id): bool
    {
        return self::hasService($id);
    }

    /**
     * PSR-11: 从容器获取服务实例
     *
     * @throws NotFoundExceptionInterface 服务未注册时抛出
     */
    public function get(string $id): mixed
    {
        $service = self::service($id);
        if ($service === null) {
            throw new class(sprintf('服务未注册: %s', $id)) extends \RuntimeException implements NotFoundExceptionInterface {};
        }

        return $service;
    }

    /**
     * 以 PSR-11 容器形式返回自身（供 kode/facade 等外部组件复用）
     */
    public static function container(): ContainerInterface
    {
        return new self();
    }

    /**
     * 将 Kode 注册为 kode/facade 的后端容器，并启用 context-safe 模式。
     *
     * 启用后，基于 {@see ServiceFacade} 的服务门面在 Swoole / Fiber 等协程环境中
     * 会按请求（Context 作用域）隔离解析结果，避免跨协程串号。
     *
     * 仅当 kode/facade 可用时生效，否则静默跳过（保持向后兼容）。
     */
    public static function enableFacades(bool $contextSafe = true): void
    {
        if (!class_exists(FacadeProxy::class)) {
            return;
        }

        FacadeProxy::setContainer(self::container());

        if ($contextSafe && class_exists(Facade::class) && method_exists(Facade::class, 'enableContextSafeMode')) {
            Facade::enableContextSafeMode();
        }
    }

    /**
     * 将服务标识符与门面类绑定（委托给 kode/facade）
     *
     * @param class-string $facade 门面类名
     */
    public static function bindFacade(string $facade, string $serviceId): void
    {
        if (!class_exists(FacadeProxy::class)) {
            return;
        }

        FacadeProxy::bind($facade, $serviceId);
    }

    /**
     * 创建 PSR-7 Response 的快捷响应
     *
     * @param int $status HTTP 状态码
     * @param string $body 响应体
     * @param array<string, string|string[]> $headers 响应头
     * @return \Kode\Http\Response
     */
    public static function response(int $status = 200, string $body = '', array $headers = []): \Kode\Http\Response
    {
        return new \Kode\Http\Response($status, $headers, $body);
    }

    /**
     * 创建 JSON 响应的快捷方法
     *
     * @param array $data JSON 数据
     * @param int $status HTTP 状态码
     * @return \Kode\Http\Response
     */
    public static function json(array $data, int $status = 200): \Kode\Http\Response
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

        if (class_exists(FacadeProxy::class)) {
            FacadeProxy::clearInstances();
            FacadeProxy::clearBindings();
        }
    }
}
