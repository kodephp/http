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
 * 提供简洁标准的应用构建方式，支持路由、中间件、请求处理
 *
 * @example
 * ```php
 * // 创建应用
 * $app = App::create();
 *
 * // 注册路由
 * $app->get('/api/users', function($req) {
 *     return Response::success(['users' => ['name' => '张三']]);
 * });
 *
 * // 运行
 * $app->run();
 * ```
 */
class App implements RequestHandlerInterface
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
        $app->handler = $app;
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
     * 添加全局中间件（别名）
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
     * 注册 PATCH 路由
     */
    public function patch(string $pattern, callable $handler): self
    {
        return $this->route('PATCH', $pattern, $handler);
    }

    /**
     * 注册 OPTIONS 路由
     */
    public function options(string $pattern, callable $handler): self
    {
        return $this->route('OPTIONS', $pattern, $handler);
    }

    /**
     * 注册任意方法路由
     */
    public function any(string $pattern, callable $handler): self
    {
        return $this->route(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], $pattern, $handler);
    }

    /**
     * 注册路由
     *
     * @param string|array $method HTTP 方法或方法数组
     */
    public function route(string|array $method, string $pattern, callable $handler): self
    {
        $methods = is_array($method) ? array_map('strtoupper', $method) : [strtoupper($method)];

        $this->routes[] = [
            'methods' => $methods,
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
        $originalRoutes = $this->routes;
        $this->routes = [];

        $callback($this);

        $prefixedRoutes = [];
        foreach ($this->routes as $route) {
            $route['pattern'] = rtrim($prefix, '/') . '/' . ltrim($route['pattern'], '/');
            $prefixedRoutes[] = $route;
        }

        $this->routes = $originalRoutes;

        foreach ($middlewares as $middleware) {
            if (is_callable($middleware)) {
                $middleware = new CallableMiddleware($middleware);
            }
            array_unshift($prefixedRoutes, ['_middleware' => $middleware]);
        }

        foreach ($prefixedRoutes as $route) {
            if (isset($route['_middleware'])) {
                $this->use($route['_middleware']);
            } else {
                $this->routes[] = $route;
            }
        }

        return $this;
    }

    /**
     * 处理请求（实现 RequestHandlerInterface）
     */
    public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        Request::setRequest($request);

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        foreach ($this->routes as $route) {
            if (!isset($route['methods']) || !isset($route['pattern'])) {
                continue;
            }

            if (!in_array($method, $route['methods'], true)) {
                continue;
            }

            $params = $this->matchPath($route['pattern'], $path);
            if ($params !== false) {
                $request = $request->withAttributes($params);
                return $this->executeHandler($route['handler'], $request);
            }
        }

        return $this->dispatcher->dispatch($request);
    }

    /**
     * 执行处理器
     */
    protected function executeHandler(callable $handler, ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        if ($handler instanceof \Closure) {
            $handler = function($req) use ($handler) {
                $result = $handler($req);
                if ($result instanceof \Psr\Http\Message\ResponseInterface) {
                    return $result;
                }
                if (is_array($result)) {
                    return Response::json($result)->send();
                }
                if (is_string($result)) {
                    return Response::text($result)->send();
                }
                if ($result === null) {
                    return Response::empty()->send();
                }
                return Response::success($result)->send();
            };
            $handler = new CallableMiddleware($handler);
        } elseif (is_callable($handler)) {
            $handler = new CallableMiddleware($handler);
        }

        $pipeline = new MiddlewarePipeline($this->handler);
        foreach ($this->globalMiddlewares as $middleware) {
            $pipeline->pipe($middleware);
        }
        $pipeline->pipe($handler);

        return $pipeline->handle($request);
    }

    /**
     * 匹配路径并提取参数
     */
    protected function matchPath(string $pattern, string $path): array|false
    {
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $path, $matches)) {
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return false;
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

        while ($client = @stream_socket_accept($server, 5)) {
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

            $factory = new \Kode\Http\Psr7\Factory\ServerRequestFactory();
            $psrRequest = $factory->createServerRequest('GET', $path)
                ->withAttribute('client_ip', '127.0.0.1')
                ->withAttribute('request_time', microtime(true));

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
