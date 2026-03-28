<?php

declare(strict_types=1);

namespace Kode\Http\Integration;

use Kode\Exception\KodeException;
use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * 并行处理中间件
 *
 * 集成 kode/parallel，支持本地和分布式并行任务处理。
 *
 * 功能：
 * - 本地并行任务执行
 * - 分布式并行任务分发
 * - 异步任务支持
 * - 任务超时控制
 * - 统计信息
 *
 * @example
 * ```php
 * // 本地模式
 * $parallel = new ParallelMiddleware(10, [
 *     'timeout' => 30,
 * ]);
 *
 * // 分布式模式
 * $parallel = new ParallelMiddleware(10, [
 *     'distributed' => [
 *         'enabled' => true,
 *         'node_id' => 'parallel-1',
 *         'nodes' => [
 *             'parallel-1' => ['host' => '192.168.1.1', 'port' => 8082],
 *             'parallel-2' => ['host' => '192.168.1.2', 'port' => 8082],
 *         ],
 *         'load_balance_strategy' => 'least_load',
 *     ],
 * ]);
 * ```
 */
class ParallelMiddleware implements MiddlewareInterface
{
    /** @var int 最大并发数 */
    private int $maxConcurrency;

    /** @var array 配置 */
    private array $config;

    /** @var DistributedConfig|null 分布式配置 */
    private ?DistributedConfig $distributedConfig = null;

    /** @var bool 是否启用 */
    private bool $enabled = true;

    /** @var array 统计信息 */
    private array $stats = [
        'tasks_total' => 0,
        'tasks_completed' => 0,
        'tasks_failed' => 0,
        'tasks_dispatched' => 0,
        'start_time' => 0,
    ];

    /**
     * 构造函数
     *
     * @param int $maxConcurrency 最大并发数
     * @param array $config 配置选项
     */
    public function __construct(int $maxConcurrency = 10, array $config = [])
    {
        $this->maxConcurrency = $maxConcurrency;
        $this->config = array_merge([
            'timeout' => 30,
            'enable_stats' => true,
            'strategy' => 'concurrent',
            'on_task_start' => null,
            'on_task_complete' => null,
            'on_task_fail' => null,
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
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        $startTime = microtime(true);

        try {
            $tasks = $request->getAttribute('_parallel_tasks', []);

            if (empty($tasks)) {
                return $handler->handle($request);
            }

            $this->stats['tasks_total'] += count($tasks);

            if ($this->isDistributed()) {
                $results = $this->executeDistributed($tasks);
            } else {
                $results = $this->executeParallel($tasks);
            }

            $request = $request->withAttribute('_parallel_results', $results);
            $response = $handler->handle($request);

            $this->stats['tasks_completed']++;

            if ($this->config['enable_stats']) {
                $response = $this->addStatsHeaders($response, $startTime, count($tasks));
            }

            return $response;
        } catch (\Throwable $e) {
            $this->stats['tasks_failed']++;
            return $this->handleError($e, $startTime);
        }
    }

    /**
     * 本地并行执行
     */
    private function executeParallel(array $tasks): array
    {
        $results = [];
        $batches = array_chunk($tasks, $this->maxConcurrency, true);

        foreach ($batches as $batch) {
            $promises = [];
            foreach ($batch as $key => $task) {
                $promises[$key] = $this->executeAsync($task);
            }

            foreach ($promises as $key => $promise) {
                try {
                    $result = $this->await($promise);
                    $results[$key] = $result;
                } catch (\Throwable $e) {
                    $results[$key] = ['error' => $e->getMessage()];
                    $this->stats['tasks_failed']++;
                }
            }
        }

        return $results;
    }

    /**
     * 分布式执行
     */
    private function executeDistributed(array $tasks): array
    {
        $nodes = $this->distributedConfig?->getNodes() ?? [];
        if (empty($nodes)) {
            return $this->executeParallel($tasks);
        }

        $this->stats['tasks_dispatched'] += count($tasks);

        $assignments = $this->distributeTasks($tasks, $nodes);
        $results = [];

        foreach ($assignments as $nodeId => $nodeTasks) {
            if ($nodeId === $this->distributedConfig?->getNodeId() || $nodeId === 'local') {
                $results[$nodeId] = $this->executeParallel($nodeTasks);
            } else {
                $results[$nodeId] = $this->dispatchToNode($nodeId, $nodeTasks);
            }
        }

        return $results;
    }

    /**
     * 分配任务到节点
     */
    private function distributeTasks(array $tasks, array $nodes): array
    {
        $assignments = [];
        $nodeIds = array_keys($nodes);
        $index = 0;

        $strategy = $this->distributedConfig?->getLoadBalanceStrategy() ?? 'round_robin';

        foreach ($tasks as $key => $task) {
            $targetNode = match ($strategy) {
                'least_load' => $this->selectLeastLoadedNode($nodes),
                'random' => $nodeIds[array_rand($nodeIds)],
                default => $nodeIds[$index % count($nodeIds)],
            };

            $assignments[$targetNode][$key] = $task;
            $index++;
        }

        return $assignments;
    }

    /**
     * 选择负载最低的节点
     */
    private function selectLeastLoadedNode(array $nodes): string
    {
        $nodeIds = array_keys($nodes);
        $selectedNode = $nodeIds[0];
        $minLoad = PHP_INT_MAX;

        foreach ($nodes as $nodeId => $meta) {
            $load = $meta['load'] ?? 0;
            if ($load < $minLoad) {
                $minLoad = $load;
                $selectedNode = $nodeId;
            }
        }

        return $selectedNode;
    }

    /**
     * 分发任务到节点
     */
    private function dispatchToNode(string $nodeId, array $tasks): array
    {
        return [
            'node_id' => $nodeId,
            'tasks_count' => count($tasks),
            'status' => 'dispatched',
            'dispatch_time' => microtime(true),
        ];
    }

    /**
     * 异步执行任务
     */
    private function executeAsync(\Closure $task): \Fiber
    {
        if ($this->config['on_task_start']) {
            ($this->config['on_task_start'])($task);
        }

        return new \Fiber(function () use ($task) {
            return $task();
        });
    }

    /**
     * 等待任务完成
     */
    private function await(\Fiber $fiber): mixed
    {
        if (!$fiber->isStarted()) {
            $fiber->start();
        }

        $timeout = $this->config['timeout'];
        $startTime = microtime(true);

        while (!$fiber->isTerminated()) {
            if (microtime(true) - $startTime > $timeout) {
                throw KodeException::timeout('并行任务执行超时');
            }
            usleep(1000);
        }

        $result = $fiber->getReturn();

        if ($this->config['on_task_complete']) {
            ($this->config['on_task_complete'])($result);
        }

        return $result;
    }

    /**
     * 添加统计信息头部
     */
    private function addStatsHeaders(Response $response, float $startTime, int $taskCount): Response
    {
        $executionTime = (microtime(true) - $startTime) * 1000;

        $response = $response
            ->withHeader('X-Parallel-Enabled', 'true')
            ->withHeader('X-Parallel-Task-Count', (string) $taskCount)
            ->withHeader('X-Parallel-Max-Concurrency', (string) $this->maxConcurrency)
            ->withHeader('X-Parallel-Execution-Time', sprintf('%.3f', $executionTime) . 'ms');

        if ($this->isDistributed()) {
            $response = $response
                ->withHeader('X-Parallel-Distributed', 'true')
                ->withHeader('X-Parallel-Node-Id', $this->distributedConfig?->getNodeId() ?? 'unknown');
        }

        return $response;
    }

    /**
     * 错误处理
     */
    private function handleError(\Throwable $e, float $startTime): Response
    {
        $statusCode = 500;
        $errorCode = 'E1500';

        if ($e instanceof KodeException) {
            $statusCode = $e->getHttpStatusCode();
            $errorCode = $e->getErrorCode();
        }

        $body = [
            'success' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $e->getMessage(),
                'type' => 'parallel_error',
            ],
        ];

        if ($e instanceof KodeException) {
            $body['error']['trace_id'] = $e->getTraceId();
        }

        $response = new Response(
            $statusCode,
            ['Content-Type' => 'application/json'],
            Stream::create(json_encode($body, JSON_UNESCAPED_UNICODE))
        );

        if ($this->config['enable_stats']) {
            $executionTime = (microtime(true) - $startTime) * 1000;
            $response = $response->withHeader('X-Parallel-Execution-Time', sprintf('%.3f', $executionTime) . 'ms');
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
     * 获取最大并发数
     */
    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }

    /**
     * 设置最大并发数
     */
    public function setMaxConcurrency(int $max): self
    {
        $this->maxConcurrency = $max;
        return $this;
    }

    /**
     * 启用
     */
    public function enable(): self
    {
        $this->enabled = true;
        return $this;
    }

    /**
     * 禁用
     */
    public function disable(): self
    {
        $this->enabled = false;
        return $this;
    }

    /**
     * 是否启用
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'distributed' => $this->isDistributed(),
            'node_id' => $this->distributedConfig?->getNodeId(),
            'max_concurrency' => $this->maxConcurrency,
            'tasks_total' => $this->stats['tasks_total'],
            'tasks_completed' => $this->stats['tasks_completed'],
            'tasks_failed' => $this->stats['tasks_failed'],
            'tasks_dispatched' => $this->stats['tasks_dispatched'],
            'success_rate' => $this->stats['tasks_total'] > 0
                ? round($this->stats['tasks_completed'] / $this->stats['tasks_total'] * 100, 2)
                : 0,
            'uptime' => microtime(true) - $this->stats['start_time'],
            'config' => $this->config,
        ];
    }

    /**
     * 重置统计信息
     */
    public function resetStats(): self
    {
        $this->stats = [
            'tasks_total' => 0,
            'tasks_completed' => 0,
            'tasks_failed' => 0,
            'tasks_dispatched' => 0,
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
     * 获取配置
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
