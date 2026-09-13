<?php

declare(strict_types=1);

/** Verifies a detached Ed25519 receipt with an operator-supplied trusted key. */
final class ConsumerAcceptanceV2ReceiptVerifier
{
    /** @return array<string,mixed> */
    public static function readLocalJson(string $file): array
    {
        try { $value = json_decode(self::readSafeLocalFile($file, 'observed capability file'), true, 512, JSON_THROW_ON_ERROR); } catch (JsonException $error) { throw new InvalidArgumentException('observed capability file must be JSON', 0, $error); }
        if (!is_array($value) || array_is_list($value)) throw new InvalidArgumentException('observed capability file must be a JSON object');
        return $value;
    }
    /** @param array<string,mixed> $receipt */
    public static function verify(array $receipt, string $trustedKeyFile, DateTimeImmutable $now): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) throw new RuntimeException('Ed25519 sodium support is required');
        $trusted = self::trustedKey($trustedKeyFile);
        $key = $trusted['key'];
        if (($receipt['algorithm'] ?? null) !== 'Ed25519' || !is_string($receipt['key_id'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{2,95}$/D', $receipt['key_id']) !== 1 || !is_string($receipt['public_key_sha256'] ?? null) || !hash_equals($trusted['sha256'], $receipt['public_key_sha256'])) throw new InvalidArgumentException('receipt algorithm or trusted key binding is invalid');
        if (!isset($receipt['signature']) || !is_string($receipt['signature'])) throw new InvalidArgumentException('receipt signature is required');
        $signature = base64_decode($receipt['signature'], true);
        if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) throw new InvalidArgumentException('receipt signature is invalid');
        $signed = $receipt; unset($signed['signature']);
        if (!sodium_crypto_sign_verify_detached($signature, self::canonical($signed), $key)) throw new RuntimeException('receipt signature does not verify against trusted key');
        foreach (['issued_at', 'expires_at'] as $field) if (!is_string($receipt[$field] ?? null)) throw new InvalidArgumentException('receipt ' . $field . ' is required');
        $issued = self::utc($receipt['issued_at'], 'receipt.issued_at');
        $expires = self::utc($receipt['expires_at'], 'receipt.expires_at');
        if ($expires <= $issued || $now < $issued || $now >= $expires) throw new RuntimeException('receipt is not within its valid UTC window');
    }

    /** @param array<string,mixed> $value */
    public static function canonical(array $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed>|list<mixed> */
    private static function sort(array $value): array
    {
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = self::sort($item);
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        return $value;
    }

    /** @return array{key:string,sha256:string} */
    private static function trustedKey(string $file): array
    {
        $encoded = trim(self::readSafeLocalFile($file, 'trusted key'));
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new RuntimeException('trusted key must contain one Ed25519 public key');
        return ['key' => $key, 'sha256' => hash('sha256', $key)];
    }

    private static function readSafeLocalFile(string $file, string $label): string
    {
        if ($file === '' || !str_starts_with($file, '/') || str_contains($file, "\0") || preg_match('/^[a-z][a-z0-9+.-]*:/i', $file) === 1) throw new InvalidArgumentException($label . ' must be an absolute local file');
        if (str_contains($file, '//')) throw new InvalidArgumentException($label . ' must not contain repeated path separators');
        self::assertOutsideSourceTree($file, $label);
        $current = '/';
        foreach (explode('/', dirname($file)) as $part) {
            if ($part === '') continue;
            if ($part === '.' || $part === '..') throw new InvalidArgumentException($label . ' path traversal is forbidden');
            $current .= ($current === '/' ? '' : '/') . $part;
            $stat = lstat($current);
            if ($stat === false || ($stat['mode'] & 0170000) === 0120000 || ($stat['mode'] & 0170000) !== 0040000) throw new RuntimeException($label . ' has missing, symlink, or non-directory parent');
            self::assertSafeParent($stat, $label);
        }
        $before = self::checkedFileStat($file, $label);
        $stream = @fopen($file, 'rb');
        if (!is_resource($stream)) throw new RuntimeException($label . ' cannot be opened safely');
        try {
            $opened = fstat($stream);
            if ($opened === false) throw new RuntimeException($label . ' cannot be inspected after open');
            self::assertSafeFile($opened, $label);
            $after = lstat($file);
            if ($after === false || !self::sameFile($before, $opened) || !self::sameFile($after, $opened)) throw new RuntimeException($label . ' changed while being opened');
            $data = stream_get_contents($stream);
            if (!is_string($data)) throw new RuntimeException($label . ' cannot be read');
            return $data;
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string,int> $stat */
    private static function assertSafeParent(array $stat, string $label): void
    {
        if (($stat['mode'] & 0022) !== 0 && ($stat['mode'] & 01000) === 0) throw new RuntimeException($label . ' parent is group/world writable without sticky protection');
    }

    /** @return array<string,int> */
    private static function checkedFileStat(string $file, string $label): array
    {
        $stat = lstat($file);
        if ($stat === false) throw new RuntimeException($label . ' must exist');
        self::assertSafeFile($stat, $label);
        return $stat;
    }

    /** @param array<string,int> $stat */
    private static function assertSafeFile(array $stat, string $label): void
    {
        if (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) throw new RuntimeException($label . ' must be a non-hardlinked regular file');
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (!is_int($uid) || (int) $stat['uid'] !== $uid || ($stat['mode'] & 0022) !== 0) throw new RuntimeException($label . ' must be current-user owned and not group/world writable');
    }

    /** @param array<string,int> $before @param array<string,int> $after */
    private static function sameFile(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid'] as $field) if (($before[$field] ?? null) !== ($after[$field] ?? null)) return false;
        return true;
    }

    private static function assertOutsideSourceTree(string $file, string $label): void
    {
        $source = dirname(__DIR__, 3);
        $workspace = dirname($source);
        foreach ([$source . '/', $workspace . '/.artifacts/'] as $forbidden) {
            if (str_starts_with($file, $forbidden)) throw new RuntimeException($label . ' cannot be inside repository source or candidate roots');
        }
    }

    private static function utc(string $value, string $label): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) throw new InvalidArgumentException($label . ' must be strict UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) || $date->format('Y-m-d\TH:i:s\Z') !== $value) throw new InvalidArgumentException($label . ' is invalid');
        return $date;
    }
}
