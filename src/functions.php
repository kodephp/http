<?php

declare(strict_types=1);

namespace Kode\Http;

use Kode\Http\Psr7\Factory\Psr17Factory;

if (!function_exists('Kode\Http\psr17_factory')) {
    /**
     * 获取 PSR-17 聚合工厂实例（实现全部六个 PSR-17 接口）
     */
    function psr17_factory(): Psr17Factory
    {
        return new Psr17Factory();
    }
}

if (!function_exists('Kode\Http\request_factory')) {
    function request_factory(): Psr17Factory
    {
        return new Psr17Factory();
    }
}

if (!function_exists('Kode\Http\create_request')) {
    function create_request(string $method, string $uri): \Psr\Http\Message\RequestInterface
    {
        return (new Psr17Factory())->createRequest($method, $uri);
    }
}

if (!function_exists('Kode\Http\create_response')) {
    function create_response(int $code = 200, string $reasonPhrase = ''): \Psr\Http\Message\ResponseInterface
    {
        return (new Psr17Factory())->createResponse($code, $reasonPhrase);
    }
}

if (!function_exists('Kode\Http\create_server_request')) {
    function create_server_request(string $method, string $uri, array $serverParams = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest($method, $uri, $serverParams);
    }
}

if (!function_exists('Kode\Http\create_stream')) {
    function create_stream(string $content = ''): \Psr\Http\Message\StreamInterface
    {
        return (new Psr17Factory())->createStream($content);
    }
}

if (!function_exists('Kode\Http\create_uri')) {
    function create_uri(string $uri = ''): \Psr\Http\Message\UriInterface
    {
        return (new Psr17Factory())->createUri($uri);
    }
}
