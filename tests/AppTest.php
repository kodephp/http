<?php

declare(strict_types=1);

namespace Kode\Http\Tests;

use Kode\Http\App;
use Kode\Http\Request;
use Kode\Http\Response;
use Kode\Http\Psr7\Factory\ServerRequestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AppTest extends TestCase
{
    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri);
    }

    public function testGetRouteReturns200(): void
    {
        $app = new App();
        $app->get('/ping', fn() => Response::json(['pong' => true]));

        $resp = $app->handle($this->request('GET', 'http://x.com/ping'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertJsonStringEqualsJsonString('{"pong":true}', (string) $resp->getBody());
    }

    public function testHeadAutoRegisteredForGet(): void
    {
        $app = new App();
        $called = false;
        $app->get('/ping', function () use (&$called) {
            $called = true;
            return Response::json(['ok' => 1]);
        });

        $resp = $app->handle($this->request('HEAD', 'http://x.com/ping'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('', (string) $resp->getBody(), 'HEAD 响应体必须为空');
        $this->assertTrue($called, 'HEAD 仍应执行处理器');
    }

    public function testNotFoundReturnsStandard404(): void
    {
        $app = new App();
        $app->get('/ping', fn() => Response::json([]));

        $resp = $app->handle($this->request('GET', 'http://x.com/nope'));

        $this->assertSame(404, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        $this->assertSame('E1004', $body['code']);
    }

    public function testMethodNotAllowedReturns405WithAllowHeader(): void
    {
        $app = new App();
        $app->get('/ping', fn() => Response::json([]));

        $resp = $app->handle($this->request('DELETE', 'http://x.com/ping'));

        $this->assertSame(405, $resp->getStatusCode());
        $this->assertTrue($resp->hasHeader('Allow'));
        $this->assertStringContainsString('GET', $resp->getHeaderLine('Allow'));
        $this->assertStringContainsString('HEAD', $resp->getHeaderLine('Allow'));
    }

    public function testRouteParametersInjectedAsAttributes(): void
    {
        $app = new App();
        $app->get('/users/{id:\d+}', function () {
            return Response::json(['id' => Request::param('id')]);
        });

        $resp = $app->handle($this->request('GET', 'http://x.com/users/42'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertJsonStringEqualsJsonString('{"id":"42"}', (string) $resp->getBody());
    }

    public function testGroupPrefixAndScopedMiddleware(): void
    {
        $app = new App();
        $log = [];

        $groupMw = function (ServerRequestInterface $req, RequestHandlerInterface $next) use (&$log) {
            $log[] = 'group';
            return $next->handle($req);
        };

        $app->group('/api', function (App $app) {
            $app->get('/info', fn() => Response::json(['info' => true]));
        }, [$groupMw]);

        // 全局路由不应被组中间件影响
        $app->get('/public', function () use (&$log) {
            $log[] = 'public';
            return Response::json([]);
        });

        $resp = $app->handle($this->request('GET', 'http://x.com/api/info'));
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertContains('group', $log);

        $resp2 = $app->handle($this->request('GET', 'http://x.com/public'));
        $this->assertSame(200, $resp2->getStatusCode());
        $this->assertContains('public', $log);
        $this->assertNotContains('group', array_slice($log, array_search('public', $log)));
    }

    public function testNamedUrlGeneration(): void
    {
        $app = new App();
        $app->get('/users/{id}', fn() => Response::json([]))->name('user.show');

        $this->assertSame('/users/7', $app->url('user.show', ['id' => 7]));
    }

    public function testGlobalMiddlewareRunsInOrder(): void
    {
        $app = new App();
        $sequence = [];

        $app->use(function (ServerRequestInterface $req, RequestHandlerInterface $next) use (&$sequence) {
            $sequence[] = 'before';
            $resp = $next->handle($req);
            $sequence[] = 'after';
            return $resp->withHeader('X-Global', 'yes');
        });

        $app->get('/ping', fn() => Response::json([]));

        $resp = $app->handle($this->request('GET', 'http://x.com/ping'));

        $this->assertSame('yes', $resp->getHeaderLine('X-Global'));
        $this->assertSame(['before', 'after'], $sequence);
    }

    public function testHandlerReturningArrayIsNormalizedToJson(): void
    {
        $app = new App();
        $app->get('/data', fn() => ['hello' => 'world']);

        $resp = $app->handle($this->request('GET', 'http://x.com/data'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertJsonStringEqualsJsonString('{"hello":"world"}', (string) $resp->getBody());
    }

    public function testAnyRegistersAllRoutableMethods(): void
    {
        $app = new App();
        $app->any('/echo', fn() => Response::json(['ok' => true]));

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $resp = $app->handle($this->request($method, 'http://x.com/echo'));
            $this->assertSame(200, $resp->getStatusCode(), "方法 {$method} 应命中");
        }
    }

    private function buildFinalHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new \Kode\Http\Psr7\Message\Response(200, [], \Kode\Http\Psr7\Stream::create('ok'));
            }
        };
    }
}
