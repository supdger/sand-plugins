<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__);
$repository = (string) file_get_contents($root . '/app/service/EloquentSecurityOperationRetentionRepository.php');
$service = (string) file_get_contents($root . '/app/service/SecurityOperationRetentionService.php');
$rateLimitRepository = (string) file_get_contents($root . '/app/service/EloquentAuthRateLimitRetentionRepository.php');
$rateLimitService = (string) file_get_contents($root . '/app/service/AuthRateLimitRetentionService.php');
$worker = (string) file_get_contents($root . '/app/process/SecurityOperationRetentionWorker.php');
$app = (string) file_get_contents($root . '/config/app.php');
$process = (string) file_get_contents($root . '/config/process.php');
$preflight = (string) file_get_contents($root . '/bin/RuntimeConfigurationPreflight.php');
$guide = (string) file_get_contents(dirname($root, 2) . '/docs/user-guide/configuration-reference.md');

function retentionContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "security operation retention contract failed: {$message}\n");
        exit(1);
    }
}

retentionContractAssert(
    str_contains($repository, "where('state', 'succeeded')")
        && str_contains($repository, "where('update_time', '<=', \$cutoff)")
        && str_contains($repository, 'limit($limit)')
        && str_contains($repository, 'lock(true)')
        && !str_contains($repository, "where('state', 'pending')"),
    'repository does not bind deletion to a locked bounded succeeded-only cutoff',
);
retentionContractAssert(
    str_contains($rateLimitRepository, "where('window_start', '<=', \$cutoff)")
        && str_contains($rateLimitRepository, "order('window_start')")
        && str_contains($rateLimitRepository, 'limit($limit)')
        && str_contains($rateLimitRepository, 'lock(true)')
        && str_contains($rateLimitRepository, "Db::table('sand_iam_auth_rate_limit')"),
    'rate-limit repository does not physically delete an exact locked expired batch',
);
retentionContractAssert(
    str_contains($rateLimitService, 'MIN_RETENTION_HOURS = 1')
        && str_contains($rateLimitService, 'MAX_RETENTION_HOURS = 168')
        && str_contains($worker, "'auth_rate_limits'")
        && str_contains($worker, 'auth_rate_limit_retention_hours'),
    'rate-limit retention is not part of the bounded operational retention tick',
);
retentionContractAssert(
    str_contains($service, 'MIN_RETENTION_DAYS = 30')
        && str_contains($service, 'MAX_RETENTION_DAYS = 3650')
        && str_contains($service, 'MAX_BATCH_SIZE = 1000'),
    'service safety bounds drifted',
);
retentionContractAssert(
    str_contains($process, "env('SAND_IAM_SECURITY_OPERATION_RETENTION_WORKER_ENABLED', '0')")
        && str_contains($process, "'handler' => SecurityOperationRetentionWorker::class")
        && str_contains($worker, 'security_operation_retention_interval_seconds'),
    'retention worker is not an independent default-off bounded process',
);
retentionContractAssert(
    str_contains($worker, "Log::info('SandIAM operational retention batch completed'")
        && str_contains($worker, "'security_operation_deleted_count'")
        && str_contains($worker, "'auth_rate_limit_deleted_count'")
        && !str_contains($worker, "'exception_message'"),
    'worker lacks secret-safe deletion observability',
);
foreach ([
    'SAND_IAM_SECURITY_OPERATION_RETENTION_WORKER_ENABLED',
    'SAND_IAM_SECURITY_OPERATION_RETENTION_DAYS',
    'SAND_IAM_SECURITY_OPERATION_RETENTION_INTERVAL_SECONDS',
    'SAND_IAM_SECURITY_OPERATION_RETENTION_BATCH_SIZE',
    'SAND_IAM_AUTH_RATE_LIMIT_RETENTION_HOURS',
] as $key) {
    retentionContractAssert(str_contains($app . $process, $key), "runtime config omits {$key}");
    retentionContractAssert(str_contains($preflight, "'{$key}'"), "offline preflight omits {$key}");
    retentionContractAssert(str_contains($guide, "`{$key}`"), "public config guide omits {$key}");
}
retentionContractAssert(str_contains($guide, 'pending 记录永不由该 worker 删除'), 'public guide does not preserve pending operations');
retentionContractAssert(str_contains($guide, '数据库删除开关'), 'public guide does not disclose destructive runtime behavior');
retentionContractAssert(str_contains($guide, '限流窗口'), 'public guide does not disclose expired rate-limit cleanup');

echo "security operation retention contract checks passed\n";
