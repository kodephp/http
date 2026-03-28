<?php

declare(strict_types=1);

namespace Kode\Http\Integration;

use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\NullLogger;

/**
 * 进程工作单元中间件
 *
 * 集成 kode/process，支持本地和分布式 Worker 调用。
 *
 * 功能：
 * - 本地 Worker 池管理
 * - 分布式 Worker 节点调用
 * - Worker 统计信息
 * - 异步响应支持
 *
 * @example
 * ```php
 * // 本地模式
 * $worker = new ProcessWorkerMiddleware(0, true, [
 *     'pool_size' => 4,
 *     'pool_strategy' => 'round_robin',
 * ]);
 *
 * // 分布式模式
 * $worker = new ProcessWorkerMiddleware(0, true, [
 *     'distributed' => [
 *         'enabled' => true,
 *         'node_id' => 'worker-1',
 *         'nodes' => [
 *             'worker-1' => ['host' => '192.168.1.1', 'port' => 8080],
 *             'worker-2' => ['host' => '192.168.1.2', 'port' => 8080],
 *         ],
 *     ],
 * ]);
 * ```
 */
class ProcessWorkerMiddleware implements MiddlewareInterface
{
    /** @var int Worker ID */
    private int $workerId;

    /** @var bool 是否启用异步 */
    private bool $enableAsync;

    /** @var array 配置 */
    private array $config;

    /** @var DistributedConfig|null 分布式配置 */
    private ?DistributedConfig $distributedConfig = null;

    /** @var mixed Worker 池实例 */
    private mixed $workerPool = null;

    /** @var array 统计信息 */
    private array $stats = [
        'requests_total' => 0,
        'requests_handled' => 0,
        'requests_failed' => 0,
        'start_time' => 0,
    ];

    /**
     * 构造函数
     *
     * @param int $workerId Worker ID
     * @param bool $enableAsync 是否启用异步响应
     * @param array $config 配置选项
     */
    public function __construct(int $workerId = 0, bool $enableAsync = false, array $config = [])
    {
        $this->workerId = $workerId;
        $this->enableAsync = $enableAsync;
        $this->config = array_merge([
            'pool_size' => 4,
            'pool_strategy' => 'round_robin',
            'enable_stats' => true,
            'enable_health_check' => true,
            'health_check_interval' => 60,
        ], $config);

        $this->stats['start_time'] = microtime(true);

        if (isset($config['distributed']) && is_array($config['distributed'])) {
            $this->distributedConfig = DistributedConfig::fromArray($config['distributed']);
        }
    }

    /**
     * 处理请求
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): Response
    {
        $startTime = microtime(true);
        $this->stats['requests_total']++;

        try {
            $request = $request
                ->withAttribute('_worker_id', $this->workerId)
                ->withAttribute('_worker_start_time', $startTime)
                ->withAttribute('_distributed_config', $this->distributedConfig);

            $response = $handler->handle($request);

            if ($this->config['enable_stats']) {
                $response = $this->addStatsHeaders($response, $startTime);
            }

            $this->stats['requests_handled']++;

            if ($this->enableAsync && function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            return $response;
        } catch (\Throwable $e) {
            $this->stats['requests_failed']++;
            throw $e;
        }
    }

    /**
     * 添加统计信息头部
     */
    private function addStatsHeaders(Response $response, float $startTime): Response
    {
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;

        $response = $response
            ->withHeader('X-Worker-Id', (string) $this->workerId)
            ->withHeader('X-Worker-Execution-Time', sprintf('%.3f', $executionTime) . 'ms')
            ->withHeader('X-Worker-Pool-Size', (string) $this->config['pool_size']);

        if ($this->distributedConfig !== null && $this->distributedConfig->isEnabled()) {
            $response = $response
                ->withHeader('X-Worker-Distributed', 'true')
                ->withHeader('X-Worker-Node-Id', $this->distributedConfig->getNodeId());
        }

        return $response;
    }

    /**
     * 设置分布式配置
     */
    public function setDistributedConfig(DistributedConfig $config): self
    {
        $this->distributedConfig = $config;
        return $this;
    }

    /**
     * 获取分布式配置
     */
    public function getDistributedConfig(): ?DistributedConfig
    {
        return $this->distributedConfig;
    }

    /**
     * 是否启用分布式模式
     */
    public function isDistributed(): bool
    {
        return $this->distributedConfig !== null && $this->distributedConfig->isEnabled();
    }

    /**
     * 获取 Worker ID
     */
    public function getWorkerId(): int
    {
        return $this->workerId;
    }

    /**
     * 是否启用异步
     */
    public function isAsyncEnabled(): bool
    {
        return $this->enableAsync;
    }

    /**
     * 获取配置
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        $uptime = microtime(true) - $this->stats['start_time'];

        return [
            'worker_id' => $this->workerId,
            'distributed' => $this->isDistributed(),
            'node_id' => $this->distributedConfig?->getNodeId(),
            'requests_total' => $this->stats['requests_total'],
            'requests_handled' => $this->stats['requests_handled'],
            'requests_failed' => $this->stats['requests_failed'],
            'success_rate' => $this->stats['requests_total'] > 0
                ? round($this->stats['requests_handled'] / $this->stats['requests_total'] * 100, 2)
                : 0,
            'uptime' => $uptime,
            'pool_size' => $this->config['pool_size'],
        ];
    }

    /**
     * 重置统计信息
     */
    public function resetStats(): self
    {
        $this->stats = [
            'requests_total' => 0,
            'requests_handled' => 0,
            'requests_failed' => 0,
            'start_time' => microtime(true),
        ];
        return $this;
    }

    /**
     * 链式配置
     */
    public function withConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * 获取可用节点列表
     */
    public function getAvailableNodes(): array
    {
        if (!$this->isDistributed()) {
            return [['node_id' => 'local', 'host' => '127.0.0.1', 'port' => 0]];
        }

        return $this->distributedConfig->getNodes();
    }

    /**
     * 健康检查
     */
    public function healthCheck(): array
    {
        return [
            'healthy' => true,
            'worker_id' => $this->workerId,
            'distributed' => $this->isDistributed(),
            'uptime' => microtime(true) - $this->stats['start_time'],
        ];
    }
}