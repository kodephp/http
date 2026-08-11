<?php

declare(strict_types=1);

namespace Kode\Http\Tests\Integration;

use Kode\Http\Integration\QueueMiddleware;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\Message\Response as Psr7Response;
use Kode\Http\Psr7\Stream;
use Kode\Http\Queue\Queue;
use PHPUnit\Framework\TestCase;

final class QueueMiddlewareTest extends TestCase
{
    private QueueMiddleware $middleware;

    protected function setUp(): void
    {
        \Kode\Http\Kode::reset();
        Queue::clear();
        $this->middleware = new QueueMiddleware();
    }

    protected function tearDown(): void
    {
        Queue::reset();
        \Kode\Http\Kode::reset();
    }

    public function testFlushesBufferedJobsAfterHandler(): void
    {
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                // 处理器执行期间收集任务
                Queue::push(SendMailJob::class, ['to' => 'a@x.com']);
                Queue::push(SendMailJob::class, ['to' => 'b@x.com']);

                return new Psr7Response(200, [], Stream::create('ok'));
            }
        };

        $response = $this->middleware->process(new ServerRequest('GET', 'http://x/'), $handler);

        // 响应头回显派发数量
        $this->assertSame('2', $response->getHeaderLine('X-Queue-Dispatched'));
        // 任务已真正派发到队列
        $this->assertSame(2, Queue::manager()->default()->size());
        // 缓冲已清空
        $this->assertSame([], Queue::pending());
    }

    public function testNoJobsDispatchesZero(): void
    {
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Psr7Response(204);
            }
        };

        $response = $this->middleware->process(new ServerRequest('GET', 'http://x/'), $handler);

        $this->assertSame('0', $response->getHeaderLine('X-Queue-Dispatched'));
        $this->assertSame(0, Queue::manager()->default()->size());
    }

    public function testHeaderCanBeDisabled(): void
    {
        $mw = new QueueMiddleware(false);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                Queue::push(SendMailJob::class);

                return new Psr7Response(200);
            }
        };

        $response = $mw->process(new ServerRequest('GET', 'http://x/'), $handler);

        $this->assertFalse($response->hasHeader('X-Queue-Dispatched'));
        $this->assertSame(1, Queue::manager()->default()->size());
    }
}

final class SendMailJob
{
    public function handle(array $data): void
    {
    }
}
