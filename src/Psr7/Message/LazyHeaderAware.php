<?php

declare(strict_types=1);

namespace Kode\Http\Psr7\Message;

/**
 * 懒加载请求的头解析状态守卫接口。
 *
 * 供框架 / 适配层标记「header 尚未解析的懒请求」：链路追踪嗅探等热路径可据此
 * 在不触发任何解析（header 规范化 / server params 构建）的前提下快速判定
 * 「不存在程序化注入的链路头」，避免为每个请求付出不必要的引导成本。
 *
 * 背景：{@see LazyServerRequest} 承诺热路径零 header 成本；但服务端框架可能
 * 自实现同语义的懒请求类（父类不同，无法被 instanceof 包内类捕获）。
 * 通过本接口解耦：任何实现方只要声明 isHeadersResolved() 供守卫查询即可。
 */
interface LazyHeaderAware
{
    /**
     * header 是否已解析（规范化并写入内部存储）。
     * 未解析时调用方应假设不存在程序化注入的 header，可跳过 header 扫描。
     */
    public function isHeadersResolved(): bool;

    /**
     * 定向读取单个 header 的值：必须在不触发全量 header 解析 / server params
     * 引导构建的前提下完成（未命中返回 null）。用于链路追踪嗅探等热路径守卫，
     * 使懒加载请求维持「热路径零解析成本」的承诺，同时不错判真实报文中的链路头。
     * 内部实现可走原始报文定向扫描 / server params 键查 / 显式注入缓存等廉价来源；
     * 若 header 已解析，允许退化为普通 getHeaderLine。
     */
    public function peekHeader(string $name): ?string;
}