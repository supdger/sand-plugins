<?php

declare(strict_types=1);

namespace plugin\SandIam\app\sync;

use PDO;
use plugin\sandadmin\exception\ApiException;

/** Read-only PostgreSQL keyset driver for normalized identity rows. */
final class PostgresIdentitySyncDriver implements SyncDriverInterface
{
    /** @return array{inbound:bool,outbound:bool} */
    public static function capabilities(): array
    {
        return ['inbound' => true, 'outbound' => false];
    }

    /** @param array<string,mixed> $config @return array{records:list<array<string,mixed>>,next_cursor:?string,has_more:bool,full_snapshot:bool} */
    public static function pullPage(array $config, ?string $cursor, int $limit): array
    {
        $settings = self::settings($config);
        if ($limit < 1 || $limit > 500 || ($cursor !== null && strlen($cursor) > 512)) throw new ApiException('SAND_IAM_SYNC_DRIVER_REQUEST_INVALID', 400);

        $pdo = self::connect($settings);
        try {
            self::beginReadOnly($pdo, $settings['statement_timeout_ms']);
            $statement = $pdo->prepare($settings['query']);
            if ($statement === false) throw new ApiException('SAND_IAM_SYNC_DRIVER_QUERY_INVALID', 400);
            $statement->bindValue(':cursor', $cursor ?? '', PDO::PARAM_STR);
            $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
            if (!$statement->execute()) throw new ApiException('SAND_IAM_SYNC_DRIVER_UNAVAILABLE', 503);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) throw new ApiException('SAND_IAM_SYNC_DRIVER_RESPONSE_INVALID', 503);
            $pdo->rollBack();
        } catch (ApiException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        } catch (\Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new ApiException('SAND_IAM_SYNC_DRIVER_UNAVAILABLE', 503);
        }

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $records = [];
        foreach ($rows as $row) $records[] = self::record($row);
        $nextCursor = $records === [] ? null : $records[array_key_last($records)]['source_id'];

        return [
            'records' => $records,
            'next_cursor' => $hasMore ? $nextCursor : null,
            'has_more' => $hasMore,
            'full_snapshot' => $cursor === null,
        ];
    }

    /** @param array<string,mixed> $config @param list<array<string,mixed>> $events @return list<string> */
    public static function pushBatch(array $config, array $events): array
    {
        throw new ApiException('SAND_IAM_SYNC_DIRECTION_UNSUPPORTED', 409);
    }

    /** @param array<string,mixed> $config */
    public static function test(array $config): void
    {
        $settings = self::settings($config);
        $pdo = self::connect($settings);
        try {
            self::beginReadOnly($pdo, $settings['statement_timeout_ms']);
            $statement = $pdo->query('SELECT 1');
            if ($statement === false || $statement->fetchColumn() !== 1) throw new ApiException('SAND_IAM_SYNC_DRIVER_UNAVAILABLE', 503);
            $pdo->rollBack();
        } catch (ApiException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        } catch (\Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new ApiException('SAND_IAM_SYNC_DRIVER_UNAVAILABLE', 503);
        }
    }

    /** @param array<string,mixed> $config @return array{dsn:string,username:string,password:string,query:string,connect_timeout:int,statement_timeout_ms:int} */
    private static function settings(array $config): array
    {
        $dsn = trim((string) ($config['dsn'] ?? ''));
        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');
        $query = trim((string) ($config['query'] ?? ''));
        if (!str_starts_with($dsn, 'pgsql:') || !preg_match('/(?:^|;)sslmode=verify-full(?:;|$)/i', substr($dsn, 6))) throw new ApiException('SAND_IAM_SYNC_POSTGRES_TLS_REQUIRED', 400);
        if ($username === '' || $password === '' || strlen($username) > 128 || strlen($password) > 2048) throw new ApiException('SAND_IAM_SYNC_CONFIGURATION_INVALID', 400);
        if (strlen($query) < 16 || strlen($query) > 16_384 || !preg_match('/^(SELECT|WITH)\b/i', $query)) throw new ApiException('SAND_IAM_SYNC_DRIVER_QUERY_INVALID', 400);
        if (!str_contains($query, ':cursor') || !str_contains($query, ':limit')) throw new ApiException('SAND_IAM_SYNC_DRIVER_QUERY_INVALID', 400);
        if (preg_match('/;|--|\/\*|\*\/|\b(INSERT|UPDATE|DELETE|MERGE|COPY|CALL|DO|ALTER|CREATE|DROP|TRUNCATE|GRANT|REVOKE|VACUUM|ANALYZE|REFRESH|LOCK)\b/i', $query)) throw new ApiException('SAND_IAM_SYNC_DRIVER_QUERY_INVALID', 400);

        return [
            'dsn' => $dsn,
            'username' => $username,
            'password' => $password,
            'query' => $query,
            'connect_timeout' => min(max((int) ($config['connect_timeout_seconds'] ?? 10), 1), 30),
            'statement_timeout_ms' => min(max((int) ($config['statement_timeout_ms'] ?? 30_000), 1_000), 120_000),
        ];
    }

    /** @param array{dsn:string,username:string,password:string,connect_timeout:int} $settings */
    private static function connect(array $settings): PDO
    {
        if (!extension_loaded('pdo_pgsql')) throw new ApiException('SAND_IAM_SYNC_POSTGRES_DRIVER_UNAVAILABLE', 503);
        try {
            return new PDO($settings['dsn'], $settings['username'], $settings['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => $settings['connect_timeout'],
            ]);
        } catch (\Throwable) {
            throw new ApiException('SAND_IAM_SYNC_DRIVER_UNAVAILABLE', 503);
        }
    }

    private static function beginReadOnly(PDO $pdo, int $statementTimeoutMs): void
    {
        $pdo->exec('BEGIN READ ONLY');
        $pdo->exec('SET LOCAL statement_timeout = ' . $statementTimeoutMs);
    }

    /** @param array<string,mixed> $row @return array{source_id:string,version:string,display_name:string,deleted:bool,attributes:array<string,mixed>} */
    private static function record(array $row): array
    {
        $sourceId = trim((string) ($row['source_id'] ?? ''));
        $version = trim((string) ($row['source_version'] ?? ''));
        $displayName = trim((string) ($row['display_name'] ?? ''));
        $deleted = filter_var($row['deleted'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($sourceId === '' || strlen($sourceId) > 256 || $version === '' || strlen($version) > 128 || $deleted === null || (!$deleted && ($displayName === '' || mb_strlen($displayName) > 128))) throw new ApiException('SAND_IAM_SYNC_RECORD_INVALID', 400);

        $groupCodes = self::groupCodes($row['group_codes'] ?? []);
        $attributes = ['group_codes' => $groupCodes];
        if (isset($row['attributes_json'])) {
            $extra = is_array($row['attributes_json']) ? $row['attributes_json'] : json_decode((string) $row['attributes_json'], true, 32);
            if (!is_array($extra)) throw new ApiException('SAND_IAM_SYNC_RECORD_INVALID', 400);
            $attributes += $extra;
        }

        return ['source_id' => $sourceId, 'version' => $version, 'display_name' => $displayName, 'deleted' => $deleted, 'attributes' => $attributes];
    }

    /** @return list<string> */
    private static function groupCodes(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode('|', $value);
        }
        if (!is_array($value) || count($value) > 100) throw new ApiException('SAND_IAM_SYNC_RECORD_INVALID', 400);
        $codes = [];
        foreach ($value as $code) {
            if (!is_string($code) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/', $code)) throw new ApiException('SAND_IAM_SYNC_RECORD_INVALID', 400);
            $codes[] = $code;
        }
        return array_values(array_unique($codes));
    }
}
