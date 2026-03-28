<?php

declare(strict_types=1);

namespace Kode\Http;

use Kode\Http\Psr7\Factory\RequestFactory;

if (!function_exists('Kode\Http\request_factory')) {
    function request_factory(): RequestFactory
    {
        return new RequestFactory();
    }
}

if (!function_exists('Kode\Http\create_request')) {
    function create_request(string $method, string $uri): \Psr\Http\Message\RequestInterface
    {
        return (new RequestFactory())->createRequest($method, $uri);
    }
}

if (!function_exists('Kode\Http\create_response')) {
    function create_response(int $code = 200, string $reasonPhrase = ''): \Psr\Http\Message\ResponseInterface
    {
        return (new RequestFactory())->createResponse($code, $reasonPhrase);
    }
}

if (!function_exists('Kode\Http\create_server_request')) {
    function create_server_request(string $method, string $uri, array $serverParams = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new RequestFactory())->createServerRequest($method, $uri, $serverParams);
    }
}

if (!function_exists('Kode\Http\create_stream')) {
    function create_stream(string $content = ''): \Psr\Http\Message\StreamInterface
    {
        return (new RequestFactory())->createStream($content);
    }
}

if (!function_exists('Kode\Http\create_uri')) {
    function create_uri(string $uri = ''): \Psr\Http\Message\UriInterface
    {
        return (new RequestFactory())->createUri($uri);
    }
}