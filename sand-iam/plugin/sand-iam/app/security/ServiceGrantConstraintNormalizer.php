<?php

declare(strict_types=1);

namespace plugin\SandIam\app\security;

use plugin\sandadmin\exception\ApiException;

final class ServiceGrantConstraintNormalizer
{
    private const MAX_INVOCATIONS = 2147483647;
    private const MAX_WINDOW_SECONDS = 31536000;

    /** @return array{}|array{max_invocation_attempts:int,window_seconds:int} */
    public static function quota(mixed $value, bool $runtime = false): array
    {
        if ($value === null || $value === '' || $value === []) return [];
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                self::invalid('额度规则不是有效 JSON 对象', $runtime);
            }
        }
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if ($value === []) return [];
        if (!is_array($value) || array_is_list($value)) self::invalid('额度规则必须是 JSON 对象', $runtime);
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['max_invocation_attempts', 'window_seconds']) self::invalid('额度规则只允许 max_invocation_attempts 和 window_seconds', $runtime);
        $limit = $value['max_invocation_attempts'] ?? null;
        $window = $value['window_seconds'] ?? null;
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_INVOCATIONS) self::invalid('max_invocation_attempts 必须是正整数', $runtime);
        if (!is_int($window) || $window < 1 || $window > self::MAX_WINDOW_SECONDS) self::invalid('window_seconds 必须是 1 到 31536000 的整数', $runtime);
        return ['max_invocation_attempts' => $limit, 'window_seconds' => $window];
    }

    public static function dataClass(mixed $value, bool $runtime = false): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/', $value) !== 1) {
            self::invalid('数据分级须为 2–32 位小写稳定代码', $runtime);
        }
        return $value;
    }

    public static function assertExactDataClass(?string $allowed, ?string $actual): void
    {
        if ($allowed !== $actual) throw new ApiException('SAND_IAM_DATA_CLASS_FORBIDDEN: 调用数据分级与服务授权不一致', 403);
    }

    private static function invalid(string $message, bool $runtime): never
    {
        throw new ApiException('SAND_IAM_SERVICE_GRANT_CONSTRAINT_INVALID: ' . $message, $runtime ? 403 : 400);
    }
}
