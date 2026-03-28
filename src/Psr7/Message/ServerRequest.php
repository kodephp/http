<?php

declare(strict_types=1);

namespace Kode\Http\Psr7\Message;

use Kode\Http\Psr7\Uri;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * 服务端请求类
 *
 * 继承了 Request 类，并添加了与服务端相关的属性，
 * 如服务器参数、Cookie、查询参数、上传文件、解析后的请求体等。
 *
 * 实现了 PSR-7 ServerRequestInterface。
 *
 * @example
 * ```php
 * $request = new ServerRequest('POST', '/api/users', $_SERVER, [
 *     'Content-Type' => 'application/json',
 * ], $body);
 *
 * // 获取查询参数
 * $page = $request->getQueryParams()['page'] ?? 1;
 *
 * // 获取解析后的 JSON body
 * $data = $request->getParsedBody();
 *
 * // 设置请求属性（用于在中间件间传递数据）
 * $request = $request->withAttribute('user_id', 123);
 * ```
 */
class ServerRequest implements ServerRequestInterface
{
    use \Kode\Http\Psr7\Trait\RequestTrait;

    /** @var string HTTP 请求方法 */
    private string $method;

    /** @var UriInterface 请求目标 URI */
    private UriInterface $uri;

    /** @var string|null 请求目标字符串 */
    private ?string $requestTarget = null;

    /** @var string HTTP 协议版本 */
    private string $protocolVersion = '1.1';

    /** @var array 服务器参数（$_SERVER） */
    private array $serverParams;

    /** @var array Cookie 参数（$_COOKIE） */
    private array $cookieParams;

    /** @var array 查询参数（$_GET） */
    private array $queryParams;

    /** @var array 上传文件信息（$_FILES） */
    private array $uploadedFiles;

    /** @var mixed 解析后的请求体（如 JSON、POST 数据） */
    private mixed $parsedBody;

    /** @var array 请求属性（用于中间件间传递数据） */
    private array $attributes;

    /**
     * 构造函数
     *
     * @param string $method HTTP 方法
     * @param UriInterface|string $uri 请求 URI
     * @param array $serverParams 服务器参数（通常为 $_SERVER）
     * @param array $headers 请求头部
     * @param StreamInterface|null $body 请求消息体
     * @param string $protocolVersion HTTP 协议版本
     */
    public function __construct(
        string $method,
        UriInterface|string $uri,
        array $serverParams = [],
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1'
    ) {
        $this->method = $method;
        $this->uri = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->serverParams = $serverParams;
        $this->protocolVersion = $protocolVersion;
        $this->queryParams = [];
        $this->cookieParams = [];
        $this->uploadedFiles = [];
        $this->parsedBody = null;
        $this->attributes = [];

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
     * @return string 请求方法
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * 获取请求 URI
     *
     * @return UriInterface URI 对象
     */
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * 获取请求目标
     *
     * @return string 请求目标
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
     * @param UriInterface $uri 新的 URI
     * @param bool $preserveHost 是否保留 Host 头
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
     * @return string 协议版本
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

    /**
     * 获取服务器参数
     *
     * 通常是 $_SERVER 的内容，包含请求方法、URI、服务器信息等。
     *
     * @return array 服务器参数关联数组
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * 获取 Cookie 参数
     *
     * @return array Cookie 参数关联数组
     */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * 返回具有指定 Cookie 参数的克隆
     *
     * @param array $cookies 新的 Cookie 参数
     * @return static 新的请求实例
     */
    public function withCookieParams(array $cookies): static
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;
        return $clone;
    }

    /**
     * 获取查询参数
     *
     * 来自 URL 中的查询字符串（?key=value）。
     *
     * @return array 查询参数关联数组
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * 返回具有指定查询参数的克隆
     *
     * @param array $query 新的查询参数
     * @return static 新的请求实例
     */
    public function withQueryParams(array $query): static
    {
        $clone = clone $this;
        $clone->queryParams = $query;
        return $clone;
    }

    /**
     * 获取上传文件信息
     *
     * @return array 上传文件信息数组
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * 返回具有指定上传文件信息的克隆
     *
     * @param array $uploadedFiles 新的上传文件信息
     * @return static 新的请求实例
     */
    public function withUploadedFiles(array $uploadedFiles): static
    {
        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;
        return $clone;
    }

    /**
     * 获取解析后的请求体
     *
     * 通常是解析后的 JSON、XML 或表单数据。
     *
     * @return mixed 解析后的请求体
     */
    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }

    /**
     * 返回具有指定解析后请求体的克隆
     *
     * @param mixed $data 新的解析后请求体
     * @return static 新的请求实例
     */
    public function withParsedBody(mixed $data): static
    {
        $clone = clone $this;
        $clone->parsedBody = $data;
        return $clone;
    }

    /**
     * 获取所有请求属性
     *
     * 请求属性用于在中间件和处理器之间传递数据。
     *
     * @return array 请求属性关联数组
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * 获取指定请求属性
     *
     * @param string $name 属性名
     * @param mixed $default 默认值（属性不存在时返回）
     * @return mixed 属性值
     */
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * 返回具有指定属性的克隆
     *
     * @param string $name 属性名
     * @param mixed $value 属性值
     * @return static 新的请求实例
     */
    public function withAttribute(string $name, mixed $value): static
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    /**
     * 返回移除指定属性的克隆
     *
     * @param string $name 要移除的属性名
     * @return static 新的请求实例
     */
    public function withoutAttribute(string $name): static
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);
        return $clone;
    }
}