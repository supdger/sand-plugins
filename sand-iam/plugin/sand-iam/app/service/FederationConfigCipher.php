<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\sandadmin\exception\ApiException;

final class FederationConfigCipher
{
    /** @param array<string,mixed> $config */
    public function encrypt(array $config): string
    {
        try {
            $plain = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $this->b64($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /** @return array<string,mixed> */
    public function decrypt(?string $value): array
    {
        if ($value === null || $value === '') return [];
        $wire = $this->b64d($value);
        $size = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($wire) <= $size) throw new ApiException('SAND_IAM_FEDERATION_CONFIGURATION_UNAVAILABLE', 503);
        $plain = sodium_crypto_secretbox_open(substr($wire, $size), substr($wire, 0, $size), $this->key());
        if ($plain === false) throw new ApiException('SAND_IAM_FEDERATION_CONFIGURATION_UNAVAILABLE', 503);
        try {
            $decoded = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiException('SAND_IAM_FEDERATION_CONFIGURATION_UNAVAILABLE', 503);
        }
        if (!is_array($decoded)) throw new ApiException('SAND_IAM_FEDERATION_CONFIGURATION_UNAVAILABLE', 503);
        return $decoded;
    }

    private function key(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) throw new ApiException('SAND_IAM_FEDERATION_CONFIGURATION_UNAVAILABLE', 503);
        $raw = base64_decode((string) config('plugin.sand-iam.app.federation_encryption_key', ''), true);
        if (!is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new ApiException('SAND_IAM_FEDERATION_CONFIGURATION_UNAVAILABLE', 503);
        return $raw;
    }

    private function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function b64d(string $value): string { $decoded = base64_decode(strtr($value, '-_', '+/'), true); if ($decoded === false) throw new ApiException('SAND_IAM_FEDERATION_CONFIGURATION_UNAVAILABLE', 503); return $decoded; }
}
