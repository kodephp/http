<?php

declare(strict_types=1);

namespace Kode\Http\Psr7\Factory;

use Kode\Http\Psr7\Message\LazyServerRequest;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Psr7\UploadedFile;
use Kode\Http\Psr7\Uri;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-17 服务端请求工厂
 *
 * 除标准工厂方法外，新增 {@see self::fromGlobals()}，
 * 可在 PHP-FPM / CLI Server 场景下直接由超全局变量构建 PSR-7 请求。
 */
class ServerRequestFactory implements ServerRequestFactoryInterface
{
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        $uri = $uri instanceof UriInterface ? $uri : new Uri((string) $uri);

        return new ServerRequest($method, $uri, $serverParams);
    }

    /**
     * 从 PHP 超全局变量构建请求
     *
     * @param array<string, mixed>|null $server $_SERVER
     * @param array<string, mixed>|null $query $_GET
     * @param array<string, mixed>|null $body $_POST
     * @param array<string, mixed>|null $cookies $_COOKIE
     * @param array<string, mixed>|null $files $_FILES
     */
    public static function fromGlobals(
        ?array $server = null,
        ?array $query = null,
        ?array $body = null,
        ?array $cookies = null,
        ?array $files = null,
    ): ServerRequestInterface {
        $server ??= $_SERVER;
        $query ??= $_GET;
        $cookies ??= $_COOKIE;
        $files ??= $_FILES;

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = self::uriFromServer($server);

        $rawBody = file_get_contents('php://input');
        $bodyString = $rawBody === false ? '' : $rawBody;

        $contentType = strtolower($server['CONTENT_TYPE'] ?? '');
        if ($body === null) {
            $body = $_POST;
            if ($body === [] && str_contains($contentType, 'application/json') && $rawBody !== false && $rawBody !== '') {
                $decoded = json_validate($rawBody) ? json_decode($rawBody, true) : null;
                $body = is_array($decoded) ? $decoded : [];
            }
        }

        $protocol = str_replace('HTTP/', '', (string) ($server['SERVER_PROTOCOL'] ?? 'HTTP/1.1'));

        $request = new LazyServerRequest($method, $uri, $server, [], $bodyString, $protocol);

        return $request
            ->withQueryParams($query)
            ->withCookieParams($cookies)
            ->withUploadedFiles(UploadedFile::normalize($files))
            ->withParsedBody($body === [] ? null : $body)
            ->withAttribute('request_time', (float) ($server['REQUEST_TIME_FLOAT'] ?? microtime(true)));
    }

    /**
     * 从 $_SERVER 还原完整 URI
     *
     * @param array<string, mixed> $server
     */
    private static function uriFromServer(array $server): UriInterface
    {
        $https = (string) ($server['HTTPS'] ?? '');
        $scheme = ($https !== '' && strtolower($https) !== 'off') || (int) ($server['SERVER_PORT'] ?? 80) === 443
            ? 'https'
            : 'http';

        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? $server['SERVER_ADDR'] ?? 'localhost');
        $port = null;
        if (str_contains($host, ':')) {
            [$host, $portPart] = explode(':', $host, 2);
            $port = (int) $portPart;
        } elseif (isset($server['SERVER_PORT'])) {
            $port = (int) $server['SERVER_PORT'];
        }

        $target = (string) ($server['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($target, PHP_URL_PATH) ?: '/');
        $queryString = (string) ($server['QUERY_STRING'] ?? parse_url($target, PHP_URL_QUERY) ?: '');

        $uri = (new Uri())
            ->withScheme($scheme)
            ->withHost($host)
            ->withPath($path);

        if ($queryString !== '') {
            $uri = $uri->withQuery($queryString);
        }

        if ($port !== null && $port !== 0 && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $uri = $uri->withPort($port);
        }

        return $uri;
    }
}
