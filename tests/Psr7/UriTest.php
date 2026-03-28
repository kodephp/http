<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Psr7;

use Kode\Http\Psr7\Uri;
use PHPUnit\Framework\TestCase;

class UriTest extends TestCase
{
    public function testEmptyUri(): void
    {
        $uri = new Uri();
        $this->assertEquals('', (string) $uri);
    }

    public function testParseSimpleUri(): void
    {
        $uri = new Uri('https://example.com/path?query=value');
        $this->assertEquals('https', $uri->getScheme());
        $this->assertEquals('example.com', $uri->getHost());
        $this->assertEquals('/path', $uri->getPath());
        $this->assertEquals('query=value', $uri->getQuery());
    }

    public function testGetScheme(): void
    {
        $uri = new Uri('https://example.com');
        $this->assertEquals('https', $uri->getScheme());
    }

    public function testGetSchemeLowercase(): void
    {
        $uri = new Uri('HTTPS://example.com');
        $this->assertEquals('https', $uri->getScheme());
    }

    public function testGetAuthority(): void
    {
        $uri = new Uri('https://user:pass@example.com:8080');
        $this->assertEquals('user:pass@example.com:8080', $uri->getAuthority());
    }

    public function testGetAuthorityWithoutPort(): void
    {
        $uri = new Uri('https://user:pass@example.com');
        $this->assertEquals('user:pass@example.com', $uri->getAuthority());
    }

    public function testGetUserInfo(): void
    {
        $uri = new Uri('https://user:pass@example.com');
        $this->assertEquals('user:pass', $uri->getUserInfo());
    }

    public function testGetHost(): void
    {
        $uri = new Uri('https://example.com');
        $this->assertEquals('example.com', $uri->getHost());
    }

    public function testGetPort(): void
    {
        $uri = new Uri('https://example.com:8080');
        $this->assertEquals(8080, $uri->getPort());
    }

    public function testGetPortNull(): void
    {
        $uri = new Uri('https://example.com');
        $this->assertNull($uri->getPort());
    }

    public function testGetPath(): void
    {
        $uri = new Uri('https://example.com/path/to/resource');
        $this->assertEquals('/path/to/resource', $uri->getPath());
    }

    public function testGetQuery(): void
    {
        $uri = new Uri('https://example.com?foo=bar&baz=qux');
        $this->assertEquals('foo=bar&baz=qux', $uri->getQuery());
    }

    public function testGetFragment(): void
    {
        $uri = new Uri('https://example.com/path#section');
        $this->assertEquals('section', $uri->getFragment());
    }

    public function testWithScheme(): void
    {
        $uri = new Uri('https://example.com');
        $newUri = $uri->withScheme('http');
        $this->assertEquals('http', $newUri->getScheme());
        $this->assertEquals('http://example.com', (string) $newUri);
    }

    public function testWithUserInfo(): void
    {
        $uri = new Uri('https://example.com');
        $newUri = $uri->withUserInfo('user', 'pass');
        $this->assertEquals('user:pass', $newUri->getUserInfo());
    }

    public function testWithHost(): void
    {
        $uri = new Uri('https://example.com');
        $newUri = $uri->withHost('newhost.com');
        $this->assertEquals('newhost.com', $newUri->getHost());
    }

    public function testWithPort(): void
    {
        $uri = new Uri('https://example.com');
        $newUri = $uri->withPort(8080);
        $this->assertEquals(8080, $newUri->getPort());
    }

    public function testWithPortNull(): void
    {
        $uri = new Uri('https://example.com:8080');
        $newUri = $uri->withPort(null);
        $this->assertNull($newUri->getPort());
    }

    public function testWithPath(): void
    {
        $uri = new Uri('https://example.com/old/path');
        $newUri = $uri->withPath('/new/path');
        $this->assertEquals('/new/path', $newUri->getPath());
    }

    public function testWithQuery(): void
    {
        $uri = new Uri('https://example.com?old=value');
        $newUri = $uri->withQuery('new=value');
        $this->assertEquals('new=value', $newUri->getQuery());
    }

    public function testWithFragment(): void
    {
        $uri = new Uri('https://example.com#old');
        $newUri = $uri->withFragment('new');
        $this->assertEquals('new', $newUri->getFragment());
    }

    public function testToString(): void
    {
        $uri = new Uri('https://user:pass@example.com:8080/path?query=value#fragment');
        $expected = 'https://user:pass@example.com:8080/path?query=value#fragment';
        $this->assertEquals($expected, (string) $uri);
    }

    public function testImmutability(): void
    {
        $uri = new Uri('https://example.com');
        $newUri = $uri->withScheme('http');

        $this->assertEquals('https', $uri->getScheme());
        $this->assertEquals('http', $newUri->getScheme());
    }

    public function testInvalidPort(): void
    {
        $uri = new Uri('https://example.com');
        $newUri = $uri->withPort(70000);
        $this->assertNull($newUri->getPort());
    }

    public function testParseUriWithAllParts(): void
    {
        $uriString = 'https://user:password@www.example.com:8443/path/to/page?name=value#section';
        $uri = new Uri($uriString);

        $this->assertEquals('https', $uri->getScheme());
        $this->assertEquals('user:password', $uri->getUserInfo());
        $this->assertEquals('www.example.com', $uri->getHost());
        $this->assertEquals(8443, $uri->getPort());
        $this->assertEquals('/path/to/page', $uri->getPath());
        $this->assertEquals('name=value', $uri->getQuery());
        $this->assertEquals('section', $uri->getFragment());
    }
}