<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求体解析中间件
 *
 * 根据 Content-Type 自动解析 JSON / 表单 / XML 请求体，并写入 PSR-7 的
 * parsed body，使 {@see \Kode\Http\Request} 的 input()/array() 等方法可直接读取。
 *
 * - 使用 PHP 8.3 的 json_validate() 做安全校验，避免 json_decode 的静默失败。
 * - 已经解析过（parsedBody !== null）的请求直接放行，幂等可重复。
 *
 * @example
 * ```php
 * $app->pipe(new BodyParser(mergeIntoAttributes: true));
 * ```
 */
final class BodyParser implements MiddlewareInterface
{
    /** 支持解析的 Content-Type（前缀匹配） */
    public const array SUPPORTED = [
        'application/json',
        'application/x-www-form-urlencoded',
        'multipart/form-data',
        'text/xml',
        'application/xml',
    ];

    /**
     * @param bool $mergeIntoAttributes 为 true 时把解析出的数组键并入请求 attribute
     */
    public function __construct(
        private bool $mergeIntoAttributes = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 已经解析过则直接放行（幂等）
        if ($request->getParsedBody() !== null) {
            return $handler->handle($request);
        }

        $type = $this->contentType($request);
        if ($type === null) {
            return $handler->handle($request);
        }

        $body = (string) $request->getBody();
        if ($body === '') {
            return $handler->handle($request);
        }

        $parsed = match (true) {
            str_starts_with($type, 'application/json') => $this->parseJson($body),
            str_starts_with($type, 'application/x-www-form-urlencoded') => $this->parseForm($body),
            str_starts_with($type, 'multipart/form-data') => $request->getParsedBody(),
            str_starts_with($type, 'text/xml'),
            str_starts_with($type, 'application/xml') => $this->parseXml($body),
            default => null,
        };

        if ($parsed === null) {
            return $handler->handle($request);
        }

        $request = $request->withParsedBody($parsed);

        if ($this->mergeIntoAttributes && is_array($parsed)) {
            foreach ($parsed as $key => $value) {
                $request = $request->withAttribute((string) $key, $value);
            }
        }

        return $handler->handle($request);
    }

    /**
     * JSON 解析：先 json_validate 再解码（PHP 8.3）
     *
     * @return array|null
     */
    private function parseJson(string $body): ?array
    {
        if (!json_validate($body)) {
            return null;
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : ['__raw' => $data];
    }

    /**
     * application/x-www-form-urlencoded 解析
     *
     * @return array<string, mixed>
     */
    private function parseForm(string $body): array
    {
        parse_str($body, $result);

        return $result;
    }

    /**
     * XML 解析：经 SimpleXML 转数组
     *
     * @return array|null
     */
    private function parseXml(string $body): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $data = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($data === false) {
            return null;
        }

        $json = json_encode($data);
        if ($json === false) {
            return null;
        }

        $array = json_decode($json, true);

        return is_array($array) ? $array : null;
    }

    private function contentType(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Content-Type');
        if ($header === '') {
            return null;
        }

        return strtolower(trim(explode(';', $header, 2)[0]));
    }
}
