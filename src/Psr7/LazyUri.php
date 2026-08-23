<?php

declare(strict_types=1);

namespace Kode\Http\Psr7;

use Psr\Http\Message\UriInterface;

/**
 * 懒构造 URI（Swoole / Workerman 热路径）
 *
 * 与 {@see Uri} 行为完全一致（PSR-7 契约、`with*` 不可变语义、`__toString` RFC 3986 拼装），
 * 但**不调用 parse_url**、构造期**不做 clone**：直接接收已分解的 path / query
 * （及可选的 scheme / host / port / userInfo / fragment），所有 getter 直接返回原始标量。
 *
 * 适用场景：Swoole / Workerman 适配器拿到的就是「request_uri（path）+ query_string」两件
 * 原始分量，旧实现却要先 `new Uri($path)` 做一次 parse_url、再 `withQuery($query)` 做一次
 * clone；`LazyUri` 用一次 `new` 直接持有分量，消除 parse_url 与构造期 clone 两项开销。
 * 对只读 path / query 的路由热路径，构造成本趋近于零标量赋值。
 *
 * 注意：本类只承接「path + query」热路径，不解析完整的 `scheme://host` 形式 URI 字符串；
 * 需要由完整字符串构建时请仍使用 {@see Uri}（FPM 生产路径即如此）。
 */
class LazyUri implements UriInterface
{
    private string $scheme;
    private string $userInfo;
    private string $host;
    private ?int $port;
    private string $path;
    private string $query;
    private string $fragment;

    /**
     * @param string $path 路径分量（如 /api/users）
     * @param string $query 查询字符串分量（不含 ?，如 page=1&limit=20）
     * @param string $scheme 协议（如 http / https），会被小写化
     * @param string $host 主机名
     * @param int|null $port 端口号（1-65535，非法归 null）
     * @param string $userInfo 用户信息（user 或 user:pass）
     * @param string $fragment 片段标识符
     */
    public function __construct(
        string $path = '',
        string $query = '',
        string $scheme = '',
        string $host = '',
        ?int $port = null,
        string $userInfo = '',
        string $fragment = ''
    ) {
        $this->scheme = $this->filterScheme($scheme);
        $this->userInfo = $userInfo;
        $this->host = $host;
        $this->port = $this->filterPort($port);
        $this->path = $path;
        $this->query = $query;
        $this->fragment = $fragment;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

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

    public function withScheme(string $scheme): static
    {
        $new = clone $this;
        $new->scheme = $this->filterScheme($scheme);

        return $new;
    }

    public function withUserInfo(string $user, ?string $password = null): static
    {
        $new = clone $this;
        $new->userInfo = $password !== null ? $user . ':' . $password : $user;

        return $new;
    }

    public function withHost(string $host): static
    {
        $new = clone $this;
        $new->host = $host;

        return $new;
    }

    public function withPort(?int $port): static
    {
        $new = clone $this;
        $new->port = $this->filterPort($port);

        return $new;
    }

    public function withPath(string $path): static
    {
        $new = clone $this;
        $new->path = $path;

        return $new;
    }

    public function withQuery(string $query): static
    {
        $new = clone $this;
        $new->query = $query;

        return $new;
    }

    public function withFragment(string $fragment): static
    {
        $new = clone $this;
        $new->fragment = $fragment;

        return $new;
    }

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

    private function filterScheme(string $scheme): string
    {
        return strtolower($scheme);
    }

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
}
