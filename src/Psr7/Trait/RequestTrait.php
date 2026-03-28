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
        return $this->headers[$this->headerNames[$name]] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $normalizedName = $this->normalizeHeaderName($name);

        if (is_array($value)) {
            $clone->headers[$name] = $value;
        } else {
            $clone->headers[$name] = [$value];
        }

        $clone->headerNames[$normalizedName] = $name;

        return $clone;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $clone = clone $this;
        $normalizedName = $this->normalizeHeaderName($name);

        if (is_array($value)) {
            $clone->headers[$name] = array_merge($clone->headers[$name] ?? [], $value);
        } else {
            $clone->headers[$name][] = $value;
        }

        $clone->headerNames[$normalizedName] = $name;

        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        $normalizedName = $this->normalizeHeaderName($name);
        $originalName = $this->headerNames[$normalizedName] ?? $name;

        unset($clone->headers[$originalName], $clone->headerNames[$normalizedName]);

        return $clone;
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