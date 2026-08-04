<?php

declare(strict_types=1);

namespace Kode\Http\Tests;

use Kode\Http\Routing\Route;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    public function testStaticRouteIsStatic(): void
    {
        $route = new Route(['GET'], '/ping', fn() => null);

        $this->assertTrue($route->isStatic());
        $this->assertNull($route->getRegex());
        $this->assertSame([], $route->match('/ping'));
        $this->assertNull($route->match('/pong'));
    }

    public function testDynamicRouteCompilesRegex(): void
    {
        $route = new Route(['GET'], '/users/{id:\d+}', fn() => null);

        $this->assertFalse($route->isStatic());
        $this->assertSame(['id'], $route->getParameters());
        $this->assertSame(['id' => '42'], $route->match('/users/42'));
        $this->assertNull($route->match('/users/abc'));
    }

    public function testOptionalParameterMatchedWhenAbsent(): void
    {
        $route = new Route(['GET'], '/posts/{slug?}', fn() => null);

        $this->assertSame([], $route->match('/posts'));
        $this->assertSame(['slug' => 'hello'], $route->match('/posts/hello'));
    }

    public function testOptionalParameterWithConstraint(): void
    {
        $route = new Route(['GET'], '/page/{page?:\d+}', fn() => null);

        $this->assertSame([], $route->match('/page'));
        $this->assertSame(['page' => '5'], $route->match('/page/5'));
    }

    public function testToUrlSubstitutesParams(): void
    {
        $route = new Route(['GET'], '/users/{id}/posts/{pid}', fn() => null);

        $this->assertSame(
            '/users/3/posts/9',
            $route->toUrl(['id' => 3, 'pid' => 9])
        );
    }

    public function testToUrlUsesDefaults(): void
    {
        $route = new Route(['GET'], '/page/{page?:\d+}', fn() => null);
        $route->defaults(['page' => 1]);

        $this->assertSame('/page/1', $route->toUrl());
    }

    public function testToUrlThrowsOnMissingRequiredParam(): void
    {
        $route = new Route(['GET'], '/users/{id}', fn() => null);

        $this->expectException(\InvalidArgumentException::class);
        $route->toUrl();
    }

    public function testNormalizePathEnsuresLeadingSlash(): void
    {
        $this->assertSame('/ping', Route::normalizePath('ping'));
        $this->assertSame('/ping', Route::normalizePath('/ping/'));
    }

    public function testNameAndMiddlewareFluent(): void
    {
        $route = (new Route(['GET'], '/x', fn() => null))
            ->name('x.show')
            ->middleware('mw1', 'mw2');

        $this->assertSame('x.show', $route->getName());
        $this->assertSame(['mw1', 'mw2'], $route->getMiddlewares());
    }
}
