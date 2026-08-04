<?php

declare(strict_types=1);

namespace Kode\Http\Tests;

use Kode\Http\Method;
use Kode\Http\Status;
use PHPUnit\Framework\TestCase;

final class EnumTest extends TestCase
{
    public function testStatusPhraseMapping(): void
    {
        $this->assertSame('OK', Status::OK->phrase());
        $this->assertSame('Not Found', Status::NOT_FOUND->phrase());
        $this->assertSame('I\'m a teapot', Status::phraseOf(418));
        $this->assertSame('', Status::phraseOf(799));
    }

    public function testStatusClassification(): void
    {
        $this->assertTrue(Status::OK->isSuccess());
        $this->assertTrue(Status::MOVED_PERMANENTLY->isRedirect());
        $this->assertTrue(Status::NOT_FOUND->isClientError());
        $this->assertTrue(Status::INTERNAL_SERVER_ERROR->isServerError());
        $this->assertTrue(Status::BAD_REQUEST->isError());
        $this->assertTrue(Status::NO_CONTENT->isEmptyBody());
        $this->assertFalse(Status::OK->isEmptyBody());
    }

    public function testMethodNormalize(): void
    {
        $this->assertSame('GET', Method::normalize('get'));
        $this->assertSame('POST', Method::normalize('  Post '));
    }

    public function testMethodSemantics(): void
    {
        $this->assertTrue(Method::GET->isSafe());
        $this->assertFalse(Method::POST->isSafe());
        $this->assertTrue(Method::PUT->isIdempotent());
        $this->assertFalse(Method::POST->isIdempotent());
        $this->assertTrue(Method::POST->hasBody());
        $this->assertFalse(Method::GET->hasBody());
    }

    public function testRoutableConstant(): void
    {
        $this->assertContains('GET', Method::ROUTABLE);
        $this->assertContains('OPTIONS', Method::ROUTABLE);
        $this->assertNotContains('TRACE', Method::ROUTABLE);
    }
}
