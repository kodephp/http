<?php

declare(strict_types=1);

namespace Kode\Http\Exception;

use Kode\Exception\KodeException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP 异常类
 *
 * 基于 kode/exception 的 KodeException，专门用于 HTTP 错误
 * 支持 PSR-7 请求上下文关联
 *
 * @method static self bad(string $msg = '请求参数错误', array $context = [], ?\Throwable $previous = null)
 * @method static self auth(string $msg = '未授权', array $context = [], ?\Throwable $previous = null)
 * @method static self deny(string $msg = '禁止访问', array $context = [], ?\Throwable $previous = null)
 * @method static self notFound(string $msg = '资源不存在', array $context = [], ?\Throwable $previous = null)
 * @method static self invalid(string $msg = '验证失败', array $context = [], ?\Throwable $previous = null)
 * @method static self frequent(string $msg = '请求过于频繁', array $context = [], ?\Throwable $previous = null)
 * @method static self error(string $msg = '服务器错误', array $context = [], ?\Throwable $previous = null)
 * @method static self unavailable(string $msg = '服务不可用', array $context = [], ?\Throwable $previous = null)
 */
class HttpException extends KodeException
{
    /** @var ServerRequestInterface|null 关联的请求对象 */
    protected ?ServerRequestInterface $request = null;

    public function __construct(
        string $errorCode,
        string $errorMsg,
        ?ServerRequestInterface $request = null,
        array $headers = [],
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($errorCode, $errorMsg, $previous, self::TYPE_HTTP, $context);
        $this->request = $request;
        if (!empty($headers)) {
            $new = clone $this;
            $new->errorContext = array_merge($new->errorContext, ['_http_headers' => $headers]);
        }
    }

    public function getRequest(): ?ServerRequestInterface
    {
        return $this->request;
    }

    public function getHeaders(): array
    {
        return $this->errorContext['_http_headers'] ?? [];
    }

    public function withRequest(ServerRequestInterface $request): self
    {
        $new = clone $this;
        $new->request = $request;
        return $new;
    }

    public function withHeaders(array $headers): self
    {
        $new = clone $this;
        $new->errorContext = array_merge($this->errorContext, ['_http_headers' => $headers]);
        return $new;
    }

    public static function notFound(?ServerRequestInterface $request = null, string $message = 'Not Found'): self
    {
        return new self(self::CODE_NOT_FOUND, $message, $request);
    }

    public static function methodNotAllowed(string $message = 'Method Not Allowed', ?ServerRequestInterface $request = null): self
    {
        return new self(self::CODE_METHOD_NOT_ALLOWED, $message, $request);
    }

    public static function badRequest(string $message = 'Bad Request', ?ServerRequestInterface $request = null): self
    {
        return new self(self::CODE_BAD_REQUEST, $message, $request);
    }

    public static function internalServerError(string $message = 'Internal Server Error', ?ServerRequestInterface $request = null): self
    {
        return new self(self::CODE_INTERNAL_ERROR, $message, $request);
    }

    public static function unauthorized(string $message = 'Unauthorized', ?ServerRequestInterface $request = null): self
    {
        return new self(self::CODE_UNAUTHORIZED, $message, $request);
    }

    public static function forbidden(string $message = 'Forbidden', ?ServerRequestInterface $request = null): self
    {
        return new self(self::CODE_FORBIDDEN, $message, $request);
    }

    public static function serviceUnavailable(string $message = 'Service Unavailable', ?ServerRequestInterface $request = null): self
    {
        return new self(self::CODE_SERVICE_UNAVAILABLE, $message, $request);
    }

    public static function tooManyRequests(string $message = 'Too Many Requests', ?ServerRequestInterface $request = null): self
    {
        return new self(self::CODE_TOO_MANY_REQUESTS, $message, $request);
    }

    public static function validationFailed(string $message = 'Validation Failed', ?ServerRequestInterface $request = null): self
    {
        return new self(self::CODE_VALIDATION_FAILED, $message, $request);
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
