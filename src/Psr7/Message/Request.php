<?php

declare(strict_types=1);

namespace Kode\Http\Psr7\Message;

use Kode\Http\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * HTTP 请求消息类
 *
 * 实现了 PSR-7 RequestInterface，规范了 HTTP 请求的所有属性，
 * 包括请求方法、URI、头部、协议版本和消息体。
 *
 * @example
 * ```php
 * $request = new Request('GET', 'https://example.com/api', [
 *     'Content-Type' => 'application/json',
 *     'Authorization' => 'Bearer token',
 * ]);
 * ```
 */
class Request implements RequestInterface
{
    use \Kode\Http\Psr7\Trait\RequestTrait;

    /** @var string HTTP 请求方法 */
    private string $method;

    /** @var UriInterface 请求目标 URI */
    private UriInterface $uri;

    /** @var string|null 请求目标字符串，null 表示使用 URI 自动生成 */
    private ?string $requestTarget = null;

    /** @var string HTTP 协议版本 */
    private string $protocolVersion = '1.1';

    /**
     * 构造函数
     *
     * @param string $method HTTP 方法，如 GET、POST、PUT 等
     * @param UriInterface|string $uri 请求 URI，可以是 UriInterface 对象或字符串
     * @param array $headers 请求头部关联数组
     * @param StreamInterface|null $body 请求消息体
     * @param string $protocolVersion HTTP 协议版本，默认为 1.1
     */
    public function __construct(
        string $method,
        UriInterface|string $uri,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1'
    ) {
        $this->method = $method;
        $this->uri = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->protocolVersion = $protocolVersion;

        $this->initializeHeaders($headers);
        $this->body = $body;

        $host = $this->uri->getHost();
        if ($host !== '' && !$this->hasHeader('Host')) {
            $this->updateHostHeader($host, $this->uri->getPort());
        }
    }

    /**
     * 获取 HTTP 请求方法
     *
     * @return string 请求方法，如 GET、POST、PUT、DELETE 等
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * 获取请求 URI 对象
     *
     * @return UriInterface 请求 URI 对象
     */
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * 获取请求目标
     *
     * 请求目标是请求 URI 的路径和查询字符串部分。
     * 如果未设置自定义请求目标，则从 URI 自动生成。
     *
     * @return string 请求目标字符串，格式为 "/path?query=value"
     */
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }

        $query = $this->uri->getQuery();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        return $target;
    }

    /**
     * 返回具有指定请求目标的克隆
     *
     * @param string|null $requestTarget 新的请求目标
     * @return static 新的请求实例
     */
    public function withRequestTarget(?string $requestTarget): static
    {
        $clone = clone $this;
        $clone->requestTarget = $requestTarget;
        return $clone;
    }

    /**
     * 返回具有指定方法的克隆
     *
     * @param string $method 新的 HTTP 方法
     * @return static 新的请求实例
     */
    public function withMethod(string $method): static
    {
        $clone = clone $this;
        $clone->method = $method;
        return $clone;
    }

    /**
     * 返回具有指定 URI 的克隆
     *
     * @param UriInterface $uri 新的 URI 对象
     * @param bool $preserveHost 是否保留原始 Host 头
     * @return static 新的请求实例
     */
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $clone = clone $this;
        $clone->uri = $uri;

        if ($preserveHost && $this->hasHeader('Host')) {
            return $clone;
        }

        $host = $uri->getHost();
        if ($host !== '') {
            $clone->updateHostHeader($host, $uri->getPort());
        }

        return $clone;
    }

    /**
     * 获取 HTTP 协议版本
     *
     * @return string 协议版本，如 "1.1"、"2.0"
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * 返回具有指定协议版本的克隆
     *
     * @param string $version 新的协议版本
     * @return static 新的请求实例
     */
    public function withProtocolVersion(string $version): static
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    /**
     * 返回具有指定消息体的克隆
     *
     * @param StreamInterface $body 新的消息体
     * @return static 新的请求实例
     */
    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }
}