<?php

declare(strict_types=1);

namespace Kode\Http\Integration;

use Kode\Exception\BaseException;
use Kode\Exception\RuntimeException;
use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Fiber 协程中间件
 *
 * 集成 kode/fibers，支持本地和分布式 Fiber 协程调用。
 *
 * 功能：
 * - 本地 Fiber 池管理
 * - 分布式 Fiber 节点调用
 * - 并发任务处理
 * - 重试机制
 * - 统计信息
 *
 * @example
 * ```php
 * // 本地模式
 * $fiber = new FiberCoroutineMiddleware(10, 2048, [
 *     'timeout' => 30,
 * ]);
 *
 * // 分布式模式
 * $fiber = new FiberCoroutineMiddleware(10, 2048, [
 *     'distributed' => [
 *         'enabled' => true,
 *         'node_id' => 'fiber-1',
 *         'nodes' => [
 *             'fiber-1' => ['host' => '192.168.1.1', 'port' => 8081],
 *             'fiber-2' => ['host' => '192.168.1.2', 'port' => 8081],
 *         ],
 *     ],
 * ]);
 * ```
 */
class FiberCoroutineMiddleware implements MiddlewareInterface
{
    /** @var int Fiber 池大小 */
    private int $poolSize;

    /** @var int 栈大小 */
    private int $stackSize;

    /** @var array 配置 */
    private array $config;

    /** @var DistributedConfig|null 分布式配置 */
    private ?DistributedConfig $distributedConfig = null;

    /** @var bool 是否启用 */
    private bool $enabled = true;

    /** @var array 统计信息 */
    private array $stats = [
        'fibers_created' => 0,
        'fibers_completed' => 0,
        'fibers_failed' => 0,
        'tasks_dispatched' => 0,
        'start_time' => 0,
    ];

    /**
     * 构造函数
     *
     * @param int $poolSize Fiber 池大小
     * @param int $stackSize 栈大小
     * @param array $config 配置选项
     */
    public function __construct(int $poolSize = 10, int $stackSize = 2048, array $config = [])
    {
        $this->poolSize = $poolSize;
        $this->stackSize = $stackSize;
        $this->config = array_merge([
            'max_retries' => 3,
            'retry_delay' => 1,
            'timeout' => 30,
            'enable_stats' => true,
            'context' => [],
            'name' => 'http_fiber_pool',
            'gc_interval' => 100,
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
            if (!extension_loaded('fibers')) {
                return $handler->handle($request);
            }

            $tasks = $request->getAttribute('_fiber_tasks', []);

            if (!empty($tasks) && $this->isDistributed()) {
                return $this->handleDistributed($request, $handler, $tasks, $startTime);
            }

            return $this->handleLocal($request, $handler, $tasks, $startTime);
        } catch (\Throwable $e) {
            $this->stats['fibers_failed']++;
            return $this->handleError($e, $startTime);
        }
    }

    /**
     * 本地处理
     */
    private function handleLocal(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        array $tasks,
        float $startTime
    ): Response {
        if (!empty($tasks)) {
            $this->stats['tasks_dispatched'] += count($tasks);
            $results = $this->executeConcurrent($tasks);
            $request = $request->withAttribute('_fiber_results', $results);
        }

        $response = $handler->handle($request);

        $this->stats['fibers_completed']++;

        if ($this->config['enable_stats']) {
            $response = $this->addStatsHeaders($response, $startTime);
        }

        return $response;
    }

    /**
     * 分布式处理
     */
    private function handleDistributed(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        array $tasks,
        float $startTime
    ): Response {
        $this->stats['tasks_dispatched'] += count($tasks);

        $nodeId = $this->distributedConfig?->getNodeId() ?? 'unknown';
        $targetNodes = $this->selectTargetNodes($tasks);

        $distributedResults = [];
        foreach ($targetNodes as $targetNodeId => $nodeTasks) {
            $distributedResults[$targetNodeId] = $this->dispatchToNode($targetNodeId, $nodeTasks);
        }

        $request = $request->withAttribute('_fiber_results', $distributedResults);
        $response = $handler->handle($request);

        if ($this->config['enable_stats']) {
            $response = $response
                ->withHeader('X-Fiber-Distributed', 'true')
                ->withHeader('X-Fiber-Node-Id', $nodeId)
                ->withHeader('X-Fiber-Target-Nodes', (string) count($targetNodes));
        }

        return $response;
    }

    /**
     * 选择目标节点
     */
    private function selectTargetNodes(array $tasks): array
    {
        $nodes = $this->distributedConfig?->getNodes() ?? [];
        if (empty($nodes)) {
            return ['local' => $tasks];
        }

        $assignments = [];
        $index = 0;
        $nodeIds = array_keys($nodes);

        foreach ($tasks as $key => $task) {
            $targetNode = $nodeIds[$index % count($nodeIds)];
            $assignments[$targetNode][$key] = $task;
            $index++;
        }

        return $assignments;
    }

    /**
     * 分发任务到节点
     */
    private function dispatchToNode(string $nodeId, array $tasks): array
    {
        if ($nodeId === 'local' || $nodeId === $this->distributedConfig?->getNodeId()) {
            return $this->executeConcurrent($tasks);
        }

        return [
            'node_id' => $nodeId,
            'tasks_count' => count($tasks),
            'status' => 'dispatched',
        ];
    }

    /**
     * 并发执行任务
     */
    private function executeConcurrent(array $tasks): array
    {
        $results = [];
        $fibers = [];
        $fiberStartTimes = [];

        foreach ($tasks as $key => $task) {
            $fibers[$key] = new \Fiber(function () use ($task) {
                if ($task instanceof \Closure) {
                    return $task();
                }
                if (is_callable($task)) {
                    return call_user_func($task);
                }
                return null;
            });
        }

        $maxRetries = $this->config['max_retries'];
        $retryDelay = $this->config['retry_delay'];
        $timeout = $this->config['timeout'];

        foreach ($fibers as $key => $fiber) {
            $attempt = 0;
            while ($attempt < $maxRetries) {
                try {
                    $fiberStartTimes[$key] = microtime(true);
                    $fiber->start();
                    $this->stats['fibers_created']++;

                    while (!$fiber->isTerminated()) {
                        if ($fiber->isSuspended()) {
                            $fiber->resume();
                        } else {
                            usleep(1000);
                        }

                        $elapsed = microtime(true) - $fiberStartTimes[$key];
                        if ($elapsed > $timeout) {
                            throw new RuntimeException('Fiber 执行超时', 0, null, 'FIBER_TIMEOUT');
                        }
                    }

                    $results[$key] = $fiber->getReturn();
                    break;
                } catch (\Throwable $e) {
                    $attempt++;
                    if ($attempt >= $maxRetries) {
                        $results[$key] = ['error' => $e->getMessage(), 'attempts' => $attempt];
                        $this->stats['fibers_failed']++;
                    } else {
                        usleep((int) ($retryDelay * 1000000));
                    }
                }
            }
        }

        return $results;
    }

    /**
     * 错误处理
     */
    private function handleError(\Throwable $e, float $startTime): Response
    {
        $statusCode = 500;
        $errorCode = 'E1500';

        if ($e instanceof BaseException) {
            $statusCode = $e instanceof \Kode\Exception\HttpException ? $e->getHttpStatusCode() : 500;
            $errorCode = $e->getErrorCode();
        }

        $body = [
            'success' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $e->getMessage(),
                'type' => 'fiber_error',
            ],
        ];

        if ($e instanceof BaseException) {
            $body['error']['trace_id'] = $e->getTraceId();
        }

        $response = new Response(
            $statusCode,
            ['Content-Type' => 'application/json'],
            Stream::create(json_encode($body, JSON_UNESCAPED_UNICODE))
        );

        if ($this->config['enable_stats']) {
            $response = $this->addStatsHeaders($response, $startTime);
        }

        return $response;
    }

    /**
     * 添加统计信息头部
     */
    private function addStatsHeaders(Response $response, float $startTime): Response
    {
        $executionTime = (microtime(true) - $startTime) * 1000;

        return $response
            ->withHeader('X-Fiber-Enabled', 'true')
            ->withHeader('X-Fiber-Execution-Time', sprintf('%.3f', $executionTime) . 'ms')
            ->withHeader('X-Fiber-Pool-Size', (string) $this->poolSize)
            ->withHeader('X-Fiber-Active', (string) count($this->stats));
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
     * 获取 Fiber 池大小
     */
    public function getPoolSize(): int
    {
        return $this->poolSize;
    }

    /**
     * 获取栈大小
     */
    public function getStackSize(): int
    {
        return $this->stackSize;
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
            'pool_size' => $this->poolSize,
            'stack_size' => $this->stackSize,
            'fibers_created' => $this->stats['fibers_created'],
            'fibers_completed' => $this->stats['fibers_completed'],
            'fibers_failed' => $this->stats['fibers_failed'],
            'tasks_dispatched' => $this->stats['tasks_dispatched'],
            'uptime' => microtime(true) - $this->stats['start_time'],
        ];
    }

    /**
     * 重置统计信息
     */
    public function resetStats(): self
    {
        $this->stats = [
            'fibers_created' => 0,
            'fibers_completed' => 0,
            'fibers_failed' => 0,
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
