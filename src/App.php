<?php

declare(strict_types=1);

namespace Kode\Http;

use Kode\Http\Middleware\JsonErrorHandlerMiddleware;
use Kode\Http\Middleware\MiddlewareDispatcher;
use Kode\Http\Routing\Route;
use Kode\Http\Routing\Router;
use Kode\Http\Routing\RouteRunner;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP 应用构建器
 *
 * 组合「路由器 + 全局中间件管道 + 路由执行器」，提供简洁的应用构建方式。
 *
 * v3.0 关键改进：
 * - 路由匹配交由 {@see Router}，静态路由 O(1) 命中；
 * - 未匹配返回标准 404 / 405（旧版本会无限递归导致内存耗尽）；
 * - 路由组中间件仅作用于组内路由（旧版本会污染全局）；
 * - 管道无状态，可在协程并发环境安全复用。
 *
 * @example
 * ```php
 * $app = App::create(debug: true);
 *
 * $app->get('/api/users/{id:\d+}', fn($req) => ['id' => $req->getAttribute('id')])
 *     ->name('user.show');
 *
 * $app->group('/admin', function (App $app) {
 *     $app->get('/stats', fn() => ['ok' => true]);
 * }, [$authMiddleware]);
 *
 * $app->run();
 * ```
 */
class App implements RequestHandlerInterface
{
    /** @var Router 路由器 */
    protected Router $router;

    /** @var RouteRunner 路由执行器（管道最终处理器） */
    protected RouteRunner $runner;

    /** @var MiddlewareDispatcher 全局中间件调度器 */
    protected MiddlewareDispatcher $dispatcher;

    /** @var bool 是否开启调试 */
    protected bool $debug = false;

    /** @var JsonErrorHandlerMiddleware 默认异常处理中间件 */
    protected JsonErrorHandlerMiddleware $errorHandler;

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
        $this->router = new Router();
        $this->runner = new RouteRunner($this->router);
        $this->dispatcher = new MiddlewareDispatcher($this->runner);
        $this->errorHandler = new JsonErrorHandlerMiddleware($debug);
        $this->dispatcher->pipe($this->errorHandler);
    }

    /**
     * 创建应用实例
     */
    public static function create(bool $debug = false): static
    {
        return new static($debug);
    }

    /**
     * 获取路由器
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * 获取全局中间件调度器
     */
    public function getDispatcher(): MiddlewareDispatcher
    {
        return $this->dispatcher;
    }

    /**
     * 获取默认异常处理中间件（可注册状态码级处理器）
     */
    public function getErrorHandler(): JsonErrorHandlerMiddleware
    {
        return $this->errorHandler;
    }

    /**
     * 是否为调试模式
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * 添加全局中间件
     */
    public function use(MiddlewareInterface|callable $middleware): static
    {
        $this->dispatcher->pipe($middleware);
        return $this;
    }

    /**
     * 添加全局中间件（别名）
     */
    public function middleware(MiddlewareInterface|callable $middleware): static
    {
        return $this->use($middleware);
    }

    /**
     * 注册 GET 路由（自动支持 HEAD）
     */
    public function get(string $pattern, mixed $handler): Route
    {
        return $this->route('GET', $pattern, $handler);
    }

    /**
     * 注册 POST 路由
     */
    public function post(string $pattern, mixed $handler): Route
    {
        return $this->route('POST', $pattern, $handler);
    }

    /**
     * 注册 PUT 路由
     */
    public function put(string $pattern, mixed $handler): Route
    {
        return $this->route('PUT', $pattern, $handler);
    }

    /**
     * 注册 DELETE 路由
     */
    public function delete(string $pattern, mixed $handler): Route
    {
        return $this->route('DELETE', $pattern, $handler);
    }

    /**
     * 注册 PATCH 路由
     */
    public function patch(string $pattern, mixed $handler): Route
    {
        return $this->route('PATCH', $pattern, $handler);
    }

    /**
     * 注册 OPTIONS 路由
     */
    public function options(string $pattern, mixed $handler): Route
    {
        return $this->route('OPTIONS', $pattern, $handler);
    }

    /**
     * 注册 HEAD 路由
     */
    public function head(string $pattern, mixed $handler): Route
    {
        return $this->route('HEAD', $pattern, $handler);
    }

    /**
     * 注册任意常用方法的路由
     */
    public function any(string $pattern, mixed $handler): Route
    {
        return $this->route(Method::ROUTABLE, $pattern, $handler);
    }

    /**
     * 注册指定方法的路由
     *
     * @param string|list<string> $method HTTP 方法或方法数组
     */
    public function route(string|array $method, string $pattern, mixed $handler): Route
    {
        return $this->router->add($method, $pattern, $handler);
    }

    /**
     * 注册路由组
     *
     * 组内注册的路由自动带上前缀，组中间件仅作用于组内路由。
     *
     * @param string|array{prefix?: string, middleware?: mixed, name?: string} $prefix 前缀或组属性
     * @param callable(App): void $callback 组内注册回调
     * @param list<mixed> $middlewares 组中间件（当 $prefix 为字符串时使用）
     */
    public function group(string|array $prefix, callable $callback, array $middlewares = []): static
    {
        $attributes = is_array($prefix)
            ? $prefix
            : ['prefix' => $prefix, 'middleware' => $middlewares];

        $this->router->group($attributes, function () use ($callback): void {
            $callback($this);
        });

        return $this;
    }

    /**
     * 注册 404 处理器
     */
    public function notFound(callable $handler): static
    {
        $this->runner->onNotFound($handler);
        return $this;
    }

    /**
     * 注册 405 处理器
     */
    public function methodNotAllowed(callable $handler): static
    {
        $this->runner->onMethodNotAllowed($handler);
        return $this;
    }

    /**
     * 根据命名路由生成 URL
     *
     * @param array<string, string|int> $params
     */
    public function url(string $name, array $params = []): string
    {
        return $this->router->url($name, $params);
    }

    /**
     * 处理请求（PSR-15 RequestHandlerInterface）
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        Request::setRequest($request);

        try {
            $response = $this->dispatcher->handle($request);

            // HEAD 请求不返回响应体
            if (strtoupper($request->getMethod()) === 'HEAD') {
                $response = $response->withBody(Psr7\Stream::create(''));
            }

            return $response;
        } finally {
            Request::clear();
        }
    }

    /**
     * 运行应用
     *
     * 优先使用已注册的服务端适配器（Swoole / Workerman），
     * 否则回退到 PHP-FPM / CLI-Server 模式，直接输出响应。
     */
    public function run(): void
    {
        $adapter = Kode::service('server_adapter');
        if ($adapter !== null && method_exists($adapter, 'run')) {
            $adapter->run($this);
            return;
        }

        Emitter::emit($this->handle(Psr7\Factory\ServerRequestFactory::fromGlobals()));
    }

    /**
     * 启动内置开发服务器（仅用于本地调试，不要用于生产）
     *
     * 相比旧版本：支持任意 HTTP 方法、解析请求头与请求体、正确关闭连接。
     */
    public function listen(string $host = '0.0.0.0', int $port = 8080): void
    {
        $address = "tcp://{$host}:{$port}";
        $server = @stream_socket_server($address, $errno, $errstr);

        if ($server === false) {
            throw new \RuntimeException(sprintf('无法监听 %s: %s (%d)', $address, $errstr, $errno));
        }

        fwrite(STDOUT, sprintf("Kode\\Http %s dev server: http://%s:%d\n", Kode::VERSION, $host, $port));
        fwrite(STDOUT, "Press Ctrl+C to stop\n");

        while (true) {
            $client = @stream_socket_accept($server, -1);
            if ($client === false) {
                continue;
            }

            try {
                $request = $this->readRequest($client);
                if ($request !== null) {
                    $this->writeResponse($client, $this->handle($request));
                }
            } catch (\Throwable $e) {
                fwrite($client, "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                fwrite(STDERR, '[error] ' . $e->getMessage() . "\n");
            } finally {
                fclose($client);
            }
        }
    }

    /**
     * 启动内置开发服务器（静态快捷方式，保持向后兼容）
     */
    public static function serve(int $port = 8080, string $host = '0.0.0.0'): void
    {
        static::create(true)->listen($host, $port);
    }

    /**
     * 从连接中读取并解析请求
     *
     * @param resource $client
     */
    private function readRequest($client): ?ServerRequestInterface
    {
        $head = '';
        while (($line = fgets($client)) !== false) {
            $head .= $line;
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
        }

        if (trim($head) === '') {
            return null;
        }

        $lines = preg_split('/\r?\n/', trim($head)) ?: [];
        $requestLine = array_shift($lines) ?? '';
        if (!preg_match('#^([A-Z]+)\s+(\S+)\s+HTTP/(\d\.\d)$#', $requestLine, $m)) {
            return null;
        }

        [$method, $target, $version] = [$m[1], $m[2], $m[3]];

        $headers = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }

        $length = (int) ($headers['Content-Length'] ?? 0);
        $body = '';
        while ($length > 0 && !feof($client)) {
            $chunk = fread($client, min(8192, $length));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
            $length -= strlen($chunk);
        }

        $host = $headers['Host'] ?? 'localhost';
        $request = new Psr7\Message\ServerRequest(
            $method,
            new Psr7\Uri('http://' . $host . $target),
            ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $target, 'SERVER_PROTOCOL' => 'HTTP/' . $version],
            $headers,
            $body,
            $version
        );

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        return $request
            ->withQueryParams($query)
            ->withCookieParams(self::parseCookies($headers['Cookie'] ?? ''))
            ->withParsedBody(self::parseBody($body, $headers['Content-Type'] ?? ''))
            ->withAttribute('request_time', microtime(true));
    }

    /**
     * 将 PSR-7 响应写回连接
     *
     * @param resource $client
     */
    private function writeResponse($client, ResponseInterface $response): void
    {
        $body = (string) $response->getBody();

        $out = sprintf(
            "HTTP/1.1 %d %s\r\n",
            $response->getStatusCode(),
            $response->getReasonPhrase() ?: Status::phraseOf($response->getStatusCode())
        );

        foreach ($response->getHeaders() as $name => $values) {
            if (strtolower($name) === 'content-length') {
                continue;
            }
            foreach ($values as $value) {
                $out .= "{$name}: {$value}\r\n";
            }
        }

        $out .= 'Content-Length: ' . strlen($body) . "\r\n";
        $out .= "Connection: close\r\n\r\n";

        fwrite($client, $out . $body);
    }

    /**
     * @return array<string, string>
     */
    private static function parseCookies(string $header): array
    {
        $cookies = [];
        foreach (array_filter(array_map('trim', explode(';', $header))) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $cookies[trim($name)] = urldecode(trim($value));
        }
        return $cookies;
    }

    /**
     * 解析请求体
     */
    private static function parseBody(string $body, string $contentType): array|null
    {
        if ($body === '') {
            return null;
        }

        $type = strtolower($contentType);

        if (str_contains($type, 'application/json')) {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : null;
        }

        if (str_contains($type, 'application/x-www-form-urlencoded')) {
            parse_str($body, $parsed);
            return $parsed;
        }

        return null;
    }
}
