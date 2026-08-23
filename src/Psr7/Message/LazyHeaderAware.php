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
}