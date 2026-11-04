<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

/**
 * First-use provisioning of the machine context HMAC signing key.
 *
 * `SAND_IAM_CONTEXT_SIGNING_KEY` is documented as deployment-owned material
 * (at least 32 random bytes). A fresh plugin install has no operator step to
 * provide it, so every `context.issue`/`context.verify` failed closed with
 * `SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE` until an admin generated one by hand.
 * `ensure()` keeps the documented contract while making first use succeed:
 * when the configured value is empty it generates 64 hex bytes (32 random
 * bytes), appends exactly one entry to the host `.env` under a private file
 * lock with a one-time backup, then refreshes this process so the running
 * worker uses the new key immediately. Any declared value — including an
 * empty placeholder — is operator-owned and never overwritten or rotated.
 */
final class ContextSigningKeyProvisioner
{
    private const ENVIRONMENT_NAME = 'SAND_IAM_CONTEXT_SIGNING_KEY';
    private const CONFIG_KEY = 'plugin.sand-iam.app.context_signing_key';
    private const LOCK_NAME = '.sand-iam-security.lock';
    private const BACKUP_SUFFIX = '.sand-iam-context-original';

    /**
     * Last value resolved by this process. `Webman\Config` has no setter on
     * supported framework versions, so a key provisioned at runtime cannot be
     * reflected into the cached `plugin.*` config tree; it is kept here so
     * later calls in the same worker still see it.
     */
    private static string $resolved = '';

    private static bool $attempted = false;

    /**
     * Returns the effective signing key, provisioning it on first use when the
     * configured value is empty. Returns '' when provisioning cannot complete;
     * callers keep their existing fail-closed behaviour.
     */
    public static function ensure(): string
    {
        $configured = (string) config(self::CONFIG_KEY, '');
        if ($configured !== '') {
            return $configured;
        }
        if (self::$resolved !== '') {
            return self::$resolved;
        }
        if (self::$attempted) {
            return '';
        }
        self::$attempted = true;
        try {
            $key = self::provision();
        } catch (\Throwable) {
            return '';
        }
        if ($key !== '') {
            self::$resolved = $key;
        }
        return $key;
    }

    /** @return array{name:string,config_key:string,environment:string} for the non-PG contract test */
    public static function contract(): array
    {
        return [
            'name' => self::ENVIRONMENT_NAME,
            'config_key' => self::CONFIG_KEY,
            'environment' => 'context signing key',
        ];
    }

    private static function provision(): string
    {
        $file = run_path('.env');
        $directory = dirname($file);
        self::assertDirectory($directory);
        $lock = self::openPrivate($directory . DIRECTORY_SEPARATOR . self::LOCK_NAME);
        try {
            if (!@flock($lock, LOCK_EX)) {
                return '';
            }
            $before = @file_get_contents($file);
            if ($before === false) {
                return '';
            }
            // Another worker may have provisioned while this one waited for the
            // lock: adopt the newest declared value instead of appending a twin.
            $parsed = self::parse($before);
            $declared = trim((string) ($parsed[self::ENVIRONMENT_NAME] ?? ''));
            if ($declared !== '') {
                self::refresh($declared);
                return $declared;
            }
            // A present-but-empty declaration is operator-owned: never treat it
            // as permission to write a second entry; adopt only our own write.
            $key = bin2hex(random_bytes(32));
            $newline = str_contains($before, "\r\n") ? "\r\n" : "\n";
            $bytes = $before;
            if ($bytes !== '' && !str_ends_with($bytes, "\n") && !str_ends_with($bytes, "\r")) {
                $bytes .= $newline;
            }
            $bytes .= self::ENVIRONMENT_NAME . '=' . $key . $newline;
            self::backup($file . self::BACKUP_SUFFIX, $before);
            self::replace($file, $bytes);
            self::refresh($key);
            return $key;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function assertDirectory(string $directory): void
    {
        if (!function_exists('posix_geteuid')) {
            throw new \RuntimeException('private storage unavailable');
        }
        $info = @lstat($directory);
        if (!is_array($info) || ($info['mode'] & 0022) !== 0 || $info['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('private storage required');
        }
    }

    /** @return resource */
    private static function openPrivate(string $path): mixed
    {
        if (is_link($path)) {
            throw new \RuntimeException('private storage required');
        }
        $oldMask = umask(0077);
        try {
            $handle = @fopen($path, is_file($path) ? 'r+b' : 'x+b');
        } finally {
            umask($oldMask);
        }
        if (!is_resource($handle)) {
            throw new \RuntimeException('private storage required');
        }
        return $handle;
    }

    private static function backup(string $path, string $bytes): void
    {
        if (is_link($path) || is_file($path)) {
            return;
        }
        $oldMask = umask(0077);
        try {
            $handle = @fopen($path, 'xb');
        } finally {
            umask($oldMask);
        }
        if (is_resource($handle)) {
            fwrite($handle, $bytes);
            fflush($handle);
            fclose($handle);
        }
    }

    private static function replace(string $file, string $bytes): void
    {
        $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $oldMask = umask(0077);
        try {
            $handle = @fopen($temporary, 'xb');
        } finally {
            umask($oldMask);
        }
        if (!is_resource($handle) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
            if (is_resource($handle)) fclose($handle);
            @unlink($temporary);
            throw new \RuntimeException('private storage write failed');
        }
        fclose($handle);
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            throw new \RuntimeException('private storage replace failed');
        }
        @chmod($file, 0600);
    }

    private static function refresh(string $key): void
    {
        $_ENV[self::ENVIRONMENT_NAME] = $key;
        $_SERVER[self::ENVIRONMENT_NAME] = $key;
        @putenv(self::ENVIRONMENT_NAME . '=' . $key);
        // `Webman\Config` exposes no setter on some supported framework
        // versions; call it only when present so the post-write refresh can
        // never fail provisioning after the atomic .env write. In-process
        // callers are covered by self::$resolved and the refreshed env.
        if (class_exists(\Webman\Config::class) && method_exists(\Webman\Config::class, 'set')) {
            \Webman\Config::set(self::CONFIG_KEY, $key);
        }
    }

    /** @return array<string,string> */
    private static function parse(string $bytes): array
    {
        $values = [];
        foreach (preg_split('/\r\n|\r|\n/', $bytes) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $values[trim($name)] = trim($value, " \t\"'");
        }
        return $values;
    }
}
