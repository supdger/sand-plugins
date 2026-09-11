<?php

declare(strict_types=1);

namespace plugin\SandIam\app\sync;

use plugin\sandadmin\exception\ApiException;

/** Microsoft Graph users delta driver. Group membership is deliberately not fetched. */
final class MicrosoftGraphDirectorySyncDriver extends AbstractRemoteDirectorySyncDriver
{
    private const BASE_URL = 'https://graph.microsoft.com/v1.0';

    /** @param array<string,mixed> $config @return array{records:list<array<string,mixed>>,next_cursor:?string,has_more:bool,full_snapshot:bool} */
    public static function pullPage(array $config, ?string $cursor, int $limit): array
    {
        self::validateConfig($config); self::assertLimit($limit);
        $base = self::base($config); $token = self::token($config);
        $url = $cursor === null
            ? $base . '/users/delta?' . http_build_query(['$select' => 'id,displayName,mail,userPrincipalName,accountEnabled'], '', '&', PHP_QUERY_RFC3986)
            : self::continuation($cursor, $base);
        $data = self::getJson($url, ['Authorization' => 'Bearer ' . $token]);
        $rows = $data['value'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $limit) throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
            $deleted = is_array($row['@removed'] ?? null);
            $subject = (string) ($row['id'] ?? '');
            $display = (string) ($row['displayName'] ?? '');
            $email = self::email($row['mail'] ?? $row['userPrincipalName'] ?? null);
            $records[] = self::record($subject, $display, $deleted, ['email' => $email, 'status' => $deleted ? 'deleted' : (($row['accountEnabled'] ?? true) === true ? 'enabled' : 'disabled')]);
        }
        self::assertUniqueSubjects($records);
        $next = $data['@odata.nextLink'] ?? null; $delta = $data['@odata.deltaLink'] ?? null;
        if ($next !== null && $delta !== null) throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
        if ($next !== null) return ['records' => $records, 'next_cursor' => self::continuation($next, $base), 'has_more' => true, 'full_snapshot' => $cursor === null];
        if ($delta === null) throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
        return ['records' => $records, 'next_cursor' => self::continuation($delta, $base), 'has_more' => false, 'full_snapshot' => $cursor === null];
    }

    /** @param array<string,mixed> $config */
    public static function validateConfig(array $config): void
    {
        self::text($config, 'tenant_id', '/^[A-Za-z0-9.-]{1,128}$/');
        self::base($config); self::token($config);
    }

    /** @param array<string,mixed> $config */
    private static function base(array $config): string
    {
        $configured = rtrim((string) ($config['base_url'] ?? self::BASE_URL), '/');
        if ($configured !== self::BASE_URL) throw new ApiException('SAND_IAM_SYNC_CONFIGURATION_INVALID', 400);
        return self::BASE_URL;
    }

    private static function continuation(mixed $cursor, string $base): string
    {
        if (!is_string($cursor) || strlen($cursor) < strlen($base) || strlen($cursor) > 8192) throw new ApiException('SAND_IAM_SYNC_CURSOR_INVALID', 409);
        $parts = parse_url($cursor); $baseParts = parse_url($base);
        if (!is_array($parts) || !is_array($baseParts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== ($baseParts['host'] ?? null) || ($parts['port'] ?? 443) !== ($baseParts['port'] ?? 443) || !str_starts_with((string) ($parts['path'] ?? ''), '/v1.0/users/delta') || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) throw new ApiException('SAND_IAM_SYNC_CURSOR_INVALID', 409);
        return $cursor;
    }

    private static function email(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return is_string($value) ? strtolower(trim($value)) : throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
    }
}
