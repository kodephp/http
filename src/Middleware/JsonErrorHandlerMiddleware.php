<?php

declare(strict_types=1);

namespace Kode\Http\Middleware;

use Kode\Exception\KodeException;
use Kode\Http\Exception\HttpException;
use Kode\Http\Psr7\Message\Response;
use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * JSON 错误处理中间件
 *
 * 捕获所有异常并转换为统一的 JSON 格式响应
 * 支持 KodeException 和 HttpException 的错误码体系
 */
class JsonErrorHandlerMiddleware implements MiddlewareInterface
{
    /** @var bool 是否开启调试模式 */
    private bool $debugMode;

    /** @var array<int, callable> 按状态码注册的错误处理器 */
    private array $errorHandlers = [];

    /**
     * 构造函数
     *
     * @param bool $debugMode 是否开启调试模式（显示详细错误信息）
     */
    public function __construct(bool $debugMode = false)
    {
        $this->debugMode = $debugMode;
    }

    /**
     * 处理请求
     *
     * @param ServerRequestInterface $request PSR-7 请求对象
     * @param RequestHandlerInterface $handler 请求处理器
     * @return Response PSR-7 响应对象
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): Response
    {
        try {
            $response = $handler->handle($request);

            if ($response->getStatusCode() >= 400) {
                return $this->handleErrorResponse($response);
            }

            return $this->ensureJsonContentType($response);
        } catch (\Throwable $e) {
            return $this->handleException($e, $request);
        }
    }

    /**
     * 处理异常
     *
     * @param \Throwable $e 异常对象
     * @param ServerRequestInterface $request 请求对象
     * @return Response JSON 格式的错误响应
     */
    private function handleException(\Throwable $e, ServerRequestInterface $request): Response
    {
        $statusCode = 500;
        $errorCode = 'E1500';
        $message = 'Internal Server Error';
        $errorType = 'server_error';

        if ($e instanceof HttpException) {
            $statusCode = $e->getHttpStatusCode();
            $errorCode = $e->getErrorCode();
            $message = $e->getErrorMsg();
            $errorType = 'http_error';
        } elseif ($e instanceof KodeException) {
            $statusCode = $e->getHttpStatusCode();
            $errorCode = $e->getErrorCode();
            $message = $e->getErrorMsg();
            $errorType = $e->getErrorType();
        } elseif ($e instanceof \InvalidArgumentException) {
            $statusCode = 400;
            $errorCode = 'E1001';
            $message = $e->getMessage();
            $errorType = 'validation_error';
        }

        if (isset($this->errorHandlers[$statusCode])) {
            return ($this->errorHandlers[$statusCode])($e, $request);
        }

        $errorData = [
            'success' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $this->debugMode ? $e->getMessage() : $message,
                'type' => $errorType,
            ],
        ];

        if ($e instanceof KodeException) {
            $errorData['error']['trace_id'] = $e->getTraceId();
        }

        if ($this->debugMode) {
            $errorData['error']['exception'] = get_class($e);
            $errorData['error']['file'] = $e->getFile();
            $errorData['error']['line'] = $e->getLine();
            $errorData['error']['trace'] = $e->getTraceAsString();
        }

        return new Response(
            $statusCode,
            ['Content-Type' => 'application/json'],
            Stream::create(json_encode($errorData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        );
    }

    /**
     * 处理错误响应
     *
     * @param Response $response 错误响应
     * @return Response JSON 格式的错误响应
     */
    private function handleErrorResponse(Response $response): Response
    {
        if ($this->isJsonContentType($response)) {
            return $response;
        }

        $body = (string) $response->getBody();

        $errorData = [
            'success' => false,
            'error' => [
                'code' => 'E' . $response->getStatusCode(),
                'message' => $body ?: 'An error occurred',
            ],
        ];

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create(json_encode($errorData, JSON_UNESCAPED_UNICODE)));
    }

    /**
     * 确保 JSON Content-Type
     *
     * @param Response $response 响应对象
     * @return Response 带有 JSON Content-Type 的响应
     */
    private function ensureJsonContentType(Response $response): Response
    {
        if ($this->isJsonContentType($response)) {
            return $response;
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * 检查是否为 JSON Content-Type
     *
     * @param Response $response 响应对象
     * @return bool 是否为 JSON 类型
     */
    private function isJsonContentType(Response $response): bool
    {
        $contentType = $response->getHeaderLine('Content-Type');
        return str_contains(strtolower($contentType), 'application/json');
    }

    /**
     * 注册特定状态码的错误处理器
     *
     * @param int $statusCode HTTP 状态码
     * @param callable $handler 处理器函数，参数为 (Throwable $e, ServerRequestInterface $request)
     * @return self 支持链式调用
     */
    public function onError(int $statusCode, callable $handler): self
    {
        $this->errorHandlers[$statusCode] = $handler;
        return $this;
    }

    /**
     * 设置调试模式
     *
     * @param bool $debugMode 是否开启调试模式
     * @return self 支持链式调用
     */
    public function setDebugMode(bool $debugMode): self
    {
        $this->debugMode = $debugMode;
        return $this;
    }
}
