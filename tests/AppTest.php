<?php

declare(strict_types=1);

namespace Kode\Http\Tests;

use Kode\Http\App;
use Kode\Http\Request;
use Kode\Http\Response;
use Kode\Http\Psr7\Factory\ServerRequestFactory;
use Kode\Http\Routing\RouteRunner;
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

    /**
     * 控制器实例 worker 级缓存：同一 "Class@method" 处理器
     * 在多次请求中复用同一个实例，不重复实例化。
     */
    public function testControllerInstanceCachedAcrossRequests(): void
    {
        $app = new App();
        $app->get('/cached', TestCachedController::class . '@handle');

        $resp1 = $app->handle($this->request('GET', 'http://x.com/cached'));
        $resp2 = $app->handle($this->request('GET', 'http://x.com/cached'));

        $this->assertSame(200, $resp1->getStatusCode());
        $this->assertSame(200, $resp2->getStatusCode());

        // 两次请求应复用同一控制器实例
        $this->assertSame(1, TestCachedController::$constructCount,
            '控制器应只实例化一次，后续请求复用缓存实例');
    }

    /**
     * invoke() 路径同样受益于实例缓存。
     */
    public function testInvokeReusesCachedInstance(): void
    {
        $request = $this->request('GET', 'http://x.com/test');

        // 第一次调用 invoke
        RouteRunner::invoke(TestCachedController::class . '@handle', $request, []);
        $countAfterFirst = TestCachedController::$constructCount;

        // 第二次调用 invoke — 不应再次实例化
        RouteRunner::invoke(TestCachedController::class . '@handle', $request, []);

        $this->assertSame($countAfterFirst, TestCachedController::$constructCount,
            'invoke() 第二次调用应复用缓存实例');
    }

    /**
     * callable 缓存：invoke() 多次调用同一 handler 返回同一 callable。
     */
    public function testInvokeReturnsConsistentResults(): void
    {
        $request = $this->request('GET', 'http://x.com/test');

        $result1 = RouteRunner::invoke(TestCallableCacheController::class . '@handle', $request, []);
        $result2 = RouteRunner::invoke(TestCallableCacheController::class . '@handle', $request, []);

        $this->assertSame(
            $result1['instance_id'],
            $result2['instance_id'],
            '两次 invoke() 应复用同一控制器实例（callable 缓存生效）'
        );
    }

    /**
     * callable 缓存对数组 [class, method] 格式同样生效。
     */
    public function testInvokeArrayHandlerCachesCallable(): void
    {
        $request = $this->request('GET', 'http://x.com/test');

        $result1 = RouteRunner::invoke([TestCallableCacheController::class, 'handle'], $request, []);
        $result2 = RouteRunner::invoke([TestCallableCacheController::class, 'handle'], $request, []);

        $this->assertSame(
            $result1['instance_id'],
            $result2['instance_id'],
            '数组 [class, method] handler 也应复用缓存实例'
        );
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

    public function testStaticRouteFacadeIdentityAcrossMiddleware(): void
    {
        // 非 bare 栈下 App::handle 已预置同一实例；RouteRunner 须复用而非重复写入。
        // 语义锁死：中间件内与处理器内经 facade 读到的是同一对象。
        $app = new App();
        $seenInMiddleware = null;
        $seenInHandler = null;
        $app->use(static function (ServerRequestInterface $req, RequestHandlerInterface $next) use (&$seenInMiddleware): ResponseInterface {
            $seenInMiddleware = Request::getRequest();
            return $next->handle($req);
        });
        $app->get('/ping', static function () use (&$seenInHandler): ResponseInterface {
            $seenInHandler = Request::getRequest();
            return Response::json(['pong' => true]);
        });

        $resp = $app->handle($this->request('GET', 'http://x.com/ping'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertNotNull($seenInMiddleware);
        $this->assertNotNull($seenInHandler);
        $this->assertSame($seenInMiddleware, $seenInHandler, '静态路由：facade 实例在中间件与处理器间必须同一');
    }

    public function testParamRouteFacadeCarriesAttributes(): void
    {
        // 有参路由经 withAttribute 克隆出新实例，facade 必须指向带参数的新实例。
        $app = new App();
        $app->get('/users/{id:\d+}', static fn() => Response::json(['id' => Request::getRequest()?->getAttribute('id')]));

        $resp = $app->handle($this->request('GET', 'http://x.com/users/42'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertJsonStringEqualsJsonString('{"id":"42"}', (string) $resp->getBody());
    }

    public function testNotFoundBranchSyncsFacade(): void
    {
        // bare 栈下 App 层跳过预置，404 分支仍须保证 facade 可用（在处理器内捕获，
        // handle 返回后 App::handle 的 finally 已 clear，直接断言必为 null）。
        $app = new App();
        $seenInNotFound = null;
        $app->get('/ping', fn() => Response::json([]));
        $app->notFound(static function (ServerRequestInterface $req) use (&$seenInNotFound): ResponseInterface {
            $seenInNotFound = Request::getRequest();
            return Response::json(['code' => 'E1004'])->status(404);
        });

        $resp = $app->handle($this->request('GET', 'http://x.com/nope'));

        $this->assertSame(404, $resp->getStatusCode());
        $this->assertNotNull($seenInNotFound, '404 分支须同步 facade');
    }
}

/**
 * 测试用无状态控制器：计数构造次数以验证实例缓存。
 */
class TestCachedController
{
    public static int $constructCount = 0;

    public function __construct()
    {
        ++self::$constructCount;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::json(['ok' => true]);
    }
}

/**
 * 测试用控制器：返回实例 ID 以验证 callable 缓存。
 */
class TestCallableCacheController
{
    public function handle(ServerRequestInterface $request): array
    {
        return ['instance_id' => spl_object_id($this)];
    }
}
