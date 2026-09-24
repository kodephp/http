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

    /**
     * 静态（无参字面量）路由走哈希查表，**先于**任何参数路由命中，与注册先后无关。
     *
     * 应用侧最常见的误解就是「`/x/{id}` 注册在前会把 `/x/export` 吞掉」——
     * 那是把动态那一层的规则错搬到了静态层。本用例把两半都钉住：字面量赢、
     * 参数路由仍接得住自己的 URL（否则「静态优先」可能被「整张动态表都不工作」蒙过去）。
     */
    public function testStaticRouteWinsOverDynamicRegardlessOfOrder(): void
    {
        $router = new Router();
        $router->add('GET', '/x/{id}', 'dynamicFirst');
        $router->add('GET', '/x/export', 'staticSecond');

        $this->assertSame('staticSecond', $router->match('GET', '/x/export')->route?->getHandler());
        $this->assertSame('dynamicFirst', $router->match('GET', '/x/42')->route?->getHandler());
    }

    /**
     * 动态路由之间是**注册顺序先到先得**：贪婪约束排在前，后面注册的一切同方法参数路由都成死路由。
     *
     * 与上一条合起来才是完整规则——顺序只管动态这一半。`{path:.+}` 跨斜杠，
     * 所以 `/api/{path:.+}` 会把 `/api/users/{id}` 整段吃掉。
     */
    public function testDynamicRoutesMatchInRegistrationOrder(): void
    {
        $router = new Router();
        $router->add('GET', '/api/{path:.+}', 'greedyFirst');
        $router->add('GET', '/api/users/{id}', 'specificSecond');

        $result = $router->match('GET', '/api/users/7');

        $this->assertSame('greedyFirst', $result->route?->getHandler());
        $this->assertSame('users/7', $result->params['path']);
    }

    /**
     * 重复注册的赢家方向：静态是「后注册覆盖」（哈希赋值），动态是「先注册赢」（数组追加）。
     *
     * 两者相反，且都不报错、`getRoutes()` 里两条都还在 —— 被覆盖/被吞的那一条只能靠 dispatch 发现。
     */
    public function testDuplicateRegistrationWinnerDiffersBetweenStaticAndDynamic(): void
    {
        $static = new Router();
        $static->add('GET', '/dup', 'first');
        $static->add('GET', '/dup', 'second');
        $this->assertSame('second', $static->match('GET', '/dup')->route?->getHandler());
        $this->assertCount(2, $static->getRoutes(), '被覆盖的那条仍然列在路由表里，所以清单看不出问题');

        $dynamic = new Router();
        $dynamic->add('GET', '/d/{a}', 'first');
        $dynamic->add('GET', '/d/{b}', 'second');
        $this->assertSame('first', $dynamic->match('GET', '/d/1')->route?->getHandler());
    }

    public function testMethodNotAllowedDistinguishedFromNotFound(): void    {
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
