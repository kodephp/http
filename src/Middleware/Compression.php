<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 响应压缩中间件
 *
 * 根据客户端 Accept-Encoding 协商 gzip / deflate 对响应体进行压缩，
 * 显著减少传输体积。内置跳过二进制/已压缩类型的保护，避免二次压缩。
 *
 * - 已设置 Content-Encoding 的响应直接放行（不重复压缩）
 * - 小于 minSize 的响应不压缩（压缩收益小于开销）
 * - 图片/音视频/压缩包等二进制类型自动跳过
 *
 * @example
 * ```php
 * $app->pipe(new Compression());        // 默认最小 1KB 才压缩
 * $app->pipe(new Compression(minSize: 512));
 * ```
 */
final class Compression implements MiddlewareInterface
{
    /** 支持的压缩编码（按优先级） */
    public const array ENCODINGS = ['gzip', 'deflate'];

    /** 不压缩的 Content-Type 前缀 */
    public const array SKIP_TYPES = [
        'image/',
        'audio/',
        'video/',
        'application/zip',
        'application/gzip',
        'application/x-gzip',
        'application/x-rar-compressed',
        'application/pdf',
        'application/octet-stream',
        'font/',
    ];

    /**
     * @param int $minSize 触发压缩的最小响应体字节数
     */
    public function __construct(
        private int $minSize = 1024,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $encoding = $this->negotiate($request);
        if ($encoding === null) {
            return $response;
        }

        if ($response->hasHeader('Content-Encoding')) {
            return $response;
        }

        if ($this->isSkippedType($response)) {
            return $response;
        }

        $body = $this->readBody($response);
        if ($body === null || strlen($body) < $this->minSize) {
            return $response;
        }

        $compressed = $encoding === 'gzip'
            ? gzencode($body, 9)
            : gzdeflate($body, 9);

        if ($compressed === false) {
            return $response;
        }

        return $response
            ->withBody(Stream::create($compressed))
            ->withHeader('Content-Encoding', $encoding)
            ->withHeader('Content-Length', (string) strlen($compressed))
            ->withAddedHeader('Vary', 'Accept-Encoding');
    }

    private function negotiate(ServerRequestInterface $request): ?string
    {
        $accept = strtolower($request->getHeaderLine('Accept-Encoding'));
        if ($accept === '' || $accept === 'identity') {
            return null;
        }

        foreach (self::ENCODINGS as $encoding) {
            if (str_contains($accept, $encoding)) {
                return $encoding;
            }
        }

        return null;
    }

    private function isSkippedType(ResponseInterface $response): bool
    {
        $type = strtolower($response->getHeaderLine('Content-Type'));

        foreach (self::SKIP_TYPES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 安全读取响应体（可寻址则先 rewind，避免读到空内容）
     */
    private function readBody(ResponseInterface $response): ?string
    {
        $stream = $response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $contents = $stream->getContents();

        return $contents === '' ? null : $contents;
    }
}
