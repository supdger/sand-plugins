<?php

declare(strict_types=1);

namespace plugin\SandIam\app\radius;

final class RadiusNetwork
{
    public static function validCidr(string $cidr): bool
    {
        [$address, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        $binary = inet_pton($address);
        if ($binary === false || $prefix === null || !ctype_digit($prefix)) return false;
        $bits = strlen($binary) * 8;
        return (int) $prefix >= 0 && (int) $prefix <= $bits && self::canonical($binary, (int) $prefix) === $binary;
    }

    public static function contains(string $cidr, string $ip): bool
    {
        if (!self::validCidr($cidr)) return false;
        [$address, $prefix] = explode('/', $cidr, 2);
        $network = inet_pton($address);
        $candidate = inet_pton($ip);
        if ($network === false || $candidate === false || strlen($network) !== strlen($candidate)) return false;
        return hash_equals(self::canonical($network, (int) $prefix), self::canonical($candidate, (int) $prefix));
    }

    private static function canonical(string $binary, int $prefix): string
    {
        $bytes = intdiv($prefix, 8);
        $remaining = $prefix % 8;
        $result = $bytes > 0 ? substr($binary, 0, $bytes) : '';
        if ($remaining > 0) $result .= chr(ord($binary[$bytes]) & (0xff << (8 - $remaining)));
        return str_pad($result, strlen($binary), "\0");
    }
}
