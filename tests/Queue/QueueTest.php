<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Queue;

use Kode\Http\Kode;
use Kode\Http\Queue\Queue;
use PHPUnit\Framework\TestCase;

final class QueueTest extends TestCase
{
    protected function setUp(): void
    {
        Kode::reset();
        Queue::clear();
    }

    protected function tearDown(): void
    {
        Queue::reset();
        Kode::reset();
    }

    public function testPushBuffersWithoutDispatching(): void
    {
        Queue::push(SendMailJob::class, ['to' => 'a@x.com']);

        $pending = Queue::pending();
        $this->assertCount(1, $pending);
        $this->assertSame(SendMailJob::class, $pending[0]['job']);
        // 未 flush 前队列为空
        $this->assertSame(0, Queue::manager()->default()->size());
    }

    public function testFlushDispatchesBufferedJobs(): void
    {
        Queue::push(SendMailJob::class, ['to' => 'a@x.com']);
        Queue::push(SendMailJob::class, ['to' => 'b@x.com']);

        $count = Queue::flush();

        $this->assertSame(2, $count);
        $this->assertSame(2, Queue::manager()->default()->size());
        $this->assertSame([], Queue::pending());
    }

    public function testLaterBuffersWithDelay(): void
    {
        Queue::later(30, SendMailJob::class, ['to' => 'c@x.com']);

        $pending = Queue::pending();
        $this->assertSame(30, $pending[0]['delay']);
    }

    public function testBulkBuffersMany(): void
    {
        Queue::bulk([SendMailJob::class, SendMailJob::class], ['to' => 'x@x.com']);

        $this->assertCount(2, Queue::pending());
        $this->assertSame(2, Queue::flush());
    }

    public function testFlushReturnsZeroWhenEmpty(): void
    {
        $this->assertSame(0, Queue::flush());
    }

    public function testBufferedJobsAreIsolatedAcrossContextScopes(): void
    {
        if (!class_exists(\Kode\Context\Context::class)) {
            $this->markTestSkipped('kode/context 未安装');
        }

        \Kode\Context\Context::run(function () {
            Queue::push(SendMailJob::class, ['to' => 'scope-a']);

            \Kode\Context\Context::run(function () {
                Queue::push(SendMailJob::class, ['to' => 'scope-b']);
                $this->assertCount(1, Queue::pending()); // 仅 scope-b
            });

            $this->assertCount(1, Queue::pending()); // 仅 scope-a
        });
    }
}

final class SendMailJob
{
    public function handle(array $data): void
    {
    }
}
