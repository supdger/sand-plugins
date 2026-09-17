<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$plugin = dirname(__DIR__);

function t12SecurityRequire(string $path, array $needles): void
{
    $source = file_get_contents($path);
    if (!is_string($source)) { fwrite(STDERR, "cannot read {$path}\n"); exit(1); }
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) { fwrite(STDERR, "missing {$needle} in {$path}\n"); exit(1); }
    }
}

t12SecurityRequire($plugin . '/app/service/SecurityOperationsService.php', [
    "leftJoin('sand_iam_audit_archive archive'",
    "whereNull('archive.id')",
    "lock('FOR UPDATE OF audit SKIP LOCKED')",
    "AuditArchive::create",
    "purge_enabled",
    "audit_purge_enabled",
    "hash_equals(\$expected, \$confirmation)",
    "Db::table('sand_iam_audit_archive')",
    "field('id, original_audit_id')",
    "SAND_IAM_AUDIT_PURGE_SCOPE_CONFLICT",
    "'physical_rows' => \$physicalRows",
    "audit.retention_purge",
    "security.alert.raised",
    "alert_failure_threshold",
    "strlen(\$key) < 32",
    "SAND_IAM_SECURITY_ALERT_KEY_INVALID",
    "'23505'",
]);
$securityOperations = (string) file_get_contents($plugin . '/app/service/SecurityOperationsService.php');
if (str_contains($securityOperations, "AuditArchive::whereIn('original_audit_id', \$ids)->delete()")) {
    fwrite(STDERR, "purge still uses AuditArchive soft delete\n");
    exit(1);
}
t12SecurityRequire($plugin . '/app/service/AuditWriter.php', ['observeAudit($audit)', 'Never replace the audited', 'security alert projection failed']);
t12SecurityRequire($plugin . '/app/model/SecurityAlert.php', ["protected \$hidden = ['fingerprint']"]);
t12SecurityRequire($plugin . '/app/model/SecurityOperation.php', [
    'extends NoSoftDeleteSandIamModel',
    "protected \$json = ['result']",
    'protected $jsonAssoc = true',
]);
t12SecurityRequire($plugin . '/config/process.php', ['SAND_IAM_AUDIT_ARCHIVE_WORKER_ENABLED', 'AuditOperationsWorker::class']);
t12SecurityRequire($plugin . '/config/route.php', [
    "'/audit/archive/index'",
    "'/audit/archive/read'",
    "'/security-alert/index'",
    "'/security-alert/resolve'",
]);
t12SecurityRequire($plugin . '/app/admin/controller/AuditRetentionPolicyController.php', [
    '归档须为 1–3650 天',
    'retention_days',
    'alert_failure_threshold',
]);

$rootMigration = dirname($plugin, 2) . '/migrations/019_security_operations.pgsql';
$packageMigration = $plugin . '/migrations/019_security_operations.pgsql';
$root = file_get_contents($rootMigration);
$copy = file_get_contents($packageMigration);
if (!is_string($root) || $root !== $copy) { fwrite(STDERR, "019 migration copies differ\n"); exit(1); }
foreach (['sand_iam_application_network_policy', 'sand_iam_audit_retention_policy', 'sand_iam_audit_archive', 'sand_iam_security_alert'] as $table) {
    if (!str_contains($root, $table)) { fwrite(STDERR, "019 missing {$table}\n"); exit(1); }
}

echo 'security operations non-PG contract checks passed' . PHP_EOL;
