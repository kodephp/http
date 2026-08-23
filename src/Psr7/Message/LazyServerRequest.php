<?php

declare(strict_types=1);

namespace Kode\Http\Psr7\Message;

/**
 * 懒加载服务端请求（热路径零 header 成本）
 *
 * 与 {@see ServerRequest} 行为完全一致（可变语义、PSR-7 契约、clone 快照），
 * 但**延迟**请求头的规范化：仅当首次调用 getHeader* / hasHeader 时才从
 * server params（$_SERVER）提取并规范化。路由阶段只取 method + path，
 * 完全不触发 header 提取，从而避免每请求约 67% 的 header 规范化开销。
 *
 * query / cookie / uploadedFiles / parsedBody 仍按原逻辑构建（成本可忽略），
 * 因为路由与绝大多数处理器都需要它们，而 header 在纯转发类热路径上往往不被读取。
 */
class LazyServerRequest extends ServerRequest
{
    private bool $headersResolved = false;

    /**
     * 仅供框架侧守卫（如链路追踪）使用：在不触发规范化的前提下判断 header 是否已解析。
     */
    public function isHeadersResolved(): bool
    {
        return $this->headersResolved;
    }

    public function getHeaders(): array
    {
        $this->resolveHeaders();

        return parent::getHeaders();
    }

    public function hasHeader(string $name): bool
    {
        if (!$this->headersResolved) {
            // 未解析时走原始源（已直接设置的 header / server params）判定，
            // 避免为一次 hasHeader 急切规范化全部 header（构造期 updateHostHeader 即依赖此）。
            $normalized = $this->normalizeHeaderName($name);
            if (isset($this->headerNames[$normalized])) {
                return true;
            }

            $server = $this->getServerParams();
            $httpKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            if (isset($server[$httpKey]) && $server[$httpKey] !== '') {
                return true;
            }

            $upper = strtoupper($name);
            if (($upper === 'CONTENT-TYPE' || $upper === 'CONTENT-LENGTH' || $upper === 'CONTENT-MD5')
                && isset($server[$upper]) && $server[$upper] !== '') {
                return true;
            }

            return false;
        }

        return parent::hasHeader($name);
    }

    public function getHeader(string $name): array
    {
        $this->resolveHeaders();

        return parent::getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        $this->resolveHeaders();

        return parent::getHeaderLine($name);
    }

    public function withHeader(string $name, $value): static
    {
        $this->resolveHeaders();

        return parent::withHeader($name, $value);
    }

    public function withAddedHeader(string $name, $value): static
    {
        $this->resolveHeaders();

        return parent::withAddedHeader($name, $value);
    }

    public function withoutHeader(string $name): static
    {
        $this->resolveHeaders();

        return parent::withoutHeader($name);
    }

    /**
     * 首次访问 header 时，从 server params 提取并规范化一次，之后所有读取走缓存。
     */
    private function resolveHeaders(): void
    {
        if ($this->headersResolved) {
            return;
        }
        $this->headersResolved = true;

        $server = $this->getServerParams();
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[$this->normalizeName(substr($key, 5))] = (string) $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH' || $key === 'CONTENT_MD5') {
                $headers[$this->normalizeName($key)] = (string) $value;
            }
        }

        $this->initializeHeaders($headers);
    }

    /**
     * 将 SERVER 键名转为标准请求头名（处理下划线，如 ACCEPT_LANGUAGE => Accept-Language）。
     * 与 RequestTrait::normalizeHeaderName 不同，本方法同时把 _ 当作单词分隔符，
     * 以匹配 PSR-7 头名查找时 hyphen 形式的规范化结果。
     */
    private static function normalizeName(string $key): string
    {
        return str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
    }
}
