<?php

declare(strict_types=1);

namespace Kode\Http\Server;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 服务端运行器
 *
 * 统一的服务端运行器，自动检测并适配不同的运行环境。
 * 支持 FPM、CLI、Swoole、Workerman 等多种运行模式。
 *
 * 使用方式：
 * 1. 创建 ServerRunner 实例（自动检测环境）
 * 2. 调用 run() 方法并传入请求处理器
 *
 * @example
 * ```php
 * $runner = new ServerRunner();
 * $runner->run(function ($request) {
 *     return new Response(200, [], Stream::create('Hello'));
 * });
 * ```
 */
class ServerRunner
{
    /** @var string 运行环境适配器名称 */
    private string $adapter;

    /**
     * 构造函数
     *
     * @param string $adapter 指定运行环境，可选值：'auto'、'swoole'、'workerman'、'fpm'
     *                       默认为 'auto'，自动检测当前环境
     */
    public function __construct(string $adapter = 'auto')
    {
        $this->adapter = $this->detectAdapter($adapter);
    }

    /**
     * 检测运行环境适配器
     *
     * 按优先级检测：Swoole > Workerman > FPM
     *
     * @param string $adapter 用户指定的适配器
     * @return string 检测到的适配器名称
     */
    private function detectAdapter(string $adapter): string
    {
        if ($adapter !== 'auto') {
            return $adapter;
        }

        if (extension_loaded('swoole')) {
            return 'swoole';
        }

        if (class_exists(\Workerman\Worker::class)) {
            return 'workerman';
        }

        return 'fpm';
    }

    /**
     * 运行服务器
     *
     * 根据检测到的运行环境，启动相应的服务器。
     *
     * @param callable $handler 请求处理器，接受 ServerRequestInterface，返回 ResponseInterface
     * @throws \RuntimeException 当运行环境不支持时抛出异常
     *
     * @example
     * ```php
     * $runner->run(function ($request) {
     *     return new Response(200, [], Stream::create('Hello World'));
     * });
     * ```
     */
    public function run(callable $handler): void
    {
        match ($this->adapter) {
            'swoole' => $this->runSwoole($handler),
            'workerman' => $this->runWorkerman($handler),
            default => $this->runFpm($handler),
        };
    }

    /**
     * 使用 Swoole 运行
     *
     * @param callable $handler 请求处理器
     */
    private function runSwoole(callable $handler): void
    {
        $adapter = new SwooleServerAdapter($handler);
        $adapter->run();
    }

    /**
     * 使用 Workerman 运行
     *
     * @param callable $handler 请求处理器
     */
    private function runWorkerman(callable $handler): void
    {
        $adapter = new WorkermanServerAdapter($handler);
        $adapter->run();
    }

    /**
     * 使用 FPM/CLI 运行
     *
     * 从全局变量（$_SERVER、php://input）创建请求，
     * 处理完成后发送响应。
     *
     * @param callable $handler 请求处理器
     */
    private function runFpm(callable $handler): void
    {
        $request = $this->createServerRequestFromGlobals();
        $response = $handler($request);
        $this->emitResponse($response);
    }

    /**
     * 从全局变量创建 ServerRequest
     *
     * 从 $_SERVER、php://input 等全局变量构建 PSR-7 ServerRequest 对象。
     *
     * @return ServerRequestInterface PSR-7 服务端请求对象
     */
    private function createServerRequestFromGlobals(): ServerRequestInterface
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = new \Kode\Http\Psr7\Uri($_SERVER['REQUEST_URI'] ?? '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('_', '-', substr($key, 5));
                $headers[$header] = [$value];
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = [$_SERVER['CONTENT_TYPE']];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = [$_SERVER['CONTENT_LENGTH']];
        }

        $body = \Kode\Http\Psr7\Stream::create(file_get_contents('php://input') ?: '');

        return new \Kode\Http\Psr7\Message\ServerRequest(
            $method,
            $uri,
            $_SERVER,
            $headers,
            $body
        );
    }

    /**
     * 发送 HTTP 响应
     *
     * 设置响应状态码、头部，并输出响应体。
     *
     * @param ResponseInterface $response PSR-7 响应对象
     */
    private function emitResponse(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        header('HTTP/' . $response->getProtocolVersion() . ' ' . $status . ' ' . $response->getReasonPhrase());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value);
            }
        }

        echo $response->getBody()->getContents();
    }

    /**
     * 获取当前适配器名称
     *
     * @return string 适配器名称：'swoole'、'workerman'、'fpm'
     */
    public function getAdapter(): string
    {
        return $this->adapter;
    }

    /**
     * 检查是否为指定环境
     *
     * @param string $adapter 适配器名称
     * @return bool 如果当前环境匹配返回 true
     */
    public function isAdapter(string $adapter): bool
    {
        return $this->adapter === $adapter;
    }
}