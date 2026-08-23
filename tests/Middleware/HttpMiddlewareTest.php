<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Middleware;

use Kode\Http\Middleware\BodyParser;
use Kode\Http\Middleware\Compression;
use Kode\Http\Middleware\RequestId;
use Kode\Http\Middleware\ResponseTime;
use Kode\Http\Middleware\SecurityHeaders;
use Kode\Http\Psr7\Message\Response as Psr7Response;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpMiddlewareTest extends TestCase
{
    private function finalHandler(mixed $capture = null): RequestHandlerInterface
    {
        return new class($capture) implements RequestHandlerInterface {
            public function __construct(private mixed $capture) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if (is_callable($this->capture)) {
                    ($this->capture)($request);
                }
                return new Psr7Response(200, ['Content-Type' => 'text/html'], Stream::create(str_repeat('a', 5000)));
            }
        };
    }

    private function request(string $method, array $headers = [], ?StreamInterface $body = null): ServerRequestInterface
    {
        return new ServerRequest($method, 'http://x.com/', [], $headers, $body);
    }

    public function testBodyParserPopulatesParsedBody(): void
    {
        $captured = null;
        $mw = new BodyParser();
        $request = $this->request('POST', ['Content-Type' => 'application/json'], Stream::create('{"name":"kode"}'));

        $mw->process($request, $this->finalHandler(function (ServerRequestInterface $req) use (&$captured) {
            $captured = $req->getParsedBody();
        }));

        $this->assertSame(['name' => 'kode'], $captured);
    }

    public function testBodyParserSkipsWhenAlreadyParsed(): void
    {
        $request = $this->request('POST')->withParsedBody(['x' => 1]);
        $mw = new BodyParser();

        $result = $mw->process($request, $this->finalHandler());
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testRequestIdGeneratesAndReuses(): void
    {
        $mw = new RequestId();
        $resp = $mw->process($this->request('GET'), $this->finalHandler());

        $this->assertTrue($resp->hasHeader('X-Request-Id'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $resp->getHeaderLine('X-Request-Id'));
    }

    public function testRequestIdReusesExistingHeader(): void
    {
        $mw = new RequestId();
        $request = $this->request('GET', ['X-Request-Id' => 'fixed-id-123']);
        $resp = $mw->process($request, $this->finalHandler());

        $this->assertSame('fixed-id-123', $resp->getHeaderLine('X-Request-Id'));
    }

    public function testRequestIdCustomHeader(): void
    {
        $mw = new RequestId(header: 'X-Trace-Id');
        $resp = $mw->process($this->request('GET'), $this->finalHandler());

        $this->assertTrue($resp->hasHeader('X-Trace-Id'));
        $this->assertFalse($resp->hasHeader('X-Request-Id'));
    }

    public function testResponseTimeAddsHeader(): void
    {
        $mw = new ResponseTime();
        $resp = $mw->process($this->request('GET'), $this->finalHandler());

        $this->assertMatchesRegularExpression('/^\d+\.\d+ms$/', $resp->getHeaderLine('X-Response-Time'));
    }

    public function testCompressionGzip(): void
    {
        $mw = new Compression();
        $request = $this->request('GET', ['Accept-Encoding' => 'gzip, deflate']);
        $resp = $mw->process($request, $this->finalHandler());

        $this->assertSame('gzip', $resp->getHeaderLine('Content-Encoding'));
        $this->assertSame(
            str_repeat('a', 5000),
            gzdecode((string) $resp->getBody())
        );
        $this->assertStringContainsString('Accept-Encoding', $resp->getHeaderLine('Vary'));
    }

    public function testCompressionSkipsSmallBodies(): void
    {
        $mw = new Compression();
        $request = $this->request('GET', ['Accept-Encoding' => 'gzip']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200, ['Content-Type' => 'text/html'], Stream::create('tiny'));
            }
        };
        $resp = $mw->process($request, $handler);

        $this->assertFalse($resp->hasHeader('Content-Encoding'));
    }

    public function testCompressionSkipsBinaryTypes(): void
    {
        $mw = new Compression();
        $request = $this->request('GET', ['Accept-Encoding' => 'gzip']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200, ['Content-Type' => 'image/png'], Stream::create(str_repeat('b', 5000)));
            }
        };
        $resp = $mw->process($request, $handler);

        $this->assertFalse($resp->hasHeader('Content-Encoding'));
    }

    public function testSecurityHeadersAdded(): void
    {
        $mw = new SecurityHeaders();
        $resp = $mw->process($this->request('GET'), $this->finalHandler());

        $this->assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $resp->getHeaderLine('X-Frame-Options'));
    }

    public function testSecurityHeadersHsts(): void
    {
        $mw = new SecurityHeaders(hsts: true);
        $resp = $mw->process($this->request('GET'), $this->finalHandler());

        $this->assertStringContainsString('max-age=', $resp->getHeaderLine('Strict-Transport-Security'));
    }

    public function testSecurityHeadersDoNotOverrideExisting(): void
    {
        $mw = new SecurityHeaders();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200, ['X-Frame-Options' => 'SAMEORIGIN']);
            }
        };
        $resp = $mw->process($this->request('GET'), $handler);

        $this->assertSame('SAMEORIGIN', $resp->getHeaderLine('X-Frame-Options'));
    }
}
