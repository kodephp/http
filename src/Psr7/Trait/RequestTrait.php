<?php

declare(strict_types=1);

namespace Kode\Http\Psr7\Trait;

use Kode\Http\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

trait RequestTrait
{
    protected array $headers = [];
    protected array $headerNames = [];
    protected ?StreamInterface $body = null;
    /** @var string|null 原始字符串体；非空时 getBody() 才物化为 Stream，避免每请求分配 */
    protected ?string $rawBody = null;

    protected function initializeHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            $normalizedName = $this->normalizeHeaderName($name);
            if (is_array($value)) {
                $this->headers[$name] = $value;
            } else {
                $this->headers[$name] = [$value];
            }
            $this->headerNames[$normalizedName] = $name;
        }
    }

    protected function updateHostHeader(string $host, ?int $port = null): void
    {
        $hostHeader = $host;
        if ($port !== null) {
            $hostHeader .= ':' . $port;
        }

        $this->headerNames['host'] = 'Host';
        $this->headers['Host'] = [$hostHeader];
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        $name = $this->normalizeHeaderName($name);
        return isset($this->headerNames[$name]);
    }

    public function getHeader(string $name): array
    {
        $name = $this->normalizeHeaderName($name);
        $original = $this->headerNames[$name] ?? null;
        return $original !== null ? $this->headers[$original] : [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $normalizedName = $this->normalizeHeaderName($name);

        if (is_array($value)) {
            $this->headers[$name] = $value;
        } else {
            $this->headers[$name] = [$value];
        }

        $this->headerNames[$normalizedName] = $name;

        return $this;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $normalizedName = $this->normalizeHeaderName($name);

        if (is_array($value)) {
            $this->headers[$name] = array_merge($this->headers[$name] ?? [], $value);
        } else {
            $this->headers[$name][] = $value;
        }

        $this->headerNames[$normalizedName] = $name;

        return $this;
    }

    public function withoutHeader(string $name): static
    {
        $normalizedName = $this->normalizeHeaderName($name);
        $originalName = $this->headerNames[$normalizedName] ?? $name;

        unset($this->headers[$originalName], $this->headerNames[$normalizedName]);

        return $this;
    }

    public function getBody(): StreamInterface
    {
        if ($this->body !== null) {
            return $this->body;
        }
        if ($this->rawBody !== null) {
            $this->body = Stream::create($this->rawBody);
            $this->rawBody = null;
            return $this->body;
        }
        return $this->body = Stream::create('');
    }

    protected function normalizeHeaderName(string $name): string
    {
        return strtolower($name);
    }
}