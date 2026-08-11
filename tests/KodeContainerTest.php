<?php

declare(strict_types=1);

namespace Kode\Http\Tests;

use Kode\Http\Kode;
use Kode\Http\Support\ServiceFacade;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

final class KodeContainerTest extends TestCase
{
    protected function setUp(): void
    {
        Kode::reset();
    }

    public function testRegisterAndService(): void
    {
        $svc = new \stdClass();
        Kode::register('demo', $svc);

        $this->assertTrue(Kode::hasService('demo'));
        $this->assertSame($svc, Kode::service('demo'));
    }

    public function testPsr11GetAndHas(): void
    {
        $svc = new \stdClass();
        Kode::register('demo', $svc);

        $this->assertTrue(Kode::container()->has('demo'));
        $this->assertSame($svc, Kode::container()->get('demo'));
    }

    public function testPsr11GetThrowsWhenMissing(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        Kode::container()->get('nope');
    }

    public function testEnableFacadesWiresContainer(): void
    {
        if (!class_exists(\Kode\Facade\FacadeProxy::class)) {
            $this->markTestSkipped('kode/facade 未安装');
        }

        Kode::register('greeter', new class {
            public function hello(string $name): string
            {
                return "hi $name";
            }
        });

        Kode::enableFacades();

        $greeter = \Kode\Facade\FacadeProxy::getInstance(GreeterFacade::class);
        $this->assertSame('hi world', $greeter->hello('world'));

        Kode::reset();
    }

    public function testServiceFacadeResolvesViaKodeContainer(): void
    {
        if (!class_exists(ServiceFacade::class)) {
            $this->markTestSkipped('kode/facade 未安装');
        }

        Kode::register('cache', new class {
            public function get(string $k): string
            {
                return "v:$k";
            }
        });

        Kode::enableFacades();
        Kode::bindFacade(CacheFacade::class, 'cache');

        $this->assertSame('v:foo', CacheFacade::get('foo'));

        Kode::reset();
    }
}

final class GreeterFacade extends ServiceFacade
{
    protected static function id(): string
    {
        return 'greeter';
    }
}

final class CacheFacade extends ServiceFacade
{
    protected static function id(): string
    {
        return 'cache';
    }
}
