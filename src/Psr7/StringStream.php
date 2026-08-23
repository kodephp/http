<?php

declare(strict_types=1);

namespace Kode\Http\Psr7;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * 纯内存字符串流（无底层资源）。
 *
 * 用于响应体等「已知全部内容、无需流式/落盘」的场景，替代 `Stream::create()`
 * 默认的 `fopen('php://temp')` 实现，消除每响应一次临时流分配 + 两次整段拷贝
 * （fwrite 写入、stream_get_contents 读回）的热路径开销。
 *
 * 大体积正文（> 1MB）仍由 {@see Stream::create()} 回落到 php://temp 以保留落盘能力，
 * 本实现只覆盖小体量（绝大多数 HTTP 响应体）的快路径。
 */
final class StringStream implements StreamInterface
{
    private string $content;

    public function __construct(string $content = '')
    {
        $this->content = $content;
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void
    {
        $this->content = '';
    }

    public function detach()
    {
        $this->content = '';

        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return strlen($this->content);
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('StringStream 不可定位');
    }

    public function rewind(): void
    {
        throw new RuntimeException('StringStream 不可定位');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('StringStream 不可写');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return $this->content;
    }

    public function getContents(): string
    {
        return $this->content;
    }

    public function getMetadata(?string $key = null)
    {
        $meta = [
            'timed_out' => false,
            'blocked' => false,
            'eof' => true,
            'unread_bytes' => 0,
            'stream_type' => 'string',
            'wrapper_type' => 'string',
            'mode' => 'r',
            'seekable' => false,
        ];

        return $key === null ? $meta : ($meta[$key] ?? null);
    }
}
