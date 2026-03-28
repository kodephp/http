<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Middleware;

use Kode\Http\Middleware\MiddlewareDispatcher;
use Kode\Http\Middleware\CallableMiddleware;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MiddlewareDispatcherTest extends TestCase
{
    public function testDispatchWithoutMiddleware(): void
    {
        $finalResponse = new Response(200, [], Stream::create('Final'));

        $handler = new class($finalResponse) implements RequestHandlerInterface {
            private $response;
            public function __construct($response) { $this->response = $response; }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $dispatcher = new MiddlewareDispatcher($handler);
        $request = new ServerRequest('GET', '/');

        $response = $dispatcher->dispatch($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Final', $response->getBody()->getContents());
    }

    public function testDispatchWithSingleMiddleware(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], Stream::create('Final'));
            }
        };

        $middlewareCalled = false;
        $middleware = new CallableMiddleware(function ($request, $handler) use (&$middlewareCalled) {
            $middlewareCalled = true;
            return $handler->handle($request);
        });

        $dispatcher = new MiddlewareDispatcher($handler);
        $dispatcher->pipe($middleware);

        $request = new ServerRequest('GET', '/');
        $response = $dispatcher->dispatch($request);

        $this->assertTrue($middlewareCalled);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDispatchWithMultipleMiddleware(): void
    {
        $callOrder = [];

        $handler = new class($callOrder) implements RequestHandlerInterface {
            private $callOrder;
            public function __construct(array &$callOrder) { $this->callOrder = &$callOrder; }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->callOrder[] = 'handler';
                return new Response(200, [], Stream::create('Final'));
            }
        };

        $middleware1 = new CallableMiddleware(function ($request, $handler) use (&$callOrder) {
            $callOrder[] = 'middleware1-before';
            $response = $handler->handle($request);
            $callOrder[] = 'middleware1-after';
            return $response;
        });

        $middleware2 = new CallableMiddleware(function ($request, $handler) use (&$callOrder) {
            $callOrder[] = 'middleware2-before';
            $response = $handler->handle($request);
            $callOrder[] = 'middleware2-after';
            return $response;
        });

        $dispatcher = new MiddlewareDispatcher($handler);
        $dispatcher->pipe($middleware1);
        $dispatcher->pipe($middleware2);

        $request = new ServerRequest('GET', '/');
        $dispatcher->dispatch($request);

        $expected = ['middleware1-before', 'middleware2-before', 'handler', 'middleware2-after', 'middleware1-after'];
        $this->assertEquals($expected, $callOrder);
    }

    public function testMiddlewareCanModifyRequest(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $customHeader = $request->getHeaderLine('X-Custom');
                return new Response(200, [], Stream::create($customHeader));
            }
        };

        $middleware = new CallableMiddleware(function ($request, $handler) {
            $newRequest = $request->withHeader('X-Custom', 'modified');
            return $handler->handle($newRequest);
        });

        $dispatcher = new MiddlewareDispatcher($handler);
        $dispatcher->pipe($middleware);

        $request = new ServerRequest('GET', '/');
        $response = $dispatcher->dispatch($request);

        $this->assertEquals('modified', $response->getBody()->getContents());
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], Stream::create('Should not reach'));
            }
        };

        $middleware = new CallableMiddleware(function ($request, $handler) {
            return new Response(403, [], Stream::create('Forbidden'));
        });

        $dispatcher = new MiddlewareDispatcher($handler);
        $dispatcher->pipe($middleware);

        $request = new ServerRequest('GET', '/');
        $response = $dispatcher->dispatch($request);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals('Forbidden', $response->getBody()->getContents());
    }

    public function testPipeReturnsDispatcher(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $dispatcher = new MiddlewareDispatcher($handler);
        $result = $dispatcher->pipe(new CallableMiddleware(fn($r, $h) => $h->handle($r)));

        $this->assertSame($dispatcher, $result);
    }
}