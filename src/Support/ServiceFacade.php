<?php

declare(strict_types=1);

namespace Kode\Http\Support;

use Kode\Facade\Facade;
use Kode\Http\Kode;
use Psr\Container\ContainerInterface;

/**
 * HTTP 服务门面基类
 *
 * 继承 kode/facade 的 {@see Facade}，并将后端容器指向 {@see Kode} 的 PSR-11 容器。
 * 配合 {@see Kode::enableFacades()} 启用 context-safe 模式后，门面解析结果会在
 * Swoole / Fiber 等协程环境中按请求（Context 作用域）隔离，天然避免跨协程串号。
 *
 * 用法：
 * ```php
 * Kode::register('cache', new Cache());
 * Kode::enableFacades();
 *
 * final class Cache extends ServiceFacade
 * {
 *     protected static function id(): string { return 'cache'; }
 * }
 *
 * Cache::get('key'); // 通过 Kode 容器解析
 * ```
 */
abstract class ServiceFacade extends Facade
{
    /**
     * 返回后端容器（即 Kode 的 PSR-11 容器）
     */
    protected static function container(): ContainerInterface
    {
        return Kode::container();
    }
}
