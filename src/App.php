<?php

declare(strict_types=1);

namespace Kode\Http;

use Kode\Http\Exception\HttpException;
use Kode\Http\Middleware\JsonErrorHandlerMiddleware;
use Kode\Http\Middleware\MiddlewareDispatcher;
use Kode\Http\Middleware\MiddlewarePipeline;
use Kode\Http\Middleware\CallableMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP 应用构建器
 *
 * 提供简洁标准的应用构建方式
 *
 * @example
 * ```php
 * // 创建应用
 * $app = App::create();
 *
 * // 注册路由
 * $app->get('/api/users', function($req) {
 *     return Res::success(['users' => ['name' => '张三']]);
 * });
 *
 * // 运行
 * $app->run();
 * ```
 */
class App
{
    /** @var MiddlewareDispatcher 中间件调度器 */
    protected MiddlewareDispatcher $dispatcher;

    /** @var RequestHandlerInterface 最终处理器 */
    protected RequestHandlerInterface $handler;

    /** @var array 路由定义 */
    protected array $routes = [];

    /** @var bool 是否开启调试 */
    protected bool $debug = false;

    /** @var array 全局中间件 */
    protected array $globalMiddlewares = [];

    /**
     * 创建应用实例
     */
    public static function create(bool $debug = false): self
    {
        $app = new self();
        $app->debug = $debug;
        $app->handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw HttpException::notFound($request, 'Route not found');
            }
        };
        $app->dispatcher = new MiddlewareDispatcher($app->handler);
        $app->dispatcher->pipe(new JsonErrorHandlerMiddleware($debug));

        return $app;
    }

    /**
     * 获取调度器
     */
    public function getDispatcher(): MiddlewareDispatcher
    {
        return $this->dispatcher;
    }

    /**
     * 添加中间件
     */
    public function use($middleware): self
    {
        if (is_callable($middleware)) {
            $middleware = new CallableMiddleware($middleware);
        }
        $this->dispatcher->pipe($middleware);
        return $this;
    }

    /**
     * 添加全局中间件
     */
    public function middleware($middleware): self
    {
        return $this->use($middleware);
    }

    /**
     * 注册 GET 路由
     */
    public function get(string $pattern, callable $handler): self
    {
        return $this->route('GET', $pattern, $handler);
    }

    /**
     * 注册 POST 路由
     */
    public function post(string $pattern, callable $handler): self
    {
        return $this->route('POST', $pattern, $handler);
    }

    /**
     * 注册 PUT 路由
     */
    public function put(string $pattern, callable $handler): self
    {
        return $this->route('PUT', $pattern, $handler);
    }

    /**
     * 注册 DELETE 路由
     */
    public function delete(string $pattern, callable $handler): self
    {
        return $this->route('DELETE', $pattern, $handler);
    }

    /**
     * 注册路由
     */
    public function route(string $method, string $pattern, callable $handler): self
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
        return $this;
    }

    /**
     * 注册路由组
     */
    public function group(string $prefix, callable $callback, array $middlewares = []): self
    {
        $app = new self();
        $app->routes = &$this->routes;
        $app->dispatcher = $this->dispatcher;

        foreach ($middlewares as $middleware) {
            $app->use($middleware);
        }

        $callback($app);

        return $this;
    }

    /**
     * 处理请求
     */
    public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if ($this->matchPath($route['pattern'], $path)) {
                $handler = $route['handler'];

                if ($handler instanceof \Closure) {
                    $handler = new CallableMiddleware(function($req) use ($handler) {
                        $result = $handler($req);
                        if ($result instanceof \Psr\Http\Message\ResponseInterface) {
                            return $result;
                        }
                        if (is_array($result)) {
                            return Res::json($result)->send($req);
                        }
                        if (is_string($result)) {
                            return Res::text($result)->send($req);
                        }
                        return Res::success($result)->send($req);
                    });
                } else {
                    $handler = new CallableMiddleware($handler);
                }

                $pipeline = new MiddlewarePipeline($this->handler);
                foreach ($this->globalMiddlewares as $middleware) {
                    $pipeline->pipe($middleware);
                }
                $pipeline->pipe($handler);

                return $pipeline->handle($request);
            }
        }

        return $this->dispatcher->dispatch($request);
    }

    /**
     * 匹配路径
     */
    protected function matchPath(string $pattern, string $path): bool
    {
        $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $pattern);
        $pattern = '#^' . $pattern . '$#';
        return (bool) preg_match($pattern, $path);
    }

    /**
     * 运行应用
     */
    public function run(): void
    {
        $serverAdapter = Kode::service('server_adapter');
        if ($serverAdapter) {
            $serverAdapter->run($this);
        }
    }

    /**
     * 启动开发服务器
     */
    public static function serve(int $port = 8080): void
    {
        $host = "0.0.0.0:{$port}";
        echo "Kode\Http Server starting on http://{$host}\n";
        echo "Press Ctrl+C to stop\n";

        $app = self::create();

        $server = @stream_socket_server("tcp://{$host}", $errno, $errstr);
        if (!$server) {
            die("Failed to create server: {$errstr}\n");
        }

        stream_set_blocking($server, false);

        $app->use(function($req) {
            return Res::success(['msg' => 'Kode\Http is running']);
        });

        while ($client = @stream_socket_accept($server, 5)) {
            $request = '';
            $headers = '';

            while (($line = fgets($client)) !== false) {
                $headers .= $line;
                if ($line === "\r\n") {
                    break;
                }
            }

            preg_match('/^[A-Z]+\s+([^\s]+)/', $headers, $matches);
            $path = $matches[1] ?? '/';

            preg_match('/Host:\s+([^\r\n]+)/', $headers, $matches);
            $hostHeader = $matches[1] ?? "localhost:{$port}";

            $request = "GET {$path} HTTP/1.1\r\nHost: {$hostHeader}\r\n\r\n";

            $factory = new \Kode\Http\Psr7\Factory\ServerRequestFactory();
            $psrRequest = $factory->createServerRequest('GET', $path)
                ->withAttribute('client_ip', '127.0.0.1');

            $response = $app->handle($psrRequest);

            $statusLine = "HTTP/1.1 {$response->getStatusCode()} " . $response->getReasonPhrase() . "\r\n";
            fwrite($client, $statusLine);

            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    fwrite($client, "{$name}: {$value}\r\n");
                }
            }

            fwrite($client, "\r\n");
            fwrite($client, (string) $response->getBody());

            fclose($client);
        }
    }
}
