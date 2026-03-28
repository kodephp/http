<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Kode\Http\Psr7\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * CORS 跨域中间件
 *
 * 处理跨域资源共享（CORS）相关的请求和响应。
 * 自动处理 OPTIONS 预检请求，并添加必要的 CORS 响应头。
 *
 * 功能：
 * - 自动处理 OPTIONS 预检请求
 * - 配置允许的来源、方法、头部
 * - 支持凭证（cookies）配置
 * - 支持暴露响应头配置
 * - 支持缓存预检结果（Max-Age）
 *
 * @example
 * ```php
 * // 基础用法
 * $cors = new CorsMiddleware();
 * $pipeline->pipe($cors);
 *
 * // 自定义配置
 * $cors = new CorsMiddleware([
 *     'origin' => ['https://example.com'],
 *     'methods' => ['GET', 'POST', 'PUT', 'DELETE'],
 *     'headers' => ['Content-Type', 'Authorization'],
 *     'credentials' => true,
 *     'max_age' => 86400,
 * ]);
 * ```
 */
class CorsMiddleware implements MiddlewareInterface
{
    /** @var array CORS 配置 */
    private array $config;

    /**
     * 构造函数
     *
     * @param array $config CORS 配置数组
     *                      - origin: 允许的来源，'*' 或域名数组
     *                      - methods: 允许的 HTTP 方法数组
     *                      - headers: 允许的请求头数组
     *                      - expose_headers: 暴露给客户端的响应头数组
     *                      - max_age: 预检请求缓存时间（秒）
     *                      - credentials: 是否允许携带凭证
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'origin' => '*',
            'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],
            'headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'expose_headers' => [],
            'max_age' => 86400,
            'credentials' => false,
        ], $config);
    }

    /**
     * 处理请求
     *
     * @param ServerRequestInterface $request HTTP 请求
     * @param RequestHandlerInterface $handler 下一个处理器
     * @return Response HTTP 响应
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): Response
    {
        // 处理 OPTIONS 预检请求
        if ($request->getMethod() === 'OPTIONS') {
            return $this->createPreflightResponse($request);
        }

        // 处理实际请求，添加 CORS 头
        $response = $handler->handle($request);
        return $this->addCorsHeaders($response, $request);
    }

    /**
     * 创建预检请求响应
     *
     * @param ServerRequestInterface $request 预检请求
     * @return Response 预检响应（204 No Content）
     */
    private function createPreflightResponse(ServerRequestInterface $request): Response
    {
        $response = new Response(204);
        return $this->addCorsHeaders($response, $request);
    }

    /**
     * 添加 CORS 头到响应
     *
     * @param Response $response 原始响应
     * @param ServerRequestInterface $request 请求
     * @return Response 添加 CORS 头后的响应
     */
    private function addCorsHeaders(Response $response, ServerRequestInterface $request): Response
    {
        $origin = $request->getHeaderLine('Origin') ?: $this->config['origin'];

        // 检查 origin 是否在允许列表中
        if (is_array($this->config['origin']) && !in_array($origin, $this->config['origin'])) {
            $origin = $this->config['origin'][0] ?? '*';
        }

        // Access-Control-Allow-Origin
        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);

        // Access-Control-Allow-Methods
        $response = $response->withHeader('Access-Control-Allow-Methods', implode(', ', $this->config['methods']));

        // Access-Control-Allow-Headers
        if (!empty($this->config['headers'])) {
            $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $this->config['headers']));
        }

        // Access-Control-Expose-Headers
        if (!empty($this->config['expose_headers'])) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->config['expose_headers']));
        }

        // Access-Control-Max-Age
        $response = $response->withHeader('Access-Control-Max-Age', (string) $this->config['max_age']);

        // Access-Control-Allow-Credentials
        if ($this->config['credentials']) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * 设置允许的来源
     *
     * @param string|array $origin '*' 或域名数组
     * @return self 支持链式调用
     */
    public function setOrigin(string|array $origin): self
    {
        $this->config['origin'] = $origin;
        return $this;
    }

    /**
     * 设置允许的 HTTP 方法
     *
     * @param array $methods HTTP 方法数组
     * @return self 支持链式调用
     */
    public function setMethods(array $methods): self
    {
        $this->config['methods'] = $methods;
        return $this;
    }

    /**
     * 设置是否允许凭证
     *
     * @param bool $credentials 是否允许携带凭证
     * @return self 支持链式调用
     */
    public function setCredentials(bool $credentials): self
    {
        $this->config['credentials'] = $credentials;
        return $this;
    }
}