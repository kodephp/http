<?php

declare(strict_types=1);

namespace Kode\Http\Tests;

use Kode\Http\Kode;
use PHPUnit\Framework\TestCase;

/**
 * 版本常量防漂移：`Kode::VERSION` 会打印在 dev server 横幅上，落后 composer.json 就是对外报错版本。
 */
final class VersionTest extends TestCase
{
    public function testVersionConstantMatchesComposerManifest(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            $manifest['version'],
            Kode::VERSION,
            'src/Kode.php 的 VERSION 与 composer.json 的 version 不一致，发版时漏改了一处'
        );
        self::assertSame(Kode::VERSION, Kode::version(), 'version() 必须回读同一个常量');
    }
}
