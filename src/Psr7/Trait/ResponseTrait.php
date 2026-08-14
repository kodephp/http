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
        return $this->body ?? Stream::create('');
    }

    protected function normalizeHeaderName(string $name): string
    {
        return strtolower($name);
    }
}