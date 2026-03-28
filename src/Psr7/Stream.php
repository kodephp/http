<?php

declare(strict_types=1);

namespace Kode\Http\Psr7;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * 流式正文实现
 *
 * 实现了 PSR-7 StreamInterface，用于处理 HTTP 消息的正文内容。
 * 完全自研实现，不依赖 Guzzle 等第三方库。
 *
 * 特性：
 * - 支持读取、写入、定位操作
 * - 支持检测流是否可读、可写、可定位
 * - 支持获取流元数据和大小
 * - 自动内存管理
 *
 * @example
 * ```php
 * // 从字符串创建
 * $stream = Stream::create('Hello World');
 *
 * // 从文件创建
 * $stream = Stream::createFromFile('/path/to/file.txt');
 *
 * // 读取内容
 * $content = $stream->getContents();
 * ```
 */
class Stream implements StreamInterface
{
    /** @var resource|object 流资源或对象 */
    private mixed $resource;

    /** @var int|null 流大小缓存 */
    private ?int $size = null;

    /** @var bool 是否可定位 */
    private bool $seekable;

    /** @var bool 是否可读 */
    private bool $readable;

    /** @var bool 是否可写 */
    private bool $writable;

    /** @var string 流 URI（用于元数据） */
    private string $uri;

    /** @var bool 是否拥有此流资源 */
    private bool $owned = true;

    /**
     * 构造函数
     *
     * @param mixed $resource PHP 流资源或可对象化的资源
     * @param string $mode 流的打开模式，如 'r', 'w+', 'a' 等
     * @throws \InvalidArgumentException 当资源无效时抛出
     */
    public function __construct(mixed $resource, string $mode = 'r')
    {
        if (!is_resource($resource) && !is_object($resource)) {
            throw new \InvalidArgumentException('无效的流资源类型');
        }

        $this->resource = $resource;
        $this->uri = '';

        $metadata = $this->getMetadata();
        $this->seekable = ($metadata['seekable'] ?? false) && fseek($resource, 0, SEEK_CUR) === 0;

        $firstChar = $mode[0];
        $hasPlus = str_contains($mode, '+');
        $this->readable = $firstChar === 'r' || ($hasPlus && ($firstChar === 'w' || $firstChar === 'a' || $firstChar === 'c'));
        $this->writable = $firstChar !== 'r' || $hasPlus;
    }

    /**
     * 从字符串创建流
     *
     * @param string $content 流内容
     * @param string $mode 打开模式，默认 'r+'
     * @return StreamInterface 新的流实例
     *
     * @example
     * ```php
     * $stream = Stream::create('Hello');
     * ```
     */
    public static function create(string $content = '', string $mode = 'r+'): StreamInterface
    {
        $resource = fopen('php://temp', $mode);
        if ($resource === false) {
            throw new RuntimeException('无法创建临时流');
        }
        if ($content !== '') {
            fwrite($resource, $content);
            fseek($resource, 0);
        }
        return new self($resource, $mode);
    }

    /**
     * 从文件创建流
     *
     * @param string $filename 文件路径
     * @param string $mode 打开模式，默认 'r'
     * @return StreamInterface 新的流实例
     * @throws RuntimeException 当文件无法打开时抛出
     */
    public static function createFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $resource = fopen($filename, $mode);
        if ($resource === false) {
            throw new RuntimeException('无法打开文件: ' . $filename);
        }
        return new self($resource, $mode);
    }

    /**
     * 从资源创建流
     *
     * @param mixed $resource PHP 流资源
     * @return StreamInterface 新的流实例
     */
    public static function createFromResource(mixed $resource): StreamInterface
    {
        return new self($resource);
    }

    /**
     * 转换为字符串
     *
     * 获取流的全部内容。如果流不可读，返回空字符串。
     *
     * @return string 流内容
     */
    public function __toString(): string
    {
        if (!$this->readable) {
            return '';
        }

        try {
            $this->rewind();
            return $this->getContents();
        } catch (RuntimeException $e) {
            return '';
        }
    }

    /**
     * 关闭流
     *
     * 释放流资源。如果是所有者，关闭底层的流资源。
     */
    public function close(): void
    {
        if ($this->owned && is_resource($this->resource)) {
            fclose($this->resource);
        }
        $this->resource = null;
    }

    /**
     * 分离流资源
     *
     * 将流从实例中分离并返回底层资源。
     *
     * @return mixed 底层资源
     */
    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        $this->size = null;
        $this->uri = '';
        return $resource;
    }

    /**
     * 获取流大小
     *
     * @return int|null 流大小（字节），未知时返回 null
     */
    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if ($this->resource === null) {
            return null;
        }

        $stat = fstat($this->resource);
        if ($stat !== false && isset($stat['size'])) {
            $this->size = $stat['size'];
            return $this->size;
        }

        return null;
    }

    /**
     * 获取当前指针位置
     *
     * @return int 当前字节位置
     * @throws RuntimeException 当流已分离或无法获取位置时抛出
     */
    public function tell(): int
    {
        if ($this->resource === null) {
            throw new RuntimeException('流已分离');
        }

        $position = ftell($this->resource);
        if ($position === false) {
            throw new RuntimeException('无法获取流位置');
        }

        return $position;
    }

    /**
     * 检查是否到达流末尾
     *
     * @return bool 如果到达末尾返回 true
     */
    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    /**
     * 检查流是否可定位
     *
     * @return bool 如果可定位返回 true
     */
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    /**
     * 定位流指针
     *
     * @param int $offset 偏移量
     * @param int $whence 定位模式：SEEK_SET、SEEK_CUR、SEEK_END
     * @throws RuntimeException 当流不可定位或定位失败时抛出
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($this->resource === null) {
            throw new RuntimeException('流已分离');
        }

        if (!$this->seekable) {
            throw new RuntimeException('流不可定位');
        }

        $result = fseek($this->resource, $offset, $whence);
        if ($result === -1) {
            throw new RuntimeException('无法定位到位置: ' . $offset);
        }
    }

    /**
     * 回到流开头
     *
     * @throws RuntimeException 当流不可定位时抛出
     */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * 检查流是否可写
     *
     * @return bool 如果可写返回 true
     */
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * 写入流
     *
     * @param string $string 要写入的字符串
     * @return int 写入的字节数
     * @throws RuntimeException 当流不可写或写入失败时抛出
     */
    public function write(string $string): int
    {
        if ($this->resource === null) {
            throw new RuntimeException('流已分离');
        }

        if (!$this->writable) {
            throw new RuntimeException('流不可写');
        }

        $bytes = fwrite($this->resource, $string);
        if ($bytes === false) {
            throw new RuntimeException('写入流失败');
        }

        $this->size = null;
        return $bytes;
    }

    /**
     * 检查流是否可读
     *
     * @return bool 如果可读返回 true
     */
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * 读取流
     *
     * @param int $length 要读取的字节数
     * @return string 读取的内容
     * @throws RuntimeException 当流不可读或读取失败时抛出
     */
    public function read(int $length): string
    {
        if ($this->resource === null) {
            throw new RuntimeException('流已分离');
        }

        if (!$this->readable) {
            throw new RuntimeException('流不可读');
        }

        if ($length < 0) {
            throw new RuntimeException('长度必须为非负数');
        }

        $result = fread($this->resource, $length);
        if ($result === false) {
            throw new RuntimeException('读取流失败');
        }

        return $result;
    }

    /**
     * 获取剩余内容
     *
     * @return string 剩余内容
     * @throws RuntimeException 当流不可读时抛出
     */
    public function getContents(): string
    {
        if ($this->resource === null) {
            throw new RuntimeException('流已分离');
        }

        if (!$this->readable) {
            throw new RuntimeException('流不可读');
        }

        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new RuntimeException('获取流内容失败');
        }

        return $contents;
    }

    /**
     * 获取流元数据
     *
     * @param string|null $key 要获取的特定元数据键，为 null 时返回全部
     * @return mixed 指定的元数据值或元数据数组
     */
    public function getMetadata(?string $key = null): mixed
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }

        $metadata = stream_get_meta_data($this->resource);

        if ($key === null) {
            return $metadata;
        }

        return $metadata[$key] ?? null;
    }
}