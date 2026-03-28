<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

class JsonErrorHandlerMiddleware implements MiddlewareInterface
{
    private bool $debugMode;
    private array $errorHandlers = [];

    public function __construct(bool $debugMode = false)
    {
        $this->debugMode = $debugMode;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): Response
    {
        try {
            $response = $handler->handle($request);

            if ($response->getStatusCode() >= 400) {
                return $this->handleErrorResponse($response);
            }

            return $this->ensureJsonContentType($response);
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    private function handleException(\Throwable $e): Response
    {
        $statusCode = 500;
        $message = 'Internal Server Error';

        if ($e instanceof \Kode\Http\Exception\HttpException) {
            $statusCode = $e->getStatusCode();
        } elseif ($e instanceof \InvalidArgumentException) {
            $statusCode = 400;
        }

        $errorData = [
            'error' => [
                'message' => $this->debugMode ? $e->getMessage() : $message,
                'code' => $statusCode,
                'type' => basename(str_replace('\\', '/', get_class($e))),
            ],
        ];

        if ($this->debugMode) {
            $errorData['error']['file'] = $e->getFile();
            $errorData['error']['line'] = $e->getLine();
            $errorData['error']['trace'] = $e->getTraceAsString();
        }

        return new Response(
            $statusCode,
            ['Content-Type' => 'application/json'],
            Stream::create(json_encode($errorData, JSON_UNESCAPED_UNICODE))
        );
    }

    private function handleErrorResponse(Response $response): Response
    {
        if ($this->isJsonContentType($response)) {
            return $response;
        }

        $body = (string) $response->getBody();

        $errorData = [
            'error' => [
                'message' => $body ?: 'An error occurred',
                'code' => $response->getStatusCode(),
            ],
        ];

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create(json_encode($errorData, JSON_UNESCAPED_UNICODE)));
    }

    private function ensureJsonContentType(Response $response): Response
    {
        if ($this->isJsonContentType($response)) {
            return $response;
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function isJsonContentType(Response $response): bool
    {
        $contentType = $response->getHeaderLine('Content-Type');
        return str_contains(strtolower($contentType), 'application/json');
    }

    public function onError(int $statusCode, callable $handler): self
    {
        $this->errorHandlers[$statusCode] = $handler;
        return $this;
    }
}