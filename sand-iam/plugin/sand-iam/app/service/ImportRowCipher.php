<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\sandadmin\exception\ApiException;

final class ImportRowCipher
{
    /** @param array<string,mixed> $row */
    public function encrypt(array $row): string
    {
        try { $plain = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        catch (\Throwable) { throw new ApiException('SAND_IAM_IMPORT_ROW_INVALID', 400); }
        if (strlen($plain) > 16384) throw new ApiException('SAND_IAM_IMPORT_ROW_INVALID', 400);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $version = $this->version();
        return $version . '.' . rtrim(strtr(base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key($version))), '+/', '-_'), '=');
    }
    /** @return array<string,mixed> */
    public function decrypt(string $ciphertext): array
    {
        [$version, $encoded] = array_pad(explode('.', $ciphertext, 2), 2, ''); $wire = base64_decode(strtr($encoded, '-_', '+/'), true); $size = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($version === '' || !is_string($wire) || strlen($wire) <= $size) throw new ApiException('SAND_IAM_IMPORT_ROW_UNAVAILABLE', 503);
        $plain = sodium_crypto_secretbox_open(substr($wire, $size), substr($wire, 0, $size), $this->key($version));
        if (!is_string($plain)) throw new ApiException('SAND_IAM_IMPORT_ROW_UNAVAILABLE', 503);
        try { $row = json_decode($plain, true, 16, JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new ApiException('SAND_IAM_IMPORT_ROW_UNAVAILABLE', 503); }
        if (!is_array($row)) throw new ApiException('SAND_IAM_IMPORT_ROW_UNAVAILABLE', 503); return $row;
    }
    private function key(string $version): string
    {
        if (!function_exists('sodium_crypto_secretbox')) throw new ApiException('SAND_IAM_IMPORT_ROW_UNAVAILABLE', 503);
        $encoded = hash_equals($this->version(), $version) ? (string) config('plugin.sand-iam.app.import_encryption_key', '') : '';
        if ($encoded === '') { $ring = json_decode((string) config('plugin.sand-iam.app.import_encryption_keys', ''), true); if (is_array($ring) && is_string($ring[$version] ?? null)) $encoded = $ring[$version]; }
        $key = base64_decode($encoded, true); if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new ApiException('SAND_IAM_IMPORT_ROW_UNAVAILABLE', 503); return $key;
    }
    private function version(): string { $value = (string) config('plugin.sand-iam.app.import_encryption_key_version', 'v1'); if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $value)) throw new ApiException('SAND_IAM_IMPORT_ROW_UNAVAILABLE', 503); return $value; }
}
