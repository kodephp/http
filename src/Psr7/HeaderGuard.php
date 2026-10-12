<?php

declare(strict_types=1);

namespace Kode\Http\Psr7;

/**
 * HTTP 头名/头值校验与发射层清洗
 *
 * 防线分两层：
 * 1. 写入层（withHeader/withStatus/withProtocolVersion 等）：非法头名
 *    （不满足 RFC 7230 token）抛 InvalidArgumentException——这是编程错误，
 *    fail-fast 有助于尽早暴露 bug；头值含 CR/LF/NUL 则静默压平（scrub）。
 *    响应头值常承载用户可控数据（如 Resp::redirect($userInput)），压平既
 *    彻底消灭响应拆分（HTTP splitting），又不让畸形输入把请求变成 500
 *    ——与 v0.8.52 起框架承诺的安全契约一致，且与读侧（请求头）对称。
 * 2. 发射层（App::writeResponse / ServerRunner::emitResponse）：对任意
 *    PSR-7 实现（含第三方）产出的头再做一次 clean() 兜底，因为发射层
 *    是信任边界，不能假设输入一定来自本包的受校验对象。
 *
 * @package Kode\Http\Psr7
 */
final class HeaderGuard
{
    /** RFC 7230 token：除分隔符外的可打印 ASCII */
    private const NAME_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    private const DANGEROUS = '/[\r\n\x00]/';

    /**
     * 校验头名，非法则抛出异常
     *
     * @throws \InvalidArgumentException 名不满足 RFC 7230 token
     */
    public static function assertName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('非法 HTTP 头名');
        }
    }

    /**
     * 严格校验头值（含数组形式的多值头），非法则抛出异常
     *
     * 供需要「宁可失败也不静默修改」的调用方显式使用。默认写入层
     * （withHeader 等）不走本方法——那里用 scrub 压平（见类注释）。
     *
     * @param mixed $value
     * @throws \InvalidArgumentException 头值含 CR/LF/NUL 或为嵌套数组/对象
     */
    public static function assertValue(mixed $value): void
    {
        if (is_string($value)) {
            if (preg_match(self::DANGEROUS, $value) === 1) {
                throw new \InvalidArgumentException('HTTP 头值不允许包含 CR/LF/NUL');
            }
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertValue($item);
            }
            return;
        }

        if (is_object($value)) {
            throw new \InvalidArgumentException('HTTP 头值不允许对象');
        }

        // int/float/bool/null 不可能携带换行
    }

    /**
     * 发射层兜底清洗：剔除 CR/LF/NUL
     */
    public static function clean(string $value): string
    {
        return preg_replace(self::DANGEROUS, '', $value) ?? '';
    }

    /**
     * 头值清洗：递归剔除字符串中的 CR/LF/NUL，其余类型原样保留
     *
     * 读写两侧统一走此路径：请求头值来自客户端，构造/解析路径必须容忍
     * 畸形输入（静默清洗保证回显/转发不造成注入，又不让畸形请求 500）；
     * 响应头值可能混入用户可控数据，同样压平而非抛错（见类注释）。
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace(self::DANGEROUS, '', $value) ?? '';
        }

        if (is_array($value)) {
            return array_map(self::scrub(...), $value);
        }

        return $value;
    }
}
