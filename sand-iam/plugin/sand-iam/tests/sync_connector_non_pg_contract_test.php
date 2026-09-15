<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** IAM-T10 generic synchronization source contract. No database is used. */
$package = dirname(__DIR__);
$root = dirname(__DIR__, 3);

$checks = [
    $package . '/app/sync/SyncDriverInterface.php' => [
        'public static function capabilities(): array',
        'pullPage(array $config, ?string $cursor, int $limit)',
        'pushBatch(array $config, array $events)',
        'public static function test(array $config): void',
    ],
    $package . '/app/service/SyncConnectorService.php' => [
        "if (++\$pages > 100)",
        'pullPage($config, $cursor, 500)',
        "hash_hmac('sha256',\$connectorId.'|'.\$value,\$pepper)",
        "where('state', 'pending')",
        'SyncOutboxAttemptPolicy',
        'SAND_IAM_SYNC_OUTBOX_NOT_RETRYABLE',
        'SAND_IAM_SYNC_OUTBOUND_PAYLOAD_INVALID',
        'sync.outbox_retry',
        "array_diff(\$accepted, \$sentIds)",
        'count($accepted) < $rowCount',
        'SAND_IAM_SYNC_DISABLE_THRESHOLD_EXCEEDED',
        'SAND_IAM_SYNC_FIELD_CONFLICT',
        'SAND_IAM_SYNC_GROUP_MAPPING_REQUIRED',
        "['postgresql'=>PostgresIdentitySyncDriver::class",
        "'microsoft_graph'=>MicrosoftGraphDirectorySyncDriver::class",
        "'google_workspace'=>GoogleWorkspaceDirectorySyncDriver::class",
        "'keycloak'=>KeycloakDirectorySyncDriver::class",
        'SAND_IAM_SYNC_DIRECTION_UNSUPPORTED',
        "(\$authority['group'] ?? 'local') !== 'source'",
        "whereIn('code', \$localCodes)",
        'Db::startTrans()',
        'IdentityLifecycleService())->disable(',
    ],
    $package . '/app/admin/controller/SyncConnectorController.php' => [
        '同步连接已创建，请继续填写连接配置并测试',
        '连接配置已加密保存，之后不回显原值',
        "'config_configured' => !empty(\$item['encrypted_config'])",
        "'cursor_configured' => !empty(\$item['encrypted_cursor'])",
        "'sand_iam:sync_run:index'",
        "'sand_iam:sync_run:run'",
        'outboxPayload',
    ],
    $package . '/app/middleware/SyncSensitiveMiddleware.php' => [
        'connector credentials, cursors and driver responses',
        'never rethrow connector secrets',
        "'Cache-Control', 'no-store'",
    ],
    $package . '/config/route.php' => [
        'sync-connector/configure',
        'sync-connector/test',
        'sync-connector/run',
        'sync-connector/runs',
        'sync-connector/outbox',
        'sync-connector/outbox-retry',
        'SyncSensitiveMiddleware::class',
    ],
    $package . '/app/sync/PostgresIdentitySyncDriver.php' => [
        "return ['inbound' => true, 'outbound' => false]",
        "str_starts_with(\$dsn, 'pgsql:')",
        'sslmode=verify-full',
        "\$pdo->exec('BEGIN READ ONLY')",
        "str_contains(\$query, ':cursor')",
        "str_contains(\$query, ':limit')",
        'SAND_IAM_SYNC_DIRECTION_UNSUPPORTED',
    ],
];

foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if (!is_string($content)) {
        fwrite(STDERR, "unreadable {$file}\n");
        exit(1);
    }
    foreach ($fragments as $fragment) {
        if (!str_contains($content, $fragment)) {
            fwrite(STDERR, "missing {$fragment} in {$file}\n");
            exit(1);
        }
    }
}

$controller = file_get_contents($package . '/app/admin/controller/SyncConnectorController.php');
if (!is_string($controller)) {
    fwrite(STDERR, "sync controller unreadable\n");
    exit(1);
}
foreach (["'encrypted_config' =>", "'encrypted_cursor' =>", "'encrypted_payload' =>"] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        fwrite(STDERR, "sync connector DTO exposes {$forbidden}\n");
        exit(1);
    }
}

$name = '015_identity_sync_connector.pgsql';
$source = $root . '/migrations/' . $name;
$copy = $package . '/migrations/' . $name;
if (!is_file($source) || !is_file($copy) || hash_file('sha256', $source) !== hash_file('sha256', $copy)) {
    fwrite(STDERR, "015 root/plugin copies differ\n");
    exit(1);
}

$sql = file_get_contents($source);
foreach ([
    'sand_iam_sync_connector',
    'sand_iam_sync_run',
    'sand_iam_sync_resource',
    'sand_iam_sync_outbox',
    "WHERE state = 'running'",
    'FOREIGN KEY (identity_id, application_id)',
    "source_state IN ('active', 'missing', 'disabled', 'conflict')",
] as $fragment) {
    if (!is_string($sql) || !str_contains($sql, $fragment)) {
        fwrite(STDERR, "015 missing {$fragment}\n");
        exit(1);
    }
}

foreach ([$source, $copy] as $file) {
    $sql = (string) file_get_contents($file);
    foreach (['ENGINE=', 'AUTO_INCREMENT', '`'] as $mysqlFragment) {
        if (str_contains($sql, $mysqlFragment)) {
            fwrite(STDERR, "MySQL syntax {$mysqlFragment} found in {$file}\n");
            exit(1);
        }
    }
}

echo "identity sync connector non-PG contract checks passed\n";
