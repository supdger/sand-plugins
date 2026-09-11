<?php

declare(strict_types=1);

namespace plugin\SandIam\app\sync;

use plugin\sandadmin\exception\ApiException;

/** Keycloak Admin REST users driver. User groups require a separate endpoint and are not guessed. */
final class KeycloakDirectorySyncDriver extends AbstractRemoteDirectorySyncDriver
{
    /** @param array<string,mixed> $config @return array{records:list<array<string,mixed>>,next_cursor:?string,has_more:bool,full_snapshot:bool} */
    public static function pullPage(array $config, ?string $cursor, int $limit): array
    {
        self::validateConfig($config); self::assertLimit($limit); $base = self::base($config); $realm = self::text($config, 'realm', '/^[A-Za-z0-9._-]{1,255}$/'); $token = self::token($config);
        $first = self::cursor($cursor); $path = '/admin/realms/' . rawurlencode($realm) . '/users?' . http_build_query(['first' => $first, 'max' => $limit, 'briefRepresentation' => 'true'], '', '&', PHP_QUERY_RFC3986);
        $rows = self::getJson($base . $path, ['Authorization' => 'Bearer ' . $token]);
        if (!array_is_list($rows) || count($rows) > $limit) throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
            $email = self::email($row['email'] ?? null); $display = trim((string) ($row['firstName'] ?? '') . ' ' . (string) ($row['lastName'] ?? ''));
            if ($display === '') $display = (string) ($row['username'] ?? $email ?? '');
            $records[] = self::record((string) ($row['id'] ?? ''), $display, false, ['email' => $email, 'status' => ($row['enabled'] ?? true) === true ? 'enabled' : 'disabled']);
        }
        self::assertUniqueSubjects($records);
        $hasMore = count($records) === $limit;
        return ['records' => $records, 'next_cursor' => $hasMore ? (string) ($first + $limit) : null, 'has_more' => $hasMore, 'full_snapshot' => $cursor === null];
    }

    /** @param array<string,mixed> $config */
    public static function validateConfig(array $config): void
    {
        self::base($config); self::text($config, 'realm', '/^[A-Za-z0-9._-]{1,255}$/'); self::token($config);
    }

    /** @param array<string,mixed> $config */
    private static function base(array $config): string
    {
        $value = rtrim((string) ($config['base_url'] ?? ''), '/'); $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || strlen($value) > 512) throw new ApiException('SAND_IAM_SYNC_CONFIGURATION_INVALID', 400);
        return $value;
    }

    private static function cursor(?string $value): int
    {
        if ($value === null) return 0;
        if (preg_match('/^(?:0|[1-9][0-9]{0,8})$/', $value) !== 1) throw new ApiException('SAND_IAM_SYNC_CURSOR_INVALID', 409);
        return (int) $value;
    }

    private static function email(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return is_string($value) ? strtolower(trim($value)) : throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
    }
}
