<?php

declare(strict_types=1);

namespace Kode\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * PSR-7 响应发射器
 *
 * 将 PSR-7 响应输出到 SAPI（PHP-FPM / CLI Server）。
 * 大响应体按块输出，避免一次性载入内存。
 *
 * @example
 * ```php
 * Emitter::emit($response);
 * ```
 */
final class Emitter
{
    /** @var int 分块输出的块大小（字节） */
    public const int CHUNK_SIZE = 8192;

    /**
     * 发射响应
     *
     * @param ResponseInterface $response 待输出的响应
     * @param bool $withBody 是否输出响应体（HEAD 请求应传 false）
     */
    public static function emit(ResponseInterface $response, bool $withBody = true): void
    {
        self::emitHeaders($response);

        if (!$withBody || self::isEmptyBodyStatus($response->getStatusCode())) {
            return;
        }

        // 快速路径：kode 自有响应若仍持有原始字符串体，直接写出，跳过 Stream 物化与分块读取
        if ($response instanceof Response && $response->hasRawBody()) {
            echo $response->getRawBody();
            return;
        }

        self::emitBody($response);
    }

    /**
     * 输出状态行与响应头
     */
    private static function emitHeaders(ResponseInterface $response): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($response->getHeaders() as $name => $values) {
            $first = strtolower($name) !== 'set-cookie';
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), $first);
                $first = false;
            }
        }

        http_response_code($response->getStatusCode());
    }

    /**
     * 分块输出响应体
     */
    private static function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if (!$body->isReadable()) {
            echo (string) $body;
            return;
        }

        while (!$body->eof()) {
            echo $body->read(self::CHUNK_SIZE);
        }
    }

    /**
     * 该状态码是否不允许响应体
     */
    private static function isEmptyBodyStatus(int $status): bool
    {
        return $status === 204 || $status === 205 || $status === 304 || ($status >= 100 && $status < 200);
    }
}
