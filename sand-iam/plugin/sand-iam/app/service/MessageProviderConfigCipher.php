<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\sandadmin\exception\ApiException;

final class MessageProviderConfigCipher
{
    /** @param array<string,mixed> $config */
    public function encrypt(array $config): string
    {
        try {
            $plain = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable) {
            throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_INVALID', 400);
        }
        if (strlen($plain) > 32768) throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_INVALID', 400);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $version = $this->currentVersion();
        return $version . '.' . $this->encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key($version)));
    }

    /** @return array<string,mixed> */
    public function decrypt(string $ciphertext): array
    {
        [$version, $encoded] = array_pad(explode('.', $ciphertext, 2), 2, '');
        if ($version === '' || $encoded === '') throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_UNAVAILABLE', 503);
        $wire = $this->decode($encoded);
        $nonceSize = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($wire) <= $nonceSize) throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_UNAVAILABLE', 503);
        $plain = sodium_crypto_secretbox_open(substr($wire, $nonceSize), substr($wire, 0, $nonceSize), $this->key($version));
        if (!is_string($plain)) throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_UNAVAILABLE', 503);
        try {
            $config = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_UNAVAILABLE', 503);
        }
        if (!is_array($config)) throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_UNAVAILABLE', 503);
        return $config;
    }

    private function key(string $version): string
    {
        if (!function_exists('sodium_crypto_secretbox')) throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_UNAVAILABLE', 503);
        $encoded = '';
        if (hash_equals($this->currentVersion(), $version)) {
            $encoded = (string) config('plugin.sand-iam.app.message_encryption_key', '');
        } else {
            $keyring = json_decode((string) config('plugin.sand-iam.app.message_encryption_keys', ''), true);
            if (is_array($keyring) && is_string($keyring[$version] ?? null)) $encoded = $keyring[$version];
        }
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_UNAVAILABLE', 503);
        return $key;
    }

    private function currentVersion(): string
    {
        $version = (string) config('plugin.sand-iam.app.message_encryption_key_version', 'v1');
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $version)) throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_UNAVAILABLE', 503);
        return $version;
    }

    private function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded)) throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_UNAVAILABLE', 503);
        return $decoded;
    }
}
