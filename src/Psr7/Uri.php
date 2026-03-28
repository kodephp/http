<?php

declare(strict_types=1);

namespace Kode\Http\Psr7;

use Psr\Http\Message\UriInterface;

/**
 * URI 实现类
 *
 * 实现了 PSR-7 UriInterface，规范了 URI 的各部分组件。
 * 支持解析和构建符合 RFC 3986 标准的 URI。
 *
 * URI 结构：
 * - scheme: 协议（如 http、https）
 * - userInfo: 用户信息（如 user:password）
 * - host: 主机名（如 example.com）
 * - port: 端口号
 * - path: 路径（如 /path/to/resource）
 * - query: 查询字符串（如 foo=bar&baz=qux）
 * - fragment: 片段标识符（如 section）
 *
 * @example
 * ```php
 * // 解析 URI
 * $uri = new Uri('https://user:pass@example.com:8080/path?query=value#fragment');
 *
 * // 构建 URI
 * $uri = (new Uri())
 *     ->withScheme('https')
 *     ->withHost('example.com')
 *     ->withPath('/api/users')
 *     ->withQuery('page=1');
 * ```
 */
class Uri implements UriInterface
{
    /** @var string 协议 */
    private string $scheme = '';

    /** @var string 用户信息 */
    private string $userInfo = '';

    /** @var string 主机名 */
    private string $host = '';

    /** @var int|null 端口号 */
    private ?int $port = null;

    /** @var string 路径 */
    private string $path = '';

    /** @var string 查询字符串 */
    private string $query = '';

    /** @var string 片段标识符 */
    private string $fragment = '';

    /**
     * 构造函数
     *
     * @param string $uri 要解析的 URI 字符串，空字符串创建空 URI
     */
    public function __construct(string $uri = '')
    {
        if ($uri !== '') {
            $this->parse($uri);
        }
    }

    /**
     * 解析 URI 字符串
     *
     * 使用 PHP 内置的 parse_url 函数解析 URI。
     *
     * @param string $uri 要解析的 URI 字符串
     */
    private function parse(string $uri): void
    {
        $parts = parse_url($uri);

        if ($parts === false) {
            return;
        }

        $this->scheme = $this->filterScheme($parts['scheme'] ?? '');
        $this->userInfo = $parts['user'] ?? '';
        if (isset($parts['pass'])) {
            $this->userInfo .= ':' . $parts['pass'];
        }
        $this->host = $parts['host'] ?? '';
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->path = $parts['path'] ?? '';
        $this->query = $parts['query'] ?? '';
        $this->fragment = $parts['fragment'] ?? '';
    }

    /**
     * 获取协议
     *
     * @return string 协议（小写），如 "http"、"https"
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * 获取授权信息
     *
     * 格式：userInfo@host:port
     *
     * @return string 授权信息字符串
     */
    public function getAuthority(): string
    {
        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }
        return $authority;
    }

    /**
     * 获取用户信息
     *
     * @return string 用户信息字符串，可能包含密码
     */
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    /**
     * 获取主机名
     *
     * @return string 主机名
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * 获取端口号
     *
     * @return int|null 端口号，无端口返回 null
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * 获取路径
     *
     * @return string URI 路径
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * 获取查询字符串
     *
     * @return string 查询字符串，不包含问号
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * 获取片段标识符
     *
     * @return string 片段标识符，不包含井号
     */
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * 返回具有指定协议的克隆
     *
     * @param string $scheme 新的协议
     * @return static 新的 URI 实例
     */
    public function withScheme(string $scheme): static
    {
        $clone = clone $this;
        $clone->scheme = $this->filterScheme($scheme);
        return $clone;
    }

    /**
     * 返回具有指定用户信息的克隆
     *
     * @param string $user 用户名
     * @param string|null $password 密码，可选
     * @return static 新的 URI 实例
     */
    public function withUserInfo(string $user, ?string $password = null): static
    {
        $clone = clone $this;
        $clone->userInfo = $password !== null ? $user . ':' . $password : $user;
        return $clone;
    }

    /**
     * 返回具有指定主机名的克隆
     *
     * @param string $host 新的主机名
     * @return static 新的 URI 实例
     */
    public function withHost(string $host): static
    {
        $clone = clone $this;
        $clone->host = $host;
        return $clone;
    }

    /**
     * 返回具有指定端口号的克隆
     *
     * @param int|null $port 新的端口号，null 表示移除端口
     * @return static 新的 URI 实例
     */
    public function withPort(?int $port): static
    {
        $clone = clone $this;
        $clone->port = $this->filterPort($port);
        return $clone;
    }

    /**
     * 返回具有指定路径的克隆
     *
     * @param string $path 新的路径
     * @return static 新的 URI 实例
     */
    public function withPath(string $path): static
    {
        $clone = clone $this;
        $clone->path = $this->filterPath($path);
        return $clone;
    }

    /**
     * 返回具有指定查询字符串的克隆
     *
     * @param string $query 新的查询字符串
     * @return static 新的 URI 实例
     */
    public function withQuery(string $query): static
    {
        $clone = clone $this;
        $clone->query = $this->filterQueryAndFragment($query);
        return $clone;
    }

    /**
     * 返回具有指定片段标识符的克隆
     *
     * @param string $fragment 新的片段标识符
     * @return static 新的 URI 实例
     */
    public function withFragment(string $fragment): static
    {
        $clone = clone $this;
        $clone->fragment = $this->filterQueryAndFragment($fragment);
        return $clone;
    }

    /**
     * 转换为字符串
     *
     * 返回完整的 URI 字符串表示。
     *
     * @return string 完整的 URI 字符串
     */
    public function __toString(): string
    {
        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();
        if ($authority !== '' || $this->scheme === 'file') {
            $uri .= '//' . $authority;
        }

        $uri .= $this->path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    /**
     * 过滤并标准化协议名
     *
     * @param string $scheme 原始协议名
     * @return string 标准化后的协议名（小写）
     */
    private function filterScheme(string $scheme): string
    {
        return strtolower($scheme);
    }

    /**
     * 过滤端口号
     *
     * 验证端口号有效性（1-65535）。
     *
     * @param int|null $port 原始端口号
     * @return int|null 过滤后的端口号，无效返回 null
     */
    private function filterPort(?int $port): ?int
    {
        if ($port === null) {
            return null;
        }

        if ($port < 1 || $port > 65535) {
            return null;
        }

        return $port;
    }

    /**
     * 过滤路径
     *
     * @param string $path 原始路径
     * @return string 过滤后的路径
     */
    private function filterPath(string $path): string
    {
        return $path;
    }

    /**
     * 过滤查询字符串和片段
     *
     * @param string $value 原始值
     * @return string 过滤后的值
     */
    private function filterQueryAndFragment(string $value): string
    {
        return $value;
    }
}