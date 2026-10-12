<?php

declare(strict_types=1);

namespace Kode\Http\Psr7;

/**
 * HTTP 头名/头值校验与发射层清洗
 *
 * 防线分两层：
 * 1. 写入层（withHeader/withStatus/withProtocolVersion 等）：按 PSR-7 要求
 *    抛 InvalidArgumentException，拒绝非法头名（RFC 7230 token）与含
 *    CR/LF/NUL 的头值——这是响应头注入（HTTP splitting）的根因。
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
     * 校验头值（含数组形式的多值头），非法则抛出异常
     *
     * 字符串禁止 CR/LF/NUL；嵌套数组/对象拒绝；标量天然不含换行直接放行。
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
     * 读侧（请求头）清洗：递归剔除字符串中的 CR/LF/NUL，其余类型原样保留
     *
     * 请求头值来自客户端，构造/解析路径必须容忍畸形输入（如解析器宽容
     * 产出的换行残留）：静默清洗保证后续任何回显/转发不会造成响应头注入，
     * 又不会让畸形请求直接 500。写侧（响应头）不适用——应 assert 抛错。
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
