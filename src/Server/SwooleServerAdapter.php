<?php

declare(strict_types=1);

namespace Kode\Http\Server;

use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;

class SwooleServerAdapter
{
    private $handler;
    private \Swoole\Http\Server $server;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function run(string $host = '0.0.0.0', int $port = 8080): void
    {
        $this->server = new \Swoole\Http\Server($host, $port);

        $this->server->on('request', function (\Swoole\Http\Request $swooleRequest, \Swoole\Http\Response $swooleResponse) {
            $request = $this->convertToServerRequest($swooleRequest);
            $response = ($this->handler)($request);

            $swooleResponse->status($response->getStatusCode(), $response->getReasonPhrase());

            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $swooleResponse->header($name, $value);
                }
            }

            $swooleResponse->end($response->getBody()->getContents());
        });

        $this->server->start();
    }

    private function convertToServerRequest(\Swoole\Http\Request $swooleRequest): ServerRequestInterface
    {
        $method = $swooleRequest->method ?? 'GET';
        $uri = new \Kode\Http\Psr7\Uri($swooleRequest->server['request_uri'] ?? '/');

        if (isset($swooleRequest->server['query_string'])) {
            $uri = $uri->withQuery($swooleRequest->server['query_string']);
        }

        $headers = [];
        foreach ($swooleRequest->header ?? [] as $name => $value) {
            $headers[$name] = [$value];
        }

        $body = Stream::create($swooleRequest->rawContent() ?: '');

        $serverParams = $swooleRequest->server ?? [];

        return new ServerRequest($method, $uri, $serverParams, $headers, $body);
    }
}