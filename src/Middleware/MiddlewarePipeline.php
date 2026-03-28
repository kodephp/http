<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MiddlewarePipeline implements RequestHandlerInterface
{
    private array $middlewares = [];
    private RequestHandlerInterface $finalHandler;
    private int $index = 0;

    public function __construct(RequestHandlerInterface $finalHandler)
    {
        $this->finalHandler = $finalHandler;
    }

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->index >= count($this->middlewares)) {
            return $this->finalHandler->handle($request);
        }

        $middleware = $this->middlewares[$this->index++];
        return $middleware->process($request, $this);
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
}