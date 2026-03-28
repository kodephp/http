<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Psr7;

use Kode\Http\Psr7\Stream;
use PHPUnit\Framework\TestCase;

class StreamTest extends TestCase
{
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
        $stream = Stream::create('content');
        $this->assertTrue($stream->isWritable());
    }

    public function testIsSeekable(): void
    {
        $stream = Stream::create('content');
        $this->assertTrue($stream->isSeekable());
    }

    public function testRead(): void
    {
        $stream = Stream::create('Hello World');
        $this->assertEquals('Hello', $stream->read(5));
    }

    public function testReadAfterSeek(): void
    {
        $stream = Stream::create('Hello World');
        $stream->seek(6);
        $this->assertEquals('World', $stream->read(5));
    }

    public function testWrite(): void
    {
        $stream = Stream::create();
        $bytes = $stream->write('Hello');
        $this->assertEquals(5, $bytes);
        $this->assertEquals('Hello', (string) $stream);
    }

    public function testGetContents(): void
    {
        $stream = Stream::create('Hello World');
        $stream->rewind();
        $this->assertEquals('Hello World', $stream->getContents());
    }

    public function testTell(): void
    {
        $stream = Stream::create('Hello World');
        $stream->seek(6);
        $this->assertEquals(6, $stream->tell());
    }

    public function testEof(): void
    {
        $stream = Stream::create('Hi');
        $this->assertFalse($stream->eof());
        $stream->getContents();
        $this->assertTrue($stream->eof());
    }

    public function testRewind(): void
    {
        $stream = Stream::create('Hello');
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
        $stream = Stream::create('Hello');
        $stream->close();
        $this->expectException(\RuntimeException::class);
        $stream->read(1);
    }

    public function testDetach(): void
    {
        $stream = Stream::create('Hello');
        $resource = $stream->detach();
        $this->assertNotNull($resource);
        $this->assertNull($stream->getSize());
    }

    public function testGetMetadata(): void
    {
        $stream = Stream::create('Hello');
        $metadata = $stream->getMetadata();
        $this->assertIsArray($metadata);
    }

    public function testGetMetadataWithKey(): void
    {
        $stream = Stream::create('Hello');
        $uri = $stream->getMetadata('uri');
        $this->assertNotNull($uri);
    }

    public function testDetachedStreamOperations(): void
    {
        $stream = Stream::create('Hello');
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
}