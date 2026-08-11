<?php

declare(strict_types=1);

namespace Kode\Http\Queue;

use Kode\Context\Context;
use Kode\Queue\QueueManager;
use Psr\Container\ContainerInterface;

/**
 * HTTP 队列门面
 *
 * 对 kode/queue 的轻量封装，面向 HTTP 请求场景做了两点增强：
 *
 * 1. **按请求收集**：{@see self::push()}/{@see self::later()}/{@see self::bulk()}
 *    默认只把任务写入「当前请求作用域」（基于 kode/context，协程安全），
 *    由 {@see self::flush()} 在处理器返回后统一派发，避免阻塞响应。
 * 2. **优雅降级**：未配置 QueueManager 时，懒加载一个内存驱动连接；
 *    若 kode/queue 完全不可用，则退化为本地缓冲区，调用方不会崩溃。
 *
 * 用法：
 * ```php
 * // 在 bootstrap 中
 * Queue::setManager(QueueManager::make([...]));
 *
 * // 在路由处理器中
 * Queue::push(SendMail::class, ['to' => $email]);
 * // 交给 QueueMiddleware 在响应后统一 flush
 * ```
 */
final class Queue
{
    /** @var QueueManager|null 全局队列管理器 */
    private static ?QueueManager $manager = null;

    /** @var array<int, array{job:mixed,data:array,queue:?string,delay:int}> 无 Context 时的回退缓冲 */
    private static array $fallback = [];

    /** @var string 请求作用域存储键 */
    private const string KEY = '__kode_http_queue';

    /**
     * 注入全局队列管理器（通常来自 PSR-11 容器）
     */
    public static function setManager(QueueManager $manager): void
    {
        self::$manager = $manager;
    }

    /**
     * 从 PSR-11 容器解析并注入管理器（键名可配置）
     */
    public static function setManagerFromContainer(ContainerInterface $container, string $id = QueueManager::class): void
    {
        if ($container->has($id)) {
            $manager = $container->get($id);
            if ($manager instanceof QueueManager) {
                self::$manager = $manager;
            }
        }
    }

    /**
     * 获取队列管理器；未注入时懒加载一个内存驱动连接（零外部依赖）。
     *
     * @throws \RuntimeException 当 kode/queue 完全不可用时
     */
    public static function manager(): QueueManager
    {
        if (self::$manager !== null) {
            return self::$manager;
        }

        if (!class_exists(QueueManager::class)) {
            throw new \RuntimeException('未安装 kode/queue，无法创建队列管理器（请 composer require kode/queue）');
        }

        self::$manager = QueueManager::make([
            'default' => 'memory',
            'connections' => ['memory' => ['driver' => 'memory']],
        ]);

        return self::$manager;
    }

    /**
     * 入队一个任务（收集到当前请求作用域，稍后由 flush 派发）
     *
     * @param string|object $job   任务类或任务实例
     * @param array         $data  任务数据
     * @param string|null   $queue 队列名
     */
    public static function push(string|object $job, array $data = [], ?string $queue = null): string
    {
        self::buffer(['job' => $job, 'data' => $data, 'queue' => $queue, 'delay' => 0]);

        return '';
    }

    /**
     * 延迟入队（收集到当前请求作用域）
     *
     * @param int           $delay 延迟秒数
     * @param string|object $job   任务类或任务实例
     * @param array         $data  任务数据
     * @param string|null   $queue 队列名
     */
    public static function later(int $delay, string|object $job, array $data = [], ?string $queue = null): string
    {
        self::buffer(['job' => $job, 'data' => $data, 'queue' => $queue, 'delay' => $delay]);

        return '';
    }

    /**
     * 批量入队（收集到当前请求作用域）
     *
     * @param iterable      $jobs  任务列表（元素为 string|object 或 [job,data] 形式）
     * @param array         $data  公共任务数据
     * @param string|null   $queue 队列名
     * @return string[] 派发 ID 列表
     */
    public static function bulk(iterable $jobs, array $data = [], ?string $queue = null): array
    {
        foreach ($jobs as $item) {
            if (is_array($item)) {
                [$job, $itemData] = [$item[0], $item[1] ?? []];
                self::buffer(['job' => $job, 'data' => array_merge($data, $itemData), 'queue' => $queue, 'delay' => 0]);
            } else {
                self::buffer(['job' => $item, 'data' => $data, 'queue' => $queue, 'delay' => 0]);
            }
        }

        return [];
    }

    /**
     * 当前请求作用域中已收集但尚未派发的任务
     *
     * @return array<int, array{job:mixed,data:array,queue:?string,delay:int}>
     */
    public static function pending(): array
    {
        if (class_exists(Context::class)) {
            return Context::get(self::KEY) ?? [];
        }

        return self::$fallback;
    }

    /**
     * 将收集到的任务统一派发到队列管理器
     *
     * @return int 实际派发的任务数
     */
    public static function flush(): int
    {
        $tasks = self::pending();
        if ($tasks === []) {
            return 0;
        }

        self::clear();

        $count = 0;
        $connection = self::manager()->default();

        foreach ($tasks as $task) {
            if ($task['delay'] > 0) {
                $connection->later($task['delay'], $task['job'], $task['data'], $task['queue']);
            } else {
                $connection->push($task['job'], $task['data'], $task['queue']);
            }
            $count++;
        }

        return $count;
    }

    /**
     * 清空当前请求作用域的收集缓冲
     */
    public static function clear(): void
    {
        if (class_exists(Context::class)) {
            Context::delete(self::KEY);

            return;
        }

        self::$fallback = [];
    }

    /**
     * 重置门面状态（主要用于测试）：清空缓冲并丢弃已缓存的管理器实例
     */
    public static function reset(): void
    {
        self::clear();
        self::$manager = null;
    }

    /**
     * 写入请求作用域缓冲（Context 优先，回退静态数组）
     *
     * @param array{job:mixed,data:array,queue:?string,delay:int} $task
     */
    private static function buffer(array $task): void
    {
        if (class_exists(Context::class)) {
            $current = Context::get(self::KEY) ?? [];
            $current[] = $task;
            Context::set(self::KEY, $current);

            return;
        }

        self::$fallback[] = $task;
    }
}
