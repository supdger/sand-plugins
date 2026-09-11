<?php

declare(strict_types=1);

use plugin\SandIam\app\sync\PostgresIdentitySyncDriver;
use plugin\sandadmin\exception\ApiException;

function t10PostgresDriverFail(string $message): never
{
    fwrite(STDERR, "IAM-T10 PostgreSQL sync driver test failed: {$message}\n");
    exit(1);
}

function t10PostgresDriverExpect(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if (str_contains($exception->getMessage(), $code)) return;
    }
    t10PostgresDriverFail("expected {$code}");
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$package = dirname(__DIR__);
if (!is_file($hostRoot . '/vendor/autoload.php')) t10PostgresDriverFail('SandAdmin host dependencies are unavailable');
require $hostRoot . '/vendor/autoload.php';
require_once $package . '/app/sync/SyncDriverInterface.php';
require_once $package . '/app/sync/PostgresIdentitySyncDriver.php';

$query = 'SELECT id::text AS source_id, updated_at::text AS source_version, display_name, false AS deleted, group_codes FROM external_users WHERE id::text > :cursor ORDER BY id::text LIMIT :limit';
$config = [
    'dsn' => 'pgsql:host=db.example.test;dbname=source;sslmode=verify-full',
    'username' => 'sync_reader',
    'password' => 'not-a-real-password',
    'query' => $query,
    'connect_timeout_seconds' => 60,
    'statement_timeout_ms' => 500_000,
];

$settingsMethod = new ReflectionMethod(PostgresIdentitySyncDriver::class, 'settings');
$settingsMethod->setAccessible(true);
$settings = $settingsMethod->invoke(null, $config);
if (($settings['connect_timeout'] ?? 0) !== 30 || ($settings['statement_timeout_ms'] ?? 0) !== 120_000) t10PostgresDriverFail('timeout bounds failed');

t10PostgresDriverExpect(static fn () => $settingsMethod->invoke(null, array_replace($config, ['dsn' => ''])), 'SAND_IAM_SYNC_POSTGRES_TLS_REQUIRED');
t10PostgresDriverExpect(static fn () => $settingsMethod->invoke(null, array_replace($config, ['dsn' => 'pgsql:host=db.example.test;dbname=source;sslmode=require'])), 'SAND_IAM_SYNC_POSTGRES_TLS_REQUIRED');
t10PostgresDriverExpect(static fn () => $settingsMethod->invoke(null, array_replace($config, ['query' => 'DELETE FROM external_users WHERE id::text > :cursor LIMIT :limit'])), 'SAND_IAM_SYNC_DRIVER_QUERY_INVALID');
t10PostgresDriverExpect(static fn () => $settingsMethod->invoke(null, array_replace($config, ['query' => $query . '; SELECT 1'])), 'SAND_IAM_SYNC_DRIVER_QUERY_INVALID');
t10PostgresDriverExpect(static fn () => $settingsMethod->invoke(null, array_replace($config, ['query' => str_replace(':cursor', "''", $query)])), 'SAND_IAM_SYNC_DRIVER_QUERY_INVALID');

$recordMethod = new ReflectionMethod(PostgresIdentitySyncDriver::class, 'record');
$recordMethod->setAccessible(true);
$record = $recordMethod->invoke(null, [
    'source_id' => 'user-100',
    'source_version' => '2026-08-21T12:00:00Z',
    'display_name' => '来源用户',
    'deleted' => false,
    'group_codes' => '["lawyer","reviewer"]',
    'attributes_json' => '{"department":"法务部"}',
]);
if (($record['attributes']['group_codes'] ?? []) !== ['lawyer', 'reviewer'] || ($record['attributes']['department'] ?? '') !== '法务部') t10PostgresDriverFail('record normalization failed');
t10PostgresDriverExpect(static fn () => $recordMethod->invoke(null, ['source_id' => '', 'source_version' => '1', 'display_name' => '用户']), 'SAND_IAM_SYNC_RECORD_INVALID');

if (PostgresIdentitySyncDriver::capabilities() !== ['inbound' => true, 'outbound' => false]) t10PostgresDriverFail('capabilities mismatch');
t10PostgresDriverExpect(static fn () => PostgresIdentitySyncDriver::pushBatch([], []), 'SAND_IAM_SYNC_DIRECTION_UNSUPPORTED');

echo "PostgreSQL identity sync driver non-PG tests passed\n";
