<?php

declare(strict_types=1);

namespace Kode\Http\Integration;

/**
 * 分布式配置选项
 *
 * 定义了跨机器分布式调用的通用配置选项。
 */
class DistributedConfig
{
    /** @var bool 是否启用分布式模式 */
    private bool $enabled = false;

    /** @var string 节点 ID */
    private string $nodeId;

    /** @var array 注册中心配置 */
    private array $registry = [];

    /** @var array 节点列表 */
    private array $nodes = [];

    /** @var string 负载均衡策略 */
    private string $loadBalanceStrategy = 'round_robin';

    /** @var float 心跳间隔（秒） */
    private float $heartbeatInterval = 5.0;

    /** @var float 节点超时（秒） */
    private float $nodeTimeout = 30.0;

    /** @var int 最大重试次数 */
    private int $maxRetries = 3;

    /** @var float 重试延迟（秒） */
    private float $retryDelay = 1.0;

    /** @var float 调用超时（秒） */
    private float $callTimeout = 30.0;

    /** @var bool 是否为 Master 节点 */
    private bool $isMaster = false;

    /** @var array 元数据标签 */
    private array $tags = [];

    public function __construct(string $nodeId = '')
    {
        $this->nodeId = $nodeId ?: uniqid('node_', true);
    }

    /**
     * 从配置数组创建
     *
     * @param array $config 配置数组
     * @return self
     */
    public static function fromArray(array $config): self
    {
        $instance = new self($config['node_id'] ?? '');

        if (isset($config['enabled'])) {
            $instance->enabled = (bool) $config['enabled'];
        }
        if (isset($config['registry'])) {
            $instance->registry = $config['registry'];
        }
        if (isset($config['nodes'])) {
            $instance->nodes = $config['nodes'];
        }
        if (isset($config['load_balance_strategy'])) {
            $instance->loadBalanceStrategy = $config['load_balance_strategy'];
        }
        if (isset($config['heartbeat_interval'])) {
            $instance->heartbeatInterval = (float) $config['heartbeat_interval'];
        }
        if (isset($config['node_timeout'])) {
            $instance->nodeTimeout = (float) $config['node_timeout'];
        }
        if (isset($config['max_retries'])) {
            $instance->maxRetries = (int) $config['max_retries'];
        }
        if (isset($config['retry_delay'])) {
            $instance->retryDelay = (float) $config['retry_delay'];
        }
        if (isset($config['call_timeout'])) {
            $instance->callTimeout = (float) $config['call_timeout'];
        }
        if (isset($config['is_master'])) {
            $instance->isMaster = (bool) $config['is_master'];
        }
        if (isset($config['tags'])) {
            $instance->tags = $config['tags'];
        }

        return $instance;
    }

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'node_id' => $this->nodeId,
            'registry' => $this->registry,
            'nodes' => $this->nodes,
            'load_balance_strategy' => $this->loadBalanceStrategy,
            'heartbeat_interval' => $this->heartbeatInterval,
            'node_timeout' => $this->nodeTimeout,
            'max_retries' => $this->maxRetries,
            'retry_delay' => $this->retryDelay,
            'call_timeout' => $this->callTimeout,
            'is_master' => $this->isMaster,
            'tags' => $this->tags,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    public function setNodeId(string $nodeId): self
    {
        $this->nodeId = $nodeId;
        return $this;
    }

    public function getRegistry(): array
    {
        return $this->registry;
    }

    public function setRegistry(array $registry): self
    {
        $this->registry = $registry;
        return $this;
    }

    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function setNodes(array $nodes): self
    {
        $this->nodes = $nodes;
        return $this;
    }

    public function addNode(string $nodeId, string $host, int $port, array $meta = []): self
    {
        $this->nodes[$nodeId] = array_merge([
            'host' => $host,
            'port' => $port,
            'weight' => 1,
            'healthy' => true,
        ], $meta);
        return $this;
    }

    public function removeNode(string $nodeId): self
    {
        unset($this->nodes[$nodeId]);
        return $this;
    }

    public function getLoadBalanceStrategy(): string
    {
        return $this->loadBalanceStrategy;
    }

    public function setLoadBalanceStrategy(string $strategy): self
    {
        $this->loadBalanceStrategy = $strategy;
        return $this;
    }

    public function getHeartbeatInterval(): float
    {
        return $this->heartbeatInterval;
    }

    public function setHeartbeatInterval(float $interval): self
    {
        $this->heartbeatInterval = $interval;
        return $this;
    }

    public function getNodeTimeout(): float
    {
        return $this->nodeTimeout;
    }

    public function setNodeTimeout(float $timeout): self
    {
        $this->nodeTimeout = $timeout;
        return $this;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function setMaxRetries(int $retries): self
    {
        $this->maxRetries = $retries;
        return $this;
    }

    public function getRetryDelay(): float
    {
        return $this->retryDelay;
    }

    public function setRetryDelay(float $delay): self
    {
        $this->retryDelay = $delay;
        return $this;
    }

    public function getCallTimeout(): float
    {
        return $this->callTimeout;
    }

    public function setCallTimeout(float $timeout): self
    {
        $this->callTimeout = $timeout;
        return $this;
    }

    public function isMaster(): bool
    {
        return $this->isMaster;
    }

    public function setMaster(bool $isMaster): self
    {
        $this->isMaster = $isMaster;
        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    public function addTag(string $tag): self
    {
        $this->tags[] = $tag;
        return $this;
    }
}