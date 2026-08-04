<?php

declare(strict_types=1);

namespace Kode\Http\Psr7;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * 上传文件实现
 *
 * 实现 PSR-7 UploadedFileInterface，补齐 v2 缺失的上传文件抽象。
 * 支持从 $_FILES 结构规范化，支持 SAPI 与非 SAPI（协程服务器）两种落盘方式。
 *
 * @example
 * ```php
 * $file = $request->getUploadedFiles()['avatar'];
 * if ($file->getError() === UPLOAD_ERR_OK) {
 *     $file->moveTo('/data/uploads/' . $file->getClientFilename());
 * }
 * ```
 */
final class UploadedFile implements UploadedFileInterface
{
    /** @var array<int, string> 上传错误码说明 */
    private const array ERROR_MESSAGES = [
        UPLOAD_ERR_OK => '上传成功',
        UPLOAD_ERR_INI_SIZE => '文件超过 upload_max_filesize 限制',
        UPLOAD_ERR_FORM_SIZE => '文件超过表单 MAX_FILE_SIZE 限制',
        UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
        UPLOAD_ERR_NO_FILE => '没有文件被上传',
        UPLOAD_ERR_NO_TMP_DIR => '缺少临时目录',
        UPLOAD_ERR_CANT_WRITE => '写入磁盘失败',
        UPLOAD_ERR_EXTENSION => '扩展阻止了文件上传',
    ];

    private bool $moved = false;

    private ?StreamInterface $stream = null;

    /**
     * @param StreamInterface|string $streamOrFile 流对象或临时文件路径
     */
    public function __construct(
        private StreamInterface|string $streamOrFile,
        private readonly ?int $size = null,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {
        if (!array_key_exists($error, self::ERROR_MESSAGES)) {
            throw new \InvalidArgumentException('非法的上传错误码: ' . $error);
        }

        if ($streamOrFile instanceof StreamInterface) {
            $this->stream = $streamOrFile;
        }
    }

    /**
     * 从 $_FILES 结构规范化为 UploadedFile 树
     *
     * @param array<string, mixed> $files
     * @return array<string, UploadedFileInterface|array>
     */
    public static function normalize(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $normalized[$key] = $value;
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            if (!isset($value['tmp_name'])) {
                $normalized[$key] = self::normalize($value);
                continue;
            }

            $normalized[$key] = is_array($value['tmp_name'])
                ? self::normalizeNested($value)
                : self::fromSpec($value);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function fromSpec(array $spec): self
    {
        return new self(
            (string) $spec['tmp_name'],
            isset($spec['size']) ? (int) $spec['size'] : null,
            isset($spec['error']) ? (int) $spec['error'] : UPLOAD_ERR_OK,
            $spec['name'] ?? null,
            $spec['type'] ?? null,
        );
    }

    /**
     * 处理 multiple 上传时 PHP 的转置结构
     *
     * @param array<string, mixed> $spec
     * @return array<int|string, UploadedFileInterface|array>
     */
    private static function normalizeNested(array $spec): array
    {
        $result = [];

        foreach (array_keys($spec['tmp_name']) as $key) {
            $result[$key] = self::normalize([
                $key => [
                    'tmp_name' => $spec['tmp_name'][$key],
                    'size' => $spec['size'][$key] ?? null,
                    'error' => $spec['error'][$key] ?? UPLOAD_ERR_OK,
                    'name' => $spec['name'][$key] ?? null,
                    'type' => $spec['type'][$key] ?? null,
                ],
            ])[$key];
        }

        return $result;
    }

    public function getStream(): StreamInterface
    {
        $this->assertUsable();

        if ($this->stream === null) {
            $this->stream = Stream::createFromFile((string) $this->streamOrFile);
        }

        return $this->stream;
    }

    public function moveTo(string $targetPath): void
    {
        $this->assertUsable();

        if ($targetPath === '') {
            throw new \InvalidArgumentException('目标路径不能为空');
        }

        $directory = dirname($targetPath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('目标目录不存在或不可写: ' . $directory);
        }

        if (is_string($this->streamOrFile)) {
            $moved = PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' || !is_uploaded_file($this->streamOrFile)
                ? rename($this->streamOrFile, $targetPath)
                : move_uploaded_file($this->streamOrFile, $targetPath);

            if ($moved === false) {
                throw new \RuntimeException('移动上传文件失败: ' . $targetPath);
            }

            $this->moved = true;
            return;
        }

        $target = fopen($targetPath, 'wb');
        if ($target === false) {
            throw new \RuntimeException('无法写入目标文件: ' . $targetPath);
        }

        $stream = $this->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        while (!$stream->eof()) {
            fwrite($target, $stream->read(8192));
        }

        fclose($target);
        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    /**
     * 上传是否成功
     */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && !$this->moved;
    }

    /**
     * 获取错误描述
     */
    public function getErrorMessage(): string
    {
        return self::ERROR_MESSAGES[$this->error] ?? '未知的上传错误';
    }

    /**
     * 获取客户端文件扩展名（小写，不含点）
     */
    public function getClientExtension(): string
    {
        return strtolower(pathinfo($this->clientFilename ?? '', PATHINFO_EXTENSION));
    }

    private function assertUsable(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->getErrorMessage());
        }

        if ($this->moved) {
            throw new \RuntimeException('上传文件已被移动，无法重复操作');
        }
    }
}
