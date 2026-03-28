<?php

declare(strict_types=1);

namespace Kode\Http\Server;

use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;

class WorkermanServerAdapter
{
    private $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function run(string $host = '0.0.0.0', int $port = 8080): void
    {
        $worker = new \Workerman\Worker("http://{$host}:{$port}");

        $worker->onMessage = function ($connection, $request) {
            $psrRequest = $this->convertToServerRequest($request);
            $response = ($this->handler)($psrRequest);

            $connection->send($this->formatResponse($response));
        };

        \Workerman\Worker::runAll();
    }

    private function convertToServerRequest($request): ServerRequestInterface
    {
        $method = $request->method ?? 'GET';
        $uri = new \Kode\Http\Psr7\Uri($request->path ?? '/');

        if (isset($request->queryString)) {
            $uri = $uri->withQuery($request->queryString);
        }

        $headers = [];
        foreach ($request->header ?? [] as $name => $value) {
            $headers[$name] = [$value];
        }

        $body = Stream::create($request->rawBody() ?: '');

        $serverParams = [];
        foreach ($request->server ?? [] as $key => $value) {
            $serverParams[strtoupper($key)] = $value;
        }

        return new ServerRequest($method, $uri, $serverParams, $headers, $body);
    }

    private function formatResponse(\Psr\Http\Message\ResponseInterface $response): string
    {
        $status = $response->getStatusCode();
        $headers = '';
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers .= "{$name}: {$value}\r\n";
            }
        }

        $body = $response->getBody()->getContents();

        return "HTTP/{$response->getProtocolVersion()} {$status} {$response->getReasonPhrase()}\r\n{$headers}\r\n{$body}";
    }
}