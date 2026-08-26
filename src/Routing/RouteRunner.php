<?php

declare(strict_types=1);

namespace Kode\Http\Routing;

use Kode\Http\Kode;
use Kode\Http\Middleware\MiddlewarePipeline;
use Kode\Http\Request;
use Kode\Http\Response;
use Kode\Http\Status;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 路由执行器
 *
 * 作为应用管道的最终处理器：负责路由匹配、参数注入、
 * 路由级中间件执行、处理器调用与返回值归一化。
 *
 * 与旧版本不同，未匹配到路由时直接返回 404/405 响应，
 * 不会再回落到应用自身造成无限递归。
 *
 * @internal 由 App 创建
 */
final class RouteRunner implements RequestHandlerInterface
{
    /** @var (callable(ServerRequestInterface): mixed)|null 自定义 404 处理器 */
    private $notFoundHandler = null;

    /** @var (callable(ServerRequestInterface, list<string>): mixed)|null 自定义 405 处理器 */
    private $methodNotAllowedHandler = null;

    /**
     * 已编译的路由处理器缓存（按 spl_object_id(Route) 索引）
     *
     * 缓存「已解析的 handler + 路由级中间件管道」，避免每请求重复反射 /
     * 实例化控制器 / 重建管道。路由对象在启动期注册且稳定，因此缓存有界。
     *
     * @var array<int, RequestHandlerInterface>
     */
    private array $compiled = [];

    /**
     * 已解析的控制器实例缓存（按类名索引，worker 级复用）。
     *
     * 无状态控制器在 worker 生命周期内只需实例化一次；后续请求（无论经
     * {@see compileRoute()} 还是 {@see invoke()}）直接复用缓存实例，
     * 跳过每请求 `Kode::service()` 查找 + `new $class()` 分配。
     *
     * 线程安全：缓存的实例须保持无状态（不持有请求级可变状态）——这与
     * webman / hyperf 的单例控制器模型一致。如需按请求隔离状态，
     * 应通过 {@see Request} facade 或 {@see Context} 获取。
     *
     * @var array<string, object>
     */
    private static array $instanceCache = [];

    public function __construct(private readonly Router $router)
    {
    }

    /**
     * 设置 404 处理器
     */
    public function onNotFound(callable $handler): self
    {
        $this->notFoundHandler = $handler;
        return $this;
    }

    /**
     * 设置 405 处理器
     */
    public function onMethodNotAllowed(callable $handler): self
    {
        $this->methodNotAllowedHandler = $handler;
        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->router->match($request->getMethod(), $request->getUri()->getPath());

        if ($result->status === RouteResult::NOT_FOUND) {
            // 保持 facade 语义：App 层在「无用户中间件」时不再预置请求，
            // 404/405 分支在此统一写入，行为与旧版（App 层预置）完全等价。
            Request::setRequest($request);

            return $this->handleNotFound($request);
        }

        if ($result->status === RouteResult::METHOD_NOT_ALLOWED) {
            Request::setRequest($request);

            return $this->handleMethodNotAllowed($request, $result->allowedMethods);
        }

        /** @var Route $route */
        $route = $result->route;

        // 无参路由热路径：跳过 attribute 克隆（_route 在包内无消费方；
        // _route_params 对空参数恒等于默认值 []，语义完全一致）。
        if ($result->params !== []) {
            foreach ($result->params as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }
            $request = $request
                ->withAttribute('_route', $route)
                ->withAttribute('_route_params', $result->params);
        }

        Request::setRequest($request);

        return $this->dispatchRoute($route, $request);
    }

    /**
     * 派发路由处理器，命中缓存则直接复用已编译的管道。
     */
    private function dispatchRoute(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        $id = spl_object_id($route);
        if (!isset($this->compiled[$id])) {
            $this->compiled[$id] = $this->compileRoute($route);
        }

        return $this->compiled[$id]->handle($request);
    }

    /**
     * 编译单条路由：解析 handler + 构造目标处理器 + 组装路由级中间件管道。
     *
     * 目标闭包从请求属性 `_route_params` 读取参数，使管道可跨请求复用。
     * 解析结果按路由对象缓存，避免每请求重复反射 / 实例化控制器。
     *
     * 热路径优化：
     * - 静态路由（无参数）编译为不含 `getAttribute` 的闭包，直接传 `[]`，
     *   跳过每请求一次哈希查找 + 默认值回填。
     * - 闭包内联 `instanceof ResponseInterface` 检查，对已为 PSR-7 响应的
     *   返回值跳过 `Response::resolve()` 的 `match` 分发。
     *
     * 注意：缓存的 handler 可跨请求复用（含控制器实例），因此路由处理器
     * 须保持无状态——这与 webman / hyperf 的单例控制器模型一致。
     *
     * @return RequestHandlerInterface
     */
    private function compileRoute(Route $route): RequestHandlerInterface
    {
        $callable = self::toCallable($route->getHandler());

        if ($route->isStatic()) {
            // 静态路由：跳过 getAttribute 查找
            $target = new CallableHandler(
                static function (ServerRequestInterface $req) use ($callable): ResponseInterface {
                    $result = $callable($req, []);
                    return $result instanceof ResponseInterface
                        ? $result
                        : Response::resolve($result);
                }
            );
        } else {
            $target = new CallableHandler(
                static function (ServerRequestInterface $req) use ($callable): ResponseInterface {
                    $result = $callable($req, $req->getAttribute('_route_params', []));
                    return $result instanceof ResponseInterface
                        ? $result
                        : Response::resolve($result);
                }
            );
        }

        $middlewares = $route->getMiddlewares();
        if ($middlewares === []) {
            return $target;
        }

        return (new MiddlewarePipeline($target))->pipeAll($middlewares);
    }

    /**
     * 调用路由处理器
     *
     * 支持 Closure、[类名, 方法]、"类名@方法"、可调用对象、含 __invoke 的类名。
     *
     * @param array<string, string> $params
     */
    public static function invoke(mixed $handler, ServerRequestInterface $request, array $params): mixed
    {
        $callable = self::toCallable($handler);

        return $callable($request, $params);
    }

    /**
     * 将各种形态的处理器解析为可调用对象
     */
    private static function toCallable(mixed $handler): callable
    {
        if ($handler instanceof \Closure) {
            return $handler;
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            return [self::resolveClass($class), $method];
        }

        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            return [self::resolveClass($handler[0]), $handler[1]];
        }

        if (is_string($handler) && class_exists($handler)) {
            $instance = self::resolveClass($handler);
            if (!is_callable($instance)) {
                throw new \InvalidArgumentException(sprintf('路由处理器 %s 缺少 __invoke 方法', $handler));
            }
            return $instance;
        }

        if (is_callable($handler)) {
            return $handler;
        }

        throw new \InvalidArgumentException('无法解析的路由处理器');
    }

    /**
     * 解析控制器实例：优先取容器中已注册的服务，其次取 worker 级缓存。
     *
     * 首次解析时调用 `Kode::service()` 查容器，未命中则 `new $class()`，
     * 结果写入 {@see $instanceCache} 供后续请求复用——无状态控制器在
     * worker 生命周期内只实例化一次，消除每请求 DI 解析开销。
     */
    private static function resolveClass(string $class): object
    {
        return self::$instanceCache[$class] ??= self::doResolveClass($class);
    }

    /**
     * 实际解析逻辑（仅在缓存未命中时执行）。
     */
    private static function doResolveClass(string $class): object
    {
        $service = Kode::service($class);
        if ($service !== null) {
            return $service;
        }

        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('路由处理器类不存在: %s', $class));
        }

        return new $class();
    }

    private function handleNotFound(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->notFoundHandler !== null) {
            return Response::resolve(($this->notFoundHandler)($request));
        }

        return Response::error(Status::NOT_FOUND->value, 'Not Found', 'E1004');
    }

    /**
     * @param list<string> $allowed
     */
    private function handleMethodNotAllowed(ServerRequestInterface $request, array $allowed): ResponseInterface
    {
        if ($this->methodNotAllowedHandler !== null) {
            return Response::resolve(($this->methodNotAllowedHandler)($request, $allowed));
        }

        return Response::error(Status::METHOD_NOT_ALLOWED->value, 'Method Not Allowed', 'E1005')
            ->header('Allow', implode(', ', $allowed));
    }
}
