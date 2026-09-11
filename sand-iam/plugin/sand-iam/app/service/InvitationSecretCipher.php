<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\sandadmin\exception\ApiException;

final class InvitationSecretCipher
{
    public function encrypt(string $plain): string
    {
        if ($plain === '' || strlen($plain) > 4096) throw new ApiException('SAND_IAM_INVITATION_SECRET_INVALID', 400);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $version = $this->version();
        return $version . '.' . rtrim(strtr(base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key($version))), '+/', '-_'), '=');
    }
    public function decrypt(string $ciphertext): string
    {
        [$version, $encoded] = array_pad(explode('.', $ciphertext, 2), 2, '');
        $wire = base64_decode(strtr($encoded, '-_', '+/'), true); $nonceSize = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($version === '' || !is_string($wire) || strlen($wire) <= $nonceSize) throw new ApiException('SAND_IAM_INVITATION_SECRET_UNAVAILABLE', 503);
        $plain = sodium_crypto_secretbox_open(substr($wire, $nonceSize), substr($wire, 0, $nonceSize), $this->key($version));
        if (!is_string($plain) || $plain === '') throw new ApiException('SAND_IAM_INVITATION_SECRET_UNAVAILABLE', 503);
        return $plain;
    }
    private function key(string $version): string
    {
        if (!function_exists('sodium_crypto_secretbox')) throw new ApiException('SAND_IAM_INVITATION_SECRET_UNAVAILABLE', 503);
        $encoded = hash_equals($this->version(), $version) ? (string) config('plugin.sand-iam.app.invitation_encryption_key', '') : '';
        if ($encoded === '') { $ring = json_decode((string) config('plugin.sand-iam.app.invitation_encryption_keys', ''), true); if (is_array($ring) && is_string($ring[$version] ?? null)) $encoded = $ring[$version]; }
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new ApiException('SAND_IAM_INVITATION_SECRET_UNAVAILABLE', 503);
        return $key;
    }
    private function version(): string { $value = (string) config('plugin.sand-iam.app.invitation_encryption_key_version', 'v1'); if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $value)) throw new ApiException('SAND_IAM_INVITATION_SECRET_UNAVAILABLE', 503); return $value; }
}
