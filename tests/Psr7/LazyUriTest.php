<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Psr7;

use Kode\Http\Psr7\LazyUri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;

class LazyUriTest extends TestCase
{
    public function testImplementsUriInterface(): void
    {
        $this->assertInstanceOf(UriInterface::class, new LazyUri('/path'));
    }

    /**
     * 适配器主路径：new LazyUri($path, $query) 等价旧 new Uri($path)->withQuery($query)
     */
    public function testAdapterStylePathQuery(): void
    {
        $uri = new LazyUri('/api/users', 'page=1&limit=20');
        $this->assertSame('/api/users', $uri->getPath());
        $this->assertSame('page=1&limit=20', $uri->getQuery());
        $this->assertSame('/api/users?page=1&limit=20', (string) $uri);
    }

    public function testEmptyQueryIsEmptyString(): void
    {
        $uri = new LazyUri('/api/users', '');
        $this->assertSame('', $uri->getQuery());
        $this->assertSame('/api/users', (string) $uri);
    }

    public function testDefaultPathIsEmpty(): void
    {
        $uri = new LazyUri();
        $this->assertSame('', $uri->getPath());
        $this->assertSame('', (string) $uri);
    }

    /**
     * 适配器不设置 host/scheme/port —— 应保持与旧 Uri 一致的空值契约
     */
    public function testAdapterStyleHasEmptyAuthority(): void
    {
        $uri = new LazyUri('/api/users', 'a=1');
        $this->assertSame('', $uri->getScheme());
        $this->assertSame('', $uri->getHost());
        $this->assertNull($uri->getPort());
        $this->assertSame('', $uri->getAuthority());
    }

    public function testGettersWithAllComponents(): void
    {
        $uri = new LazyUri(
            '/path',
            'q=1',
            'https',
            'example.com',
            8443,
            'user:pass',
            'frag'
        );
        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('user:pass', $uri->getUserInfo());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame(8443, $uri->getPort());
        $this->assertSame('/path', $uri->getPath());
        $this->assertSame('q=1', $uri->getQuery());
        $this->assertSame('frag', $uri->getFragment());
        $this->assertSame('user:pass@example.com:8443', $uri->getAuthority());
    }

    public function testToStringWithAllComponents(): void
    {
        $uri = new LazyUri('/p', 'q=1', 'https', 'example.com', 8443, 'u:p', 'f');
        $this->assertSame('https://u:p@example.com:8443/p?q=1#f', (string) $uri);
    }

    public function testSchemeLowercased(): void
    {
        $uri = new LazyUri('/', '', 'HTTPS');
        $this->assertSame('https', $uri->getScheme());
    }

    public function testInvalidPortBecomesNull(): void
    {
        $uri = new LazyUri('/', '', 'http', 'h', 70000);
        $this->assertNull($uri->getPort());
    }

    public function testImmutability(): void
    {
        $uri = new LazyUri('/path', 'a=1');
        $new = $uri->withQuery('b=2')->withPath('/other');

        $this->assertSame('/path', $uri->getPath());
        $this->assertSame('a=1', $uri->getQuery());
        $this->assertSame('/other', $new->getPath());
        $this->assertSame('b=2', $new->getQuery());
    }

    public function testWithHostDoesNotMutateOriginal(): void
    {
        $uri = new LazyUri('/path', '', 'http', 'a.com');
        $new = $uri->withHost('b.com');
        $this->assertSame('a.com', $uri->getHost());
        $this->assertSame('b.com', $new->getHost());
    }

    public function testDropInForRequestPathResolution(): void
    {
        // 路由只读 getMethod + getUri()->getPath()，LazyUri 必须等价
        $uri = new LazyUri('/platform/api/v1/member/login', 'token=xyz');
        $this->assertSame('/platform/api/v1/member/login', $uri->getPath());
        $this->assertSame('token=xyz', $uri->getQuery());
    }
}
