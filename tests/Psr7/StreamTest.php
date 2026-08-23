<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Psr7;

use Kode\Http\Psr7\Stream;
use PHPUnit\Framework\TestCase;

class StreamTest extends TestCase
{
    /**
     * 通过 fopen + 构造函数直接创建 Stream（不走 create() 快路径），
     * 用于测试 Stream 特有的可写/可定位行为。
     */
    private function createFileStream(string $content = ''): Stream
    {
        $resource = fopen('php://temp', 'r+');
        if ($content !== '') {
            fwrite($resource, $content);
            fseek($resource, 0);
        }
        return new Stream($resource, 'r+');
    }

    public function testCreateFromString(): void
    {
        $stream = Stream::create('Hello World');
        $this->assertEquals('Hello World', (string) $stream);
    }

    public function testCreateEmptyStream(): void
    {
        $stream = Stream::create();
        $this->assertEquals('', (string) $stream);
    }

    public function testIsReadable(): void
    {
        $stream = Stream::create('content');
        $this->assertTrue($stream->isReadable());
    }

    public function testIsWritable(): void
    {
        $stream = $this->createFileStream('content');
        $this->assertTrue($stream->isWritable());
    }

    public function testIsSeekable(): void
    {
        $stream = $this->createFileStream('content');
        $this->assertTrue($stream->isSeekable());
    }

    public function testRead(): void
    {
        $stream = $this->createFileStream('Hello World');
        $this->assertEquals('Hello', $stream->read(5));
    }

    public function testReadAfterSeek(): void
    {
        $stream = $this->createFileStream('Hello World');
        $stream->seek(6);
        $this->assertEquals('World', $stream->read(5));
    }

    public function testWrite(): void
    {
        $stream = $this->createFileStream();
        $bytes = $stream->write('Hello');
        $this->assertEquals(5, $bytes);
        $this->assertEquals('Hello', (string) $stream);
    }

    public function testGetContents(): void
    {
        $stream = $this->createFileStream('Hello World');
        $stream->rewind();
        $this->assertEquals('Hello World', $stream->getContents());
    }

    public function testTell(): void
    {
        $stream = $this->createFileStream('Hello World');
        $stream->seek(6);
        $this->assertEquals(6, $stream->tell());
    }

    public function testEof(): void
    {
        $stream = $this->createFileStream('Hi');
        $this->assertFalse($stream->eof());
        $stream->getContents();
        $this->assertTrue($stream->eof());
    }

    public function testRewind(): void
    {
        $stream = $this->createFileStream('Hello');
        $stream->seek(3);
        $stream->rewind();
        $this->assertEquals(0, $stream->tell());
        $this->assertEquals('Hello', $stream->getContents());
    }

    public function testGetSize(): void
    {
        $stream = Stream::create('Hello World');
        $this->assertEquals(11, $stream->getSize());
    }

    public function testClose(): void
    {
        $stream = $this->createFileStream('Hello');
        $stream->close();
        $this->expectException(\RuntimeException::class);
        $stream->read(1);
    }

    public function testDetach(): void
    {
        $stream = $this->createFileStream('Hello');
        $resource = $stream->detach();
        $this->assertNotNull($resource);
        $this->assertNull($stream->getSize());
    }

    public function testGetMetadata(): void
    {
        $stream = $this->createFileStream('Hello');
        $metadata = $stream->getMetadata();
        $this->assertIsArray($metadata);
    }

    public function testGetMetadataWithKey(): void
    {
        $stream = $this->createFileStream('Hello');
        $uri = $stream->getMetadata('uri');
        $this->assertNotNull($uri);
    }

    public function testDetachedStreamOperations(): void
    {
        $stream = $this->createFileStream('Hello');
        $stream->detach();

        $this->assertTrue($stream->eof());
        $this->assertNull($stream->getSize());
        $this->assertEquals([], $stream->getMetadata());
    }

    public function testCreateFromFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, 'File Content');

        $stream = Stream::createFromFile($tmpFile);
        $this->assertEquals('File Content', $stream->getContents());

        unlink($tmpFile);
    }

    public function testCreateFromResource(): void
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, 'Resource Content');
        fseek($resource, 0);

        $stream = Stream::createFromResource($resource);
        $this->assertEquals('Resource Content', $stream->getContents());
    }

    public function testCreateLargeContentReturnsStream(): void
    {
        // 超过 1MB 仍返回 Stream（php://temp 落盘路径）
        $large = str_repeat('x', 1_048_577);
        $stream = Stream::create($large);
        $this->assertInstanceOf(Stream::class, $stream);
        $this->assertEquals(1_048_577, $stream->getSize());
    }
}
