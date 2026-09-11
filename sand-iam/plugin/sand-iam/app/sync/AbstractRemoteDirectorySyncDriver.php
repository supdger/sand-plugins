<?php

declare(strict_types=1);

namespace plugin\SandIam\app\sync;

use plugin\sandadmin\exception\ApiException;

abstract class AbstractRemoteDirectorySyncDriver implements SyncDriverInterface
{
    private const MAX_LIMIT = 500;
    private const MAX_RETRIES = 2;

    /** @return array{inbound:bool,outbound:bool} */
    final public static function capabilities(): array { return ['inbound' => true, 'outbound' => false]; }

    /** @param array<string,mixed> $config @param list<array<string,mixed>> $events @return list<string> */
    final public static function pushBatch(array $config, array $events): array { throw new ApiException('SAND_IAM_SYNC_DIRECTION_UNSUPPORTED', 409); }

    /** @param array<string,mixed> $config */
    final public static function test(array $config): void { static::pullPage($config, null, self::MAX_LIMIT); }

    /** @param array<string,string> $headers @return array<string,mixed> */
    final protected static function getJson(string $url, array $headers): array
    {
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response = RemoteDirectoryTransportRegistry::transport()->get($url, $headers);
            if (!is_int($response['status'] ?? null) || !is_array($response['headers'] ?? null) || !is_string($response['body'] ?? null)) throw new ApiException('SAND_IAM_SYNC_DRIVER_RESPONSE_INVALID', 503);
            if ($response['status'] === 429 && $attempt < self::MAX_RETRIES) {
                $retry = self::retryAfter($response['headers']['retry-after'] ?? null);
                if ($retry === null) throw new ApiException('SAND_IAM_SYNC_REMOTE_RATE_LIMITED', 429);
                if ($retry > 0) usleep($retry * 1_000_000);
                continue;
            }
            if ($response['status'] < 200 || $response['status'] >= 300) throw new ApiException($response['status'] === 429 ? 'SAND_IAM_SYNC_REMOTE_RATE_LIMITED' : 'SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', $response['status'] === 429 ? 429 : 502);
            try { $decoded = json_decode($response['body'], true, 128, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502); }
            if (!is_array($decoded)) throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
            return $decoded;
        }
        throw new ApiException('SAND_IAM_SYNC_REMOTE_RATE_LIMITED', 429);
    }

    final protected static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) throw new ApiException('SAND_IAM_SYNC_DRIVER_REQUEST_INVALID', 400);
    }

    final protected static function token(array $config): string
    {
        $token = trim((string) ($config['access_token'] ?? ''));
        if ($token === '' || strlen($token) > 16_384 || preg_match('/[\x00-\x1f\x7f]/', $token)) throw new ApiException('SAND_IAM_SYNC_CONFIGURATION_INVALID', 400);
        return $token;
    }

    final protected static function text(array $config, string $field, string $pattern, int $max = 255): string
    {
        $value = trim((string) ($config[$field] ?? ''));
        if ($value === '' || strlen($value) > $max || preg_match($pattern, $value) !== 1) throw new ApiException('SAND_IAM_SYNC_CONFIGURATION_INVALID', 400);
        return $value;
    }

    /** @param array<string,mixed> $row @return array{source_id:string,version:string,display_name:string,deleted:bool,attributes:array<string,mixed>} */
    final protected static function record(string $subject, string $displayName, bool $deleted, array $attributes): array
    {
        $subject = trim($subject); $displayName = trim($displayName);
        if ($subject === '' || strlen($subject) > 256 || (!$deleted && ($displayName === '' || mb_strlen($displayName) > 128))) throw new ApiException('SAND_IAM_SYNC_RECORD_INVALID', 400);
        $email = $attributes['email'] ?? null;
        if ($email !== null && (!is_string($email) || strlen($email) > 320 || !filter_var($email, FILTER_VALIDATE_EMAIL))) throw new ApiException('SAND_IAM_SYNC_RECORD_INVALID', 400);
        $attributes = ['external_subject' => $subject] + $attributes;
        $version = substr(hash('sha256', json_encode([$subject, $displayName, $deleted, $attributes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 64);
        return ['source_id' => $subject, 'version' => $version, 'display_name' => $displayName, 'deleted' => $deleted, 'attributes' => $attributes];
    }

    /** @param list<array<string,mixed>> $records */
    final protected static function assertUniqueSubjects(array $records): void
    {
        $subjects = [];
        foreach ($records as $record) {
            $subject = (string) ($record['source_id'] ?? '');
            if ($subject === '' || isset($subjects[$subject])) throw new ApiException('SAND_IAM_SYNC_DUPLICATE_SUBJECT', 409);
            $subjects[$subject] = true;
        }
    }

    private static function retryAfter(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^\d{1,2}$/', trim($value)) !== 1) return null;
        $seconds = (int) trim($value);
        return $seconds <= 5 ? $seconds : null;
    }
}
