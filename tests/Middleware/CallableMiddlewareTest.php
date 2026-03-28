<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Middleware;

use Kode\Http\Middleware\CallableMiddleware;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CallableMiddlewareTest extends TestCase
{
    public function testCallableMiddlewareImplementsInterface(): void
    {
        $middleware = new CallableMiddleware(fn($r, $h) => $h->handle($r));
        $this->assertInstanceOf(\Psr\Http\Server\MiddlewareInterface::class, $middleware);
    }

    public function testProcessCallsCallable(): void
    {
        $called = false;
        $middleware = new CallableMiddleware(function ($request, $handler) use (&$called) {
            $called = true;
            return $handler->handle($request);
        });

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], Stream::create('OK'));
            }
        };

        $request = new ServerRequest('GET', '/');
        $middleware->process($request, $handler);

        $this->assertTrue($called);
    }

    public function testProcessReturnsHandlerResponse(): void
    {
        $middleware = new CallableMiddleware(fn($r, $h) => $h->handle($r));

        $expectedResponse = new Response(200, [], Stream::create('OK'));
        $handler = new class($expectedResponse) implements RequestHandlerInterface {
            private $response;
            public function __construct($response) { $this->response = $response; }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $request = new ServerRequest('GET', '/');
        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testProcessCanReturnOwnResponse(): void
    {
        $expectedResponse = new Response(403, [], Stream::create('Forbidden'));
        $middleware = new CallableMiddleware(fn($r, $h) => $expectedResponse);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $request = new ServerRequest('GET', '/');
        $response = $middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testClosureWithReference(): void
    {
        $value = 0;
        $middleware = new CallableMiddleware(function ($request, $handler) use (&$value) {
            $value = 42;
            return $handler->handle($request);
        });

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $request = new ServerRequest('GET', '/');
        $middleware->process($request, $handler);

        $this->assertEquals(42, $value);
    }

    public function testCallableToClosureConversion(): void
    {
        $middleware = new CallableMiddleware([self::class, 'staticHandler']);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], Stream::create('Converted'));
            }
        };

        $request = new ServerRequest('GET', '/');
        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public static function staticHandler($request, $handler): Response
    {
        return $handler->handle($request);
    }
}