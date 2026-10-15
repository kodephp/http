<?php

declare(strict_types=1);

namespace Kode\Http\Exception;

use Kode\Exception\Enum\ErrorCode;
use Kode\Exception\Enum\ErrorType;
use Kode\Exception\KodeException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP 异常类
 *
 * 基于 kode/exception 的 KodeException，专门用于 HTTP 错误
 * 支持 PSR-7 请求上下文关联
 *
 * 设计约束（勿破坏）：
 *  - 静态工厂签名与父类 KodeException 保持可覆写兼容（message, context, previous）
 *    之后才追加可选的 $request——否则触发 PHP 继承 "must be compatible" 致命错误；
 *  - Throwable 子类在 PHP 8.5+ 不可 clone（全局禁止），不可变派生一律走
 *    {@see KodeException::replicate()}（new 重建）；本类覆写 replicate 以携带
 *    $request 字段，withContext/withHttpStatus 等父类派生方法对 HttpException
 *    同样保真；
 *  - 构造器前五参与父类逐位对齐（replicate 按位置传参），$headers/$request
 *    以可选尾参追加。
 */
class HttpException extends KodeException
{
    /** @var ServerRequestInterface|null 关联的请求对象 */
    protected ?ServerRequestInterface $request = null;

    public function __construct(
        ErrorCode|string $errorCode,
        ?string $errorMsg = null,
        ?\Throwable $previous = null,
        ErrorType|string|null $errorType = null,
        #[\SensitiveParameter] array $context = [],
        array $headers = [],
        ?ServerRequestInterface $request = null
    ) {
        parent::__construct($errorCode, $errorMsg, $previous, $errorType ?? self::TYPE_HTTP, $context);
        $this->request = $request;
        if ($headers !== []) {
            $this->errorContext = array_merge($this->errorContext, ['_http_headers' => $headers]);
        }
    }

    protected function replicate(): static
    {
        $clone = parent::replicate();
        $clone->request = $this->request;
        return $clone;
    }

    public function getRequest(): ?ServerRequestInterface
    {
        return $this->request;
    }

    public function getHeaders(): array
    {
        return $this->errorContext['_http_headers'] ?? [];
    }

    public function withRequest(ServerRequestInterface $request): static
    {
        $new = $this->replicate();
        $new->request = $request;
        return $new;
    }

    public function withHeaders(array $headers): static
    {
        $new = $this->replicate();
        $new->errorContext = array_merge($this->errorContext, ['_http_headers' => $headers]);
        return $new;
    }

    public static function notFound(string $message = 'Not Found', array $context = [], ?\Throwable $previous = null, ?ServerRequestInterface $request = null): static
    {
        return new static(self::CODE_NOT_FOUND, $message, $previous, self::TYPE_HTTP, $context, [], $request);
    }

    public static function methodNotAllowed(string $message = 'Method Not Allowed', array $context = [], ?\Throwable $previous = null, ?ServerRequestInterface $request = null): static
    {
        return new static(self::CODE_METHOD_NOT_ALLOWED, $message, $previous, self::TYPE_HTTP, $context, [], $request);
    }

    public static function badRequest(string $message = 'Bad Request', array $context = [], ?\Throwable $previous = null, ?ServerRequestInterface $request = null): static
    {
        return new static(self::CODE_BAD_REQUEST, $message, $previous, self::TYPE_HTTP, $context, [], $request);
    }

    public static function internalServerError(string $message = 'Internal Server Error', array $context = [], ?\Throwable $previous = null, ?ServerRequestInterface $request = null): static
    {
        return new static(self::CODE_INTERNAL_ERROR, $message, $previous, self::TYPE_HTTP, $context, [], $request);
    }

    public static function unauthorized(string $message = 'Unauthorized', array $context = [], ?\Throwable $previous = null, ?ServerRequestInterface $request = null): static
    {
        return new static(self::CODE_UNAUTHORIZED, $message, $previous, self::TYPE_HTTP, $context, [], $request);
    }

    public static function forbidden(string $message = 'Forbidden', array $context = [], ?\Throwable $previous = null, ?ServerRequestInterface $request = null): static
    {
        return new static(self::CODE_FORBIDDEN, $message, $previous, self::TYPE_HTTP, $context, [], $request);
    }

    public static function serviceUnavailable(string $message = 'Service Unavailable', array $context = [], ?\Throwable $previous = null, ?ServerRequestInterface $request = null): static
    {
        return new static(self::CODE_SERVICE_UNAVAILABLE, $message, $previous, self::TYPE_HTTP, $context, [], $request);
    }

    public static function tooManyRequests(string $message = 'Too Many Requests', array $context = [], ?\Throwable $previous = null, ?ServerRequestInterface $request = null): static
    {
        return new static(self::CODE_TOO_MANY_REQUESTS, $message, $previous, self::TYPE_HTTP, $context, [], $request);
    }

    public static function validationFailed(string $message = 'Validation Failed', array $context = [], ?\Throwable $previous = null, ?ServerRequestInterface $request = null): static
    {
        return new static(self::CODE_VALIDATION_FAILED, $message, $previous, self::TYPE_HTTP, $context, [], $request);
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        if ($this->request !== null) {
            $data['request'] = [
                'method' => $this->request->getMethod(),
                'uri' => (string) $this->request->getUri(),
            ];
        }
        return $data;
    }
}
