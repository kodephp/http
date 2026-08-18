<?php

declare(strict_types=1);

namespace Kode\Http\Psr7\Trait;

use Kode\Http\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

trait ResponseTrait
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
            // 非破坏性：物化 Stream 作为 $body 缓存，但保留 rawBody 作为字符串真相源，
            // 使 hasRawBody()/Emitter 快速路径/getRawBody() 在任意次 getBody() 后仍可用，
            // 杜绝「二次物化 + 缓存销毁」（kode/process::toHttp11 每请求走 getBody() 的代价）。
            $this->body = Stream::create($this->rawBody);
            return $this->body;
        }
        return $this->body = Stream::create('');
    }

    protected function normalizeHeaderName(string $name): string
    {
        return strtolower($name);
    }
}