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
 * ```
 */
final class RequestId implements MiddlewareInterface
{
    public const string HEADER = 'X-Request-Id';

    /**
     * @param string        $header     请求/响应头名称
     * @param callable|null $generator  自定义 ID 生成器，返回字符串
     */
    public function __construct(
        private string $header = self::HEADER,
        private mixed $generator = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $request->getHeaderLine($this->header);
        if ($id === '') {
            $id = $this->generate();
        }

        $request = $request->withAttribute('requestId', $id);

        $response = $handler->handle($request);

        return $response->withHeader($this->header, $id);
    }

    private function generate(): string
    {
        if ($this->generator !== null) {
            return (string) ($this->generator)();
        }

        return bin2hex(random_bytes(12));
    }
}
