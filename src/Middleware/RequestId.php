<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求 ID 中间件
 *
 * 为每一个请求生成（或从请求头复用）唯一 ID，写入请求 attribute 与响应头，
 * 便于链路追踪、日志关联与灰度调试。
 *
 * @example
 * ```php
 * $app->pipe(new RequestId());                       // 默认头 X-Request-Id
 * $app->pipe(new RequestId(header: 'X-Trace-Id'));   // 自定义头
 * $app->pipe(new RequestId(trustClient: false));     // 一律服务端生成，不认客户端传来的 ID
 * ```
 */
final class RequestId implements MiddlewareInterface
{
    public const string HEADER = 'X-Request-Id';

    /**
     * @param string        $header     请求/响应头名称
     * @param callable|null $generator  自定义 ID 生成器，返回字符串
     * @param bool          $trustClient 是否复用客户端送来的同名头（跨服务透传链路 ID 时才有必要）。
     *                                   对外入口应为 false：该值会被访问日志/审计原样当作关联键，
     *                                   信任它等于让调用方伪造日志归属、或用超长/畸形值刷屏。
     * @param int           $maxLength  复用客户端值时的最大长度（字节），超出截断
     */
    public function __construct(
        private string $header = self::HEADER,
        private mixed $generator = null,
        private bool $trustClient = true,
        private int $maxLength = 128,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $this->trustClient ? $this->sanitize($request->getHeaderLine($this->header)) : '';
        if ($id === '') {
            $id = $this->generate();
        }

        $request = $request->withAttribute('requestId', $id);

        $response = $handler->handle($request);

        return $response->withHeader($this->header, $id);
    }

    /**
     * 客户端 ID 只当标识符用：剥掉控制字符与空白（日志注入/换行伪造），再按上限截断。
     */
    private function sanitize(string $id): string
    {
        $id = preg_replace('/[\x00-\x1F\x7F\s]+/', '', $id) ?? '';

        return strlen($id) > $this->maxLength ? substr($id, 0, $this->maxLength) : $id;
    }

    private function generate(): string
    {
        if ($this->generator !== null) {
            return (string) ($this->generator)();
        }

        return bin2hex(random_bytes(12));
    }
}
