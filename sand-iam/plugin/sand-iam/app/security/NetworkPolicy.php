<?php

declare(strict_types=1);

namespace plugin\SandIam\app\security;

use plugin\SandIam\app\radius\RadiusNetwork;
use plugin\sandadmin\exception\ApiException;

final class NetworkPolicy
{
    /** @param mixed $value @return array{allow_cidrs:list<string>,deny_cidrs:list<string>} */
    public static function normalize(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) return ['allow_cidrs' => [], 'deny_cidrs' => []];
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value) || array_diff(array_keys($value), ['allow_cidrs', 'deny_cidrs']) !== []) {
            throw new ApiException('SAND_IAM_NETWORK_POLICY_INVALID: 网络规则只允许填写允许网段和拒绝网段', 400);
        }
        return [
            'allow_cidrs' => self::cidrs($value['allow_cidrs'] ?? []),
            'deny_cidrs' => self::cidrs($value['deny_cidrs'] ?? []),
        ];
    }

    /** @param mixed $value */
    public static function allows(mixed $value, string $sourceIp): bool
    {
        $policy = self::normalize($value);
        if ($policy['allow_cidrs'] === [] && $policy['deny_cidrs'] === []) return true;
        if (inet_pton($sourceIp) === false) return false;
        foreach ($policy['deny_cidrs'] as $cidr) {
            if (RadiusNetwork::contains($cidr, $sourceIp)) return false;
        }
        if ($policy['allow_cidrs'] === []) return true;
        foreach ($policy['allow_cidrs'] as $cidr) {
            if (RadiusNetwork::contains($cidr, $sourceIp)) return true;
        }
        return false;
    }

    /** @return list<string> */
    private static function cidrs(mixed $value): array
    {
        if (!is_array($value) || count($value) > 64) throw new ApiException('SAND_IAM_NETWORK_POLICY_INVALID: 每类网段最多 64 项', 400);
        $result = [];
        foreach ($value as $cidr) {
            if (!is_string($cidr) || !RadiusNetwork::validCidr($cidr)) {
                throw new ApiException('SAND_IAM_NETWORK_POLICY_INVALID: 网段必须使用规范 IPv4/IPv6 CIDR，例如 10.20.0.0/24', 400);
            }
            $result[] = $cidr;
        }
        return array_values(array_unique($result));
    }
}
