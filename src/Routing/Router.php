<?php

declare(strict_types=1);

namespace Kode\Http\Routing;

use Kode\Http\Method;

/**
 * 路由器
 *
 * 采用「静态路由哈希表 + 动态路由正则」的两级匹配策略：
 * - 静态路由 O(1) 命中，不走正则；
 * - 动态路由按注册顺序尝试正则，并对 `方法 + 路径` 结果做运行时缓存。
 *
 * 同时区分 404（路径不存在）与 405（路径存在但方法不允许）。
 *
 * @example
 * ```php
 * $router = new Router();
 * $router->add(['GET'], '/users/{id:\d+}', $handler)->name('user.show');
 * $router->group(['prefix' => '/api', 'middleware' => [$auth]], function (Router $r) {
 *     $r->add(['POST'], '/orders', $createOrder);
 * });
 *
 * $result = $router->match('GET', '/users/42');
 * $router->url('user.show', ['id' => 42]);   // /users/42
 * ```
 */
final class Router
{
    /** @var array<string, array<string, Route>> 静态路由表 [METHOD][path] => Route */
    private array $static = [];

    /** @var array<string, list<Route>> 动态路由表 [METHOD] => Route[] */
    private array $dynamic = [];

    /** @var list<Route> 全部路由（按注册顺序） */
    private array $routes = [];

    /** @var array<string, Route> 命名路由表 */
    private array $named = [];

    /** @var list<array{prefix: string, middleware: list<mixed>, name: string}> 路由组栈 */
    private array $groupStack = [];

    /** @var array<string, RouteResult> 匹配结果缓存 */
    private array $cache = [];

    /** @var array<int, string> 路由对象 ID 到组名前缀的映射 */
    private array $namePrefixes = [];

    /** @var bool 命名路由索引是否已构建 */
    private bool $namedBuilt = false;

    /** @var int 匹配缓存上限，防止恶意路径撑爆内存 */
    private int $cacheLimit = 1024;

    /**
     * 注册路由
     *
     * @param list<string>|string $methods HTTP 方法
     * @param string $pattern 路由规则
     * @param mixed $handler 处理器
     */
    public function add(array|string $methods, string $pattern, mixed $handler): Route
    {
        $methods = is_string($methods) ? [$methods] : $methods;
        $methods = array_map(Method::normalize(...), $methods);

        // GET 自动支持 HEAD
        if (in_array('GET', $methods, true) && !in_array('HEAD', $methods, true)) {
            $methods[] = 'HEAD';
        }

        $prefix = '';
        $groupMiddlewares = [];
        $namePrefix = '';
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $namePrefix .= $group['name'];
            foreach ($group['middleware'] as $middleware) {
                $groupMiddlewares[] = $middleware;
            }
        }

        $route = new Route($methods, $prefix . '/' . ltrim($pattern, '/'), $handler);
        if ($groupMiddlewares !== []) {
            $route->middleware(...$groupMiddlewares);
        }

        $this->routes[] = $route;
        $this->cache = [];
        $this->namedBuilt = false;

        foreach ($route->getMethods() as $method) {
            if ($route->isStatic()) {
                $this->static[$method][$route->getPattern()] = $route;
            } else {
                $this->dynamic[$method][] = $route;
            }
        }

        if ($namePrefix !== '') {
            $this->namePrefixes[spl_object_id($route)] = $namePrefix;
        }

        return $route;
    }

    /**
     * 注册路由组
     *
     * @param array{prefix?: string, middleware?: list<mixed>|mixed, name?: string} $attributes 组属性
     * @param callable(Router): void $callback 组内路由注册回调
     */
    public function group(array $attributes, callable $callback): self
    {
        $middleware = $attributes['middleware'] ?? [];
        if (!is_array($middleware)) {
            $middleware = [$middleware];
        }

        $prefix = isset($attributes['prefix']) && $attributes['prefix'] !== ''
            ? '/' . trim((string) $attributes['prefix'], '/')
            : '';

        $this->groupStack[] = [
            'prefix' => $prefix,
            'middleware' => array_values($middleware),
            'name' => (string) ($attributes['name'] ?? ''),
        ];

        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }

        return $this;
    }

    /**
     * 匹配请求
     *
     * @param string $method HTTP 方法
     * @param string $path 请求路径
     */
    public function match(string $method, string $path): RouteResult
    {
        $method = Method::normalize($method);
        $path = Route::normalizePath($path);
        $cacheKey = $method . ' ' . $path;

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $result = $this->doMatch($method, $path);

        if (count($this->cache) < $this->cacheLimit) {
            $this->cache[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * 执行匹配逻辑
     */
    private function doMatch(string $method, string $path): RouteResult
    {
        if (isset($this->static[$method][$path])) {
            return RouteResult::found($this->static[$method][$path]);
        }

        foreach ($this->dynamic[$method] ?? [] as $route) {
            $params = $route->match($path);
            if ($params !== null) {
                return RouteResult::found($route, $params);
            }
        }

        // 路径存在但方法不匹配 => 405
        $allowed = [];
        foreach ($this->routes as $route) {
            if (in_array($method, $route->getMethods(), true)) {
                continue;
            }
            if ($route->match($path) !== null) {
                foreach ($route->getMethods() as $allowedMethod) {
                    $allowed[] = $allowedMethod;
                }
            }
        }

        return $allowed === [] ? RouteResult::notFound() : RouteResult::methodNotAllowed($allowed);
    }

    /**
     * 根据路由名称生成 URL
     *
     * @param array<string, string|int> $params
     * @throws \InvalidArgumentException 路由名不存在时抛出
     */
    public function url(string $name, array $params = []): string
    {
        $route = $this->route($name);
        if ($route === null) {
            throw new \InvalidArgumentException(sprintf('未找到命名路由: %s', $name));
        }

        return $route->toUrl($params);
    }

    /**
     * 获取命名路由
     */
    public function route(string $name): ?Route
    {
        if (!$this->namedBuilt) {
            // 惰性建立命名索引，允许在注册后再调用 ->name()
            $this->named = [];
            foreach ($this->routes as $route) {
                $routeName = $route->getName();
                if ($routeName === null) {
                    continue;
                }
                $prefix = $this->namePrefixes[spl_object_id($route)] ?? '';
                $this->named[$prefix . $routeName] = $route;
            }
            $this->namedBuilt = true;
        }

        return $this->named[$name] ?? null;
    }

    /**
     * 获取全部路由
     *
     * @return list<Route>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * 路由数量
     */
    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * 清空匹配缓存
     */
    public function flushCache(): self
    {
        $this->cache = [];
        $this->named = [];
        $this->namedBuilt = false;
        return $this;
    }

    /**
     * 设置匹配缓存上限
     */
    public function setCacheLimit(int $limit): self
    {
        $this->cacheLimit = max(0, $limit);
        return $this;
    }
}
