<?php

declare(strict_types=1);

namespace Kode\Http\Psr7\Factory;

use Kode\Http\Psr7\Message\Request;
use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\Stream;
use Kode\Http\Psr7\UploadedFile;
use Kode\Http\Psr7\Uri;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-17 聚合工厂
 *
 * 一个实例同时实现全部六个 PSR-17 工厂接口，
 * 便于注入到只接受单一工厂对象的第三方库（如 HTTPlug discovery）。
 *
 * @example
 * ```php
 * $factory = new Psr17Factory();
 * $response = $factory->createResponse(201);
 * $stream   = $factory->createStream('{"ok":true}');
 * ```
 */
final class Psr17Factory implements
    RequestFactoryInterface,
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface,
    UriFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri instanceof UriInterface ? $uri : new Uri((string) $uri));
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, [], null, '1.1', $reasonPhrase);
    }

    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest($method, $uri instanceof UriInterface ? $uri : new Uri((string) $uri), $serverParams);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return Stream::create($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return Stream::createFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return Stream::createFromResource($resource);
    }

    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = \UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ): UploadedFileInterface {
        return new UploadedFile($stream, $size ?? $stream->getSize(), $error, $clientFilename, $clientMediaType);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
