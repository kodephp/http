<?php

declare(strict_types=1);

namespace Kode\Http\Psr7\Message;

use Kode\Http\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * HTTP 响应消息类
 *
 * 实现了 PSR-7 ResponseInterface，规范了 HTTP 响应的所有属性，
 * 包括状态码、原因短语、头部、协议版本和消息体。
 *
 * 自 v3.4 起，响应对象为**可变**：所有 with* 方法原地修改自身并返回 `$this`
 * （仿 webman / hyperf），中间件管道中逐层改消息不再产生逐次对象分配。
 * 如需独立快照，请在使用前 clone。
 *
 * @example
 * ```php
 * // 创建成功响应
 * $response = new Response(200, [
 *     'Content-Type' => 'application/json',
 * ], Stream::create('{"message":"success"}'));
 *
 * // 创建错误响应
 * $response = new Response(404)->withStatus(404, 'Not Found');
 * ```
 */
class Response implements ResponseInterface
{
    use \Kode\Http\Psr7\Trait\ResponseTrait;

    /** @var int HTTP 状态码 */
    private int $statusCode = 200;

    /** @var string 原因短语 */
    private string $reasonPhrase = '';

    /** @var string HTTP 协议版本 */
    private string $protocolVersion = '1.1';

    /**
     * HTTP 状态码与原因短语映射表
     *
     * @var array<int, string>
     */
    private static array $reasonPhrases = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    /**
     * 构造函数
     *
     * @param int $statusCode HTTP 状态码，默认 200
     * @param array $headers 响应头部关联数组
     * @param StreamInterface|null $body 响应消息体
     * @param string $protocolVersion HTTP 协议版本，默认为 1.1
     * @param string $reasonPhrase 原因短语，空字符串自动根据状态码获取
     */
    public function __construct(
        int $statusCode = 200,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        string $reasonPhrase = ''
    ) {
        $this->statusCode = $statusCode;
        $this->protocolVersion = $protocolVersion;
        $this->reasonPhrase = $reasonPhrase ?: (self::$reasonPhrases[$statusCode] ?? '');

        $this->initializeHeaders($headers);
        $this->body = $body ?? Stream::create('');
    }

    /**
     * 获取 HTTP 状态码
     *
     * @return int 状态码，如 200、404、500 等
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * 获取原因短语
     *
     * @return string 原因短语，如 "OK"、"Not Found" 等
     */
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * 原地设置状态码与原因短语，并返回自身
     *
     * 自 v3.4 起，消息对象为可变（仿 webman / hyperf）：所有 with* 方法
     * 直接修改自身并返回 `$this`，不再克隆。这样在中间件管道中逐层改消息
     * 时不再产生逐次对象分配。
     *
     * @param int $code 新的状态码
     * @param string $reasonPhrase 新的原因短语，空字符串自动获取
     * @return static 自身
     */
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $this->statusCode = $code;
        $this->reasonPhrase = $reasonPhrase ?: (self::$reasonPhrases[$code] ?? '');
        return $this;
    }

    /**
     * 获取 HTTP 协议版本
     *
     * @return string 协议版本，如 "1.1"、"2.0"
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * 原地设置协议版本，并返回自身
     *
     * @param string $version 新的协议版本
     * @return static 自身
     */
    public function withProtocolVersion(string $version): static
    {
        $this->protocolVersion = $version;
        return $this;
    }

    /**
     * 原地设置消息体，并返回自身
     *
     * @param StreamInterface $body 新的消息体
     * @return static 自身
     */
    public function withBody(StreamInterface $body): static
    {
        $this->body = $body;
        return $this;
    }
}