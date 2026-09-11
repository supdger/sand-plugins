<?php

declare(strict_types=1);

namespace plugin\SandIam\app\sync;

use plugin\sandadmin\exception\ApiException;

/** Google Admin SDK Directory users.list driver. Group membership is not fetched. */
final class GoogleWorkspaceDirectorySyncDriver extends AbstractRemoteDirectorySyncDriver
{
    private const BASE_URL = 'https://admin.googleapis.com';

    /** @param array<string,mixed> $config @return array{records:list<array<string,mixed>>,next_cursor:?string,has_more:bool,full_snapshot:bool} */
    public static function pullPage(array $config, ?string $cursor, int $limit): array
    {
        self::validateConfig($config); self::assertLimit($limit); $domain = self::text($config, 'domain', '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/');
        $base = self::base($config); $token = self::token($config);
        $query = ['domain' => $domain, 'maxResults' => $limit, 'orderBy' => 'email', 'sortOrder' => 'ASC', 'showDeleted' => 'true'];
        if ($cursor !== null) $query['pageToken'] = self::cursor($cursor);
        $data = self::getJson($base . '/admin/directory/v1/users?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), ['Authorization' => 'Bearer ' . $token]);
        $rows = $data['users'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $limit) throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
            $deleted = isset($row['deletionTime']) && $row['deletionTime'] !== null;
            $email = self::email($row['primaryEmail'] ?? null);
            $name = is_array($row['name'] ?? null) ? trim((string) (($row['name']['fullName'] ?? ''))) : '';
            $records[] = self::record((string) ($row['id'] ?? ''), $name !== '' ? $name : (string) ($email ?? ''), $deleted, ['email' => $email, 'status' => $deleted ? 'deleted' : (($row['suspended'] ?? false) === true ? 'disabled' : 'enabled')]);
        }
        self::assertUniqueSubjects($records);
        $next = $data['nextPageToken'] ?? null;
        if ($next !== null && (!is_string($next) || $next === '')) throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
        return ['records' => $records, 'next_cursor' => $next === null ? null : self::cursor($next), 'has_more' => $next !== null, 'full_snapshot' => $cursor === null];
    }

    /** @param array<string,mixed> $config */
    public static function validateConfig(array $config): void
    {
        self::text($config, 'domain', '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/');
        self::base($config); self::token($config);
    }

    /** @param array<string,mixed> $config */
    private static function base(array $config): string
    {
        $configured = rtrim((string) ($config['base_url'] ?? self::BASE_URL), '/');
        if ($configured !== self::BASE_URL) throw new ApiException('SAND_IAM_SYNC_CONFIGURATION_INVALID', 400);
        return self::BASE_URL;
    }

    private static function cursor(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 1 || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)) throw new ApiException('SAND_IAM_SYNC_CURSOR_INVALID', 409);
        return $value;
    }

    private static function email(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return is_string($value) ? strtolower(trim($value)) : throw new ApiException('SAND_IAM_SYNC_REMOTE_RESPONSE_INVALID', 502);
    }
}
