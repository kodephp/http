<?php

declare(strict_types=1);

namespace Kode\Http\Exception;

use Kode\Exception\HttpException as BaseHttpException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP 异常类
 *
 * 基于 kode/exception 的 HttpException，专门用于 HTTP 错误
 * 支持 PSR-7 请求上下文关联
 */
class HttpException extends BaseHttpException
{
    /** @var ServerRequestInterface|null 关联的请求对象 */
    protected ?ServerRequestInterface $request = null;

    public function __construct(
        int $httpStatusCode,
        string $message = '',
        ?ServerRequestInterface $request = null,
        array $headers = [],
        ?\Throwable $previous = null,
        string $errorCode = 'HTTP_ERROR'
    ) {
        $this->request = $request;
        parent::__construct($httpStatusCode, $message, 0, $previous, $headers, $errorCode);
    }

    public function getRequest(): ?ServerRequestInterface
    {
        return $this->request;
    }

    public function withRequest(ServerRequestInterface $request): self
    {
        $new = clone $this;
        $new->request = $request;
        return $new;
    }

    public static function notFound(?ServerRequestInterface $request = null, string $message = 'Not Found'): self
    {
        return new self(404, $message, $request, [], null, 'NOT_FOUND');
    }

    public static function methodNotAllowed(string $message = 'Method Not Allowed', ?ServerRequestInterface $request = null): self
    {
        return new self(405, $message, $request, [], null, 'METHOD_NOT_ALLOWED');
    }

    public static function badRequest(string $message = 'Bad Request', ?ServerRequestInterface $request = null): self
    {
        return new self(400, $message, $request, [], null, 'BAD_REQUEST');
    }

    public static function internalServerError(string $message = 'Internal Server Error', ?ServerRequestInterface $request = null): self
    {
        return new self(500, $message, $request, [], null, 'INTERNAL_SERVER_ERROR');
    }

    public static function unauthorized(string $message = 'Unauthorized', ?ServerRequestInterface $request = null): self
    {
        return new self(401, $message, $request, [], null, 'UNAUTHORIZED');
    }

    public static function forbidden(string $message = 'Forbidden', ?ServerRequestInterface $request = null): self
    {
        return new self(403, $message, $request, [], null, 'FORBIDDEN');
    }

    public static function serviceUnavailable(string $message = 'Service Unavailable', ?ServerRequestInterface $request = null): self
    {
        return new self(503, $message, $request, [], null, 'SERVICE_UNAVAILABLE');
    }

    public static function tooManyRequests(string $message = 'Too Many Requests', ?ServerRequestInterface $request = null): self
    {
        return new self(429, $message, $request, [], null, 'TOO_MANY_REQUESTS');
    }

    public static function validationFailed(string $message = 'Validation Failed', ?ServerRequestInterface $request = null): self
    {
        return new self(422, $message, $request, [], null, 'VALIDATION_FAILED');
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
