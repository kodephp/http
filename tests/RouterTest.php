<?php

declare(strict_types=1);

namespace Kode\Http\Tests;

use Kode\Http\Method;
use Kode\Http\Routing\Route;
use Kode\Http\Routing\RouteResult;
use Kode\Http\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testStaticRouteMatches(): void
    {
        $router = new Router();
        $router->add('GET', '/ping', fn() => 'pong');

        $result = $router->match('GET', '/ping');

        $this->assertSame(RouteResult::FOUND, $result->status);
        $this->assertSame([], $result->params);
    }

    public function testDynamicRouteCapturesParams(): void
    {
        $router = new Router();
        $router->add(['GET'], '/users/{id:\d+}', fn() => null);

        $result = $router->match('GET', '/users/42');

        $this->assertSame(RouteResult::FOUND, $result->status);
        $this->assertSame('42', $result->params['id']);
    }

    public function testDynamicRouteConstraintRejectsNonMatching(): void
    {
        $router = new Router();
        $router->add(['GET'], '/users/{id:\d+}', fn() => null);

        $result = $router->match('GET', '/users/abc');

        $this->assertSame(RouteResult::NOT_FOUND, $result->status);
    }

    public function testMethodNotAllowedDistinguishedFromNotFound(): void
    {
        $router = new Router();
        $router->add(['GET'], '/ping', fn() => null);

        $result = $router->match('DELETE', '/ping');

        $this->assertSame(RouteResult::METHOD_NOT_ALLOWED, $result->status);
        $this->assertContains('GET', $result->allowedMethods);
        $this->assertContains('HEAD', $result->allowedMethods);
    }

    public function testGetAutoRegistersHead(): void
    {
        $router = new Router();
        $router->add(['GET'], '/ping', fn() => null);

        $this->assertNotNull($router->match('HEAD', '/ping')->route);
        $this->assertSame(RouteResult::FOUND, $router->match('HEAD', '/ping')->status);
    }

    public function testGroupAppliesPrefix(): void
    {
        $router = new Router();
        $router->group(['prefix' => '/api'], function (Router $r) {
            $r->add(['GET'], '/info', fn() => null);
        });

        $this->assertSame(RouteResult::FOUND, $router->match('GET', '/api/info')->status);
        $this->assertSame(RouteResult::NOT_FOUND, $router->match('GET', '/info')->status);
    }

    public function testGroupMiddlewareIsScoped(): void
    {
        $calls = [];
        $mw = function (string $label) use (&$calls) {
            return function ($req, $next) use ($label, &$calls) {
                $calls[] = $label;
                return $next->handle($req);
            };
        };

        $router = new Router();
        $router->group(['prefix' => '/admin', 'middleware' => [$mw('group')]], function (Router $r) {
            $r->add(['GET'], '/dash', fn() => null);
        });
        $router->add(['GET'], '/public', fn() => null);

        $route = $router->match('GET', '/admin/dash')->route;
        $this->assertCount(1, $route->getMiddlewares());

        $public = $router->match('GET', '/public')->route;
        $this->assertCount(0, $public->getMiddlewares());
    }

    public function testNamedUrlGenerationWithParams(): void
    {
        $router = new Router();
        $router->add(['GET'], '/users/{id}', fn() => null)->name('user.show');

        $this->assertSame('/users/7', $router->url('user.show', ['id' => 7]));
    }

    public function testNamedUrlThrowsWhenMissingParam(): void
    {
        $router = new Router();
        $router->add(['GET'], '/users/{id}', fn() => null)->name('user.show');

        $this->expectException(\InvalidArgumentException::class);
        $router->url('user.show');
    }

    public function testMatchResultIsCached(): void
    {
        $router = new Router();
        $router->add(['GET'], '/users/{id:\d+}', fn() => null);

        $first = $router->match('GET', '/users/42');
        $second = $router->match('GET', '/users/42');

        $this->assertSame($first, $second, '相同路径的匹配结果应命中缓存');
    }

    public function testNormalizeMethodIsCaseInsensitive(): void
    {
        $router = new Router();
        $router->add(['GET'], '/ping', fn() => null);

        $this->assertSame(RouteResult::FOUND, $router->match('get', '/ping')->status);
    }
}
