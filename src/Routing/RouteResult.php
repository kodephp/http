<?php

declare(strict_types=1);

namespace Kode\Http\Routing;

/**
 * 路由匹配结果
 *
 * 使用不可变值对象承载匹配状态，明确区分「未找到」与「方法不允许」，
 * 避免把 404 与 405 混为一谈。
 */
final readonly class RouteResult
{
    /** @var int 匹配成功 */
    public const int FOUND = 0;

    /** @var int 路径未匹配（404） */
    public const int NOT_FOUND = 1;

    /** @var int 路径匹配但方法不允许（405） */
    public const int METHOD_NOT_ALLOWED = 2;

    /**
     * @param int $status 匹配状态
     * @param Route|null $route 命中的路由
     * @param array<string, string> $params 路由参数
     * @param list<string> $allowedMethods 405 时的可用方法列表
     */
    private function __construct(
        public int $status,
        public ?Route $route = null,
        public array $params = [],
        public array $allowedMethods = [],
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public static function found(Route $route, array $params = []): self
    {
        return new self(self::FOUND, $route, $params);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(self::METHOD_NOT_ALLOWED, null, [], array_values(array_unique($allowedMethods)));
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}
