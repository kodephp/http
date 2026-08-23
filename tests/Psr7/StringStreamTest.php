<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Psr7;

use Kode\Http\Psr7\Stream;
use Kode\Http\Psr7\StringStream;
use PHPUnit\Framework\TestCase;

class StringStreamTest extends TestCase
{
    public function testImplementsStreamInterface(): void
    {
        $stream = new StringStream('hello');
        $this->assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $stream);
    }

    public function testToString(): void
    {
        $stream = new StringStream('Hello World');
        $this->assertEquals('Hello World', (string) $stream);
    }

    public function testGetContents(): void
    {
        $stream = new StringStream('{"code":1,"data":[1,2,3]}');
        $this->assertEquals('{"code":1,"data":[1,2,3]}', $stream->getContents());
    }

    public function testGetSize(): void
    {
        $stream = new StringStream('Hello');
        $this->assertEquals(5, $stream->getSize());
    }

    public function testGetSizeEmpty(): void
    {
        $stream = new StringStream('');
        $this->assertEquals(0, $stream->getSize());
    }

    public function testIsReadable(): void
    {
        $stream = new StringStream('content');
        $this->assertTrue($stream->isReadable());
    }

    public function testIsWritable(): void
    {
        $stream = new StringStream('content');
        $this->assertFalse($stream->isWritable());
    }

    public function testIsSeekable(): void
    {
        $stream = new StringStream('content');
        $this->assertFalse($stream->isSeekable());
    }

    public function testEof(): void
    {
        $stream = new StringStream('content');
        $this->assertTrue($stream->eof());
    }

    public function testRead(): void
    {
        $stream = new StringStream('Hello World');
        // StringStream::read() 返回全部内容（无指针概念）
        $this->assertEquals('Hello World', $stream->read(5));
    }

    public function testWriteThrows(): void
    {
        $stream = new StringStream('content');
        $this->expectException(\RuntimeException::class);
        $stream->write('data');
    }

    public function testSeekThrows(): void
    {
        $stream = new StringStream('content');
        $this->expectException(\RuntimeException::class);
        $stream->seek(0);
    }

    public function testRewindThrows(): void
    {
        $stream = new StringStream('content');
        $this->expectException(\RuntimeException::class);
        $stream->rewind();
    }

    public function testTell(): void
    {
        $stream = new StringStream('Hello');
        // tell() 返回内容长度（与指针概念对齐：全部已读）
        $this->assertEquals(5, $stream->tell());
    }

    public function testClose(): void
    {
        $stream = new StringStream('Hello');
        $stream->close();
        $this->assertEquals('', $stream->getContents());
        $this->assertEquals(0, $stream->getSize());
    }

    public function testDetachReturnsNull(): void
    {
        $stream = new StringStream('Hello');
        $result = $stream->detach();
        $this->assertNull($result);
        $this->assertEquals(0, $stream->getSize());
    }

    public function testGetMetadata(): void
    {
        $stream = new StringStream('Hello');
        $metadata = $stream->getMetadata();
        $this->assertIsArray($metadata);
        $this->assertEquals('string', $metadata['stream_type']);
        $this->assertFalse($metadata['seekable']);
    }

    public function testGetMetadataWithKey(): void
    {
        $stream = new StringStream('Hello');
        $this->assertEquals('string', $stream->getMetadata('stream_type'));
        $this->assertNull($stream->getMetadata('nonexistent_key'));
    }

    public function testStreamCreateReturnsStringStreamForSmallContent(): void
    {
        $stream = Stream::create('hello');
        $this->assertInstanceOf(StringStream::class, $stream);
    }

    public function testStreamCreateReturnsStringStreamForEmptyString(): void
    {
        $stream = Stream::create();
        $this->assertInstanceOf(StringStream::class, $stream);
        $this->assertEquals('', $stream->getContents());
    }

    public function testStreamCreateReturnsStreamForLargeContent(): void
    {
        $large = str_repeat('x', 1_048_577);
        $stream = Stream::create($large);
        $this->assertInstanceOf(Stream::class, $stream);
        $this->assertNotInstanceOf(StringStream::class, $stream);
    }

    public function testResponseBodyRoundTrip(): void
    {
        // 模拟 Response::json() → getBody() → getContents() 链路
        $json = '{"status":"ok","data":[1,2,3]}';
        $stream = Stream::create($json);
        $this->assertInstanceOf(StringStream::class, $stream);
        $this->assertEquals($json, $stream->getContents());
        $this->assertEquals($json, (string) $stream);
        $this->assertEquals(strlen($json), $stream->getSize());
    }
}
