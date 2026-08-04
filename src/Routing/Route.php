<?php

declare(strict_types=1);

namespace Kode\Http\Routing;

/**
 * 路由定义
 *
 * 支持静态路径、动态参数、参数正则约束、可选参数、路由中间件与命名路由。
 *
 * 参数语法：
 * - `{id}`        必选参数，默认匹配 `[^/]+`
 * - `{id:\d+}`    带正则约束的必选参数
 * - `{name?}`     可选参数（其前导 `/` 一并可选）
 * - `{page?:\d+}` 带约束的可选参数
 *
 * @example
 * ```php
 * $route = new Route(['GET'], '/users/{id:\d+}', $handler);
 * $route->name('user.show')->middleware($authMiddleware);
 * ```
 */
final class Route
{
    /** @var list<string> 允许的 HTTP 方法（大写） */
    private array $methods;

    /** @var string 原始路由规则 */
    private string $pattern;

    /** @var mixed 路由处理器 */
    private mixed $handler;

    /** @var list<mixed> 路由级中间件 */
    private array $middlewares = [];

    /** @var string|null 路由名称 */
    private ?string $name = null;

    /** @var string|null 编译后的正则，静态路由为 null */
    private ?string $regex = null;

    /** @var list<string> 参数名列表 */
    private array $parameters = [];

    /** @var array<string, string> 参数默认值 */
    private array $defaults = [];

    /**
     * @param list<string> $methods HTTP 方法列表
     * @param string $pattern 路由规则
     * @param mixed $handler 处理器（Closure、[类, 方法]、"类@方法" 等）
     */
    public function __construct(array $methods, string $pattern, mixed $handler)
    {
        $this->methods = array_values(array_unique(array_map(strtoupper(...), $methods)));
        $this->pattern = self::normalizePath($pattern);
        $this->handler = $handler;

        [$this->regex, $this->parameters] = self::compile($this->pattern);
    }

    /**
     * 规范化路径：确保以 / 开头，去掉末尾多余的 /
     */
    public static function normalizePath(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    /**
     * 编译路由规则为正则
     *
     * @return array{0: string|null, 1: list<string>} [正则或 null（静态路由）, 参数名列表]
     */
    private static function compile(string $pattern): array
    {
        if (!str_contains($pattern, '{')) {
            return [null, []];
        }

        $placeholder = '/\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*(\?)?\s*(?::\s*((?:[^{}]|\{\d+(?:,\d*)?\})+?)\s*)?\}/';

        if (preg_match_all($placeholder, $pattern, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return [null, []];
        }

        $regex = '';
        $params = [];
        $cursor = 0;

        foreach ($matches as $set) {
            /** @var array{0: string, 1: int} $whole */
            $whole = $set[0];
            $literal = substr($pattern, $cursor, $whole[1] - $cursor);
            $cursor = $whole[1] + strlen($whole[0]);

            $name = $set[1][0];
            $optional = isset($set[2]) && $set[2][1] !== -1 && $set[2][0] === '?';
            $constraint = (isset($set[3]) && $set[3][1] !== -1 && $set[3][0] !== '') ? $set[3][0] : '[^/]+';

            $params[] = $name;
            $group = '(?P<' . $name . '>' . $constraint . ')';

            if ($optional && str_ends_with($literal, '/')) {
                $regex .= preg_quote(substr($literal, 0, -1), '#') . '(?:/' . $group . ')?';
            } elseif ($optional) {
                $regex .= preg_quote($literal, '#') . '(?:' . $group . ')?';
            } else {
                $regex .= preg_quote($literal, '#') . $group;
            }
        }

        $regex .= preg_quote(substr($pattern, $cursor), '#');

        return ['#^' . $regex . '$#', $params];
    }

    /**
     * 尝试匹配路径，返回参数数组或 null
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        if ($this->regex === null) {
            return $this->pattern === $path ? [] : null;
        }

        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $params = $this->defaults;
        foreach ($this->parameters as $name) {
            if (isset($matches[$name]) && $matches[$name] !== '') {
                $params[$name] = $matches[$name];
            }
        }

        return $params;
    }

    /**
     * 是否为静态路由（无动态参数）
     */
    public function isStatic(): bool
    {
        return $this->regex === null;
    }

    /**
     * 设置路由名称
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 追加路由级中间件
     *
     * @param mixed ...$middlewares PSR-15 中间件或可调用对象
     */
    public function middleware(mixed ...$middlewares): self
    {
        foreach ($middlewares as $middleware) {
            if (is_array($middleware)) {
                foreach ($middleware as $item) {
                    $this->middlewares[] = $item;
                }
                continue;
            }
            $this->middlewares[] = $middleware;
        }
        return $this;
    }

    /**
     * 设置参数默认值
     *
     * @param array<string, string> $defaults
     */
    public function defaults(array $defaults): self
    {
        $this->defaults = array_merge($this->defaults, $defaults);
        return $this;
    }

    /**
     * 根据参数生成 URL
     *
     * @param array<string, string|int> $params
     * @throws \InvalidArgumentException 缺少必选参数时抛出
     */
    public function toUrl(array $params = []): string
    {
        $params = array_merge($this->defaults, $params);
        $placeholder = '/\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*(\?)?\s*(?::\s*((?:[^{}]|\{\d+(?:,\d*)?\})+?)\s*)?\}/';

        $url = preg_replace_callback($placeholder, static function (array $m) use ($params): string {
            $name = $m[1];
            $optional = ($m[2] ?? '') === '?';

            if (array_key_exists($name, $params)) {
                return (string) $params[$name];
            }

            if ($optional) {
                return '';
            }

            throw new \InvalidArgumentException(sprintf('生成 URL 缺少必选参数: %s', $name));
        }, $this->pattern) ?? $this->pattern;

        $url = preg_replace('#/+#', '/', $url) ?? $url;

        return $url === '' ? '/' : ($url !== '/' ? rtrim($url, '/') : '/');
    }

    /** @return list<string> */
    public function getMethods(): array
    {
        return $this->methods;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /** @return list<mixed> */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getRegex(): ?string
    {
        return $this->regex;
    }
}
