<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\sandadmin\exception\ApiException;

final class RadiusSecretCipher
{
    public function encrypt(string $plain): string
    {
        if (strlen($plain) < 16 || strlen($plain) > 128 || preg_match('/[\x00-\x1f\x7f]/', $plain)) throw new ApiException('SAND_IAM_RADIUS_SHARED_SECRET_INVALID', 400);
        $version = $this->version();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $version . '.' . rtrim(strtr(base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key($version))), '+/', '-_'), '=');
    }

    public function decrypt(string $ciphertext): string
    {
        [$version, $encoded] = array_pad(explode('.', $ciphertext, 2), 2, '');
        $wire = base64_decode(strtr($encoded, '-_', '+/'), true);
        $size = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($version === '' || !is_string($wire) || strlen($wire) <= $size) throw new ApiException('SAND_IAM_RADIUS_SHARED_SECRET_UNAVAILABLE', 503);
        $plain = sodium_crypto_secretbox_open(substr($wire, $size), substr($wire, 0, $size), $this->key($version));
        if (!is_string($plain) || strlen($plain) < 16 || strlen($plain) > 128) throw new ApiException('SAND_IAM_RADIUS_SHARED_SECRET_UNAVAILABLE', 503);
        return $plain;
    }

    private function key(string $version): string
    {
        if (!function_exists('sodium_crypto_secretbox')) throw new ApiException('SAND_IAM_RADIUS_SHARED_SECRET_UNAVAILABLE', 503);
        $encoded = hash_equals($this->version(), $version) ? (string) config('plugin.sand-iam.app.radius_encryption_key', '') : '';
        if ($encoded === '') {
            $ring = json_decode((string) config('plugin.sand-iam.app.radius_encryption_keys', ''), true);
            if (is_array($ring) && is_string($ring[$version] ?? null)) $encoded = $ring[$version];
        }
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new ApiException('SAND_IAM_RADIUS_SHARED_SECRET_UNAVAILABLE', 503);
        return $key;
    }

    private function version(): string
    {
        $version = (string) config('plugin.sand-iam.app.radius_encryption_key_version', 'v1');
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $version)) throw new ApiException('SAND_IAM_RADIUS_SHARED_SECRET_UNAVAILABLE', 503);
        return $version;
    }
}
