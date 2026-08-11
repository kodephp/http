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
            return $this->handleNotFound($request);
        }

        if ($result->status === RouteResult::METHOD_NOT_ALLOWED) {
            return $this->handleMethodNotAllowed($request, $result->allowedMethods);
        }

        /** @var Route $route */
        $route = $result->route;

        foreach ($result->params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }
        $request = $request
            ->withAttribute('_route', $route)
            ->withAttribute('_route_params', $result->params);

        Request::setRequest($request);

        $target = new CallableHandler(
            fn(ServerRequestInterface $req): ResponseInterface => Response::resolve(
                self::invoke($route->getHandler(), $req, $result->params)
            )
        );

        $middlewares = $route->getMiddlewares();
        if ($middlewares === []) {
            return $target->handle($request);
        }

        return (new MiddlewarePipeline($target))->pipeAll($middlewares)->handle($request);
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
     * 解析控制器实例：优先取容器中已注册的服务
     */
    private static function resolveClass(string $class): object
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
