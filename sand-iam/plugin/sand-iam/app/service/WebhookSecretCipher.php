<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\sandadmin\exception\ApiException;

final class WebhookSecretCipher
{
    public function encrypt(string $secret): string
    {
        if ($secret === '') throw new ApiException('SAND_IAM_WEBHOOK_SECRET_INVALID', 500);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $version = $this->currentVersion();
        return $version . '.' . $this->encode($nonce . sodium_crypto_secretbox($secret, $nonce, $this->key($version)));
    }

    public function decrypt(string $ciphertext): string
    {
        [$version, $encoded] = array_pad(explode('.', $ciphertext, 2), 2, '');
        if ($version === '' || $encoded === '') throw new ApiException('SAND_IAM_WEBHOOK_SECRET_UNAVAILABLE', 503);
        $wire = $this->decode($encoded);
        $nonceSize = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($wire) <= $nonceSize) throw new ApiException('SAND_IAM_WEBHOOK_SECRET_UNAVAILABLE', 503);
        $plain = sodium_crypto_secretbox_open(substr($wire, $nonceSize), substr($wire, 0, $nonceSize), $this->key($version));
        if (!is_string($plain) || $plain === '') throw new ApiException('SAND_IAM_WEBHOOK_SECRET_UNAVAILABLE', 503);
        return $plain;
    }

    private function key(string $version): string
    {
        if (!function_exists('sodium_crypto_secretbox')) throw new ApiException('SAND_IAM_WEBHOOK_SECRET_UNAVAILABLE', 503);
        $encoded = '';
        if (hash_equals($this->currentVersion(), $version)) {
            $encoded = (string) config('plugin.sand-iam.app.webhook_encryption_key', '');
        } else {
            $keyring = json_decode((string) config('plugin.sand-iam.app.webhook_encryption_keys', ''), true);
            if (is_array($keyring) && is_string($keyring[$version] ?? null)) $encoded = $keyring[$version];
        }
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new ApiException('SAND_IAM_WEBHOOK_SECRET_UNAVAILABLE', 503);
        }
        return $key;
    }

    private function currentVersion(): string
    {
        $version = (string) config('plugin.sand-iam.app.webhook_encryption_key_version', 'v1');
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $version)) throw new ApiException('SAND_IAM_WEBHOOK_SECRET_UNAVAILABLE', 503);
        return $version;
    }

    private function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded)) throw new ApiException('SAND_IAM_WEBHOOK_SECRET_UNAVAILABLE', 503);
        return $decoded;
    }
}
