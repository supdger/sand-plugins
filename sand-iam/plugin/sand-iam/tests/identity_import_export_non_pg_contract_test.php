<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** IAM-T10 import/export source contract. No database is used. */
$package = dirname(__DIR__); $root = dirname(__DIR__, 3);
$checks = [
    $package . '/app/service/IdentityImportService.php' => [
        "private const HEADERS = ['用户名', '显示名称', '邮箱', '手机号', '账号状态', '用户组代码']",
        '2_097_152', '10_000', "'previewed'", "'running'", "'partial'", 'hash_equals((string) $job->content_digest, $digest)',
        "whereIn('state', ['valid', 'failed'])", "'succeeded_with_warning'", 'job->mode === \'create\'', 'IdentityInvitationService())->create(',
        'encrypted_payload', "'identity_export.sensitive'", "preg_match('/^[=+\\-@]/u",
    ],
    $package . '/app/admin/controller/IdentityImportController.php' => ['getSize() > 2_097_152', '预检完成；尚未创建或修改任何应用用户', "sand_iam:identity_export:masked", "sand_iam:identity_export:sensitive", "'summary' =>", "'validation_errors' =>"],
    $package . '/app/service/ImportRowCipher.php' => ['sodium_crypto_secretbox(', 'sodium_crypto_secretbox_open(', 'import_encryption_key_version', 'import_encryption_keys'],
    $package . '/app/middleware/IdentityImportSensitiveMiddleware.php' => ['uploaded CSV rows and personal identifiers', 'never rethrow CSV contents'],
    $package . '/config/route.php' => ['identity-import/preview', 'identity-import/confirm', 'identity-export/masked', 'identity-export/sensitive'],
];
foreach ($checks as $file => $fragments) { $content = file_get_contents($file); if (!is_string($content)) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); } foreach ($fragments as $fragment) if (!str_contains($content, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); } }
$controller = file_get_contents($package . '/app/admin/controller/IdentityImportController.php'); if (is_string($controller) && str_contains($controller, "'encrypted_payload' =>")) { fwrite(STDERR, "import report DTO exposes encrypted payload\n"); exit(1); }
$name = '014_identity_import_export.pgsql'; $source = $root . '/migrations/' . $name; $copy = $package . '/migrations/' . $name;
if (!is_file($source) || !is_file($copy) || hash_file('sha256', $source) !== hash_file('sha256', $copy)) { fwrite(STDERR, "014 root/plugin copies differ\n"); exit(1); }
$sql = file_get_contents($source); foreach (['sand_iam_identity_import_job', 'sand_iam_identity_import_row', 'succeeded_with_warning', 'encrypted_payload', 'idempotency_key'] as $fragment) if (!is_string($sql) || !str_contains($sql, $fragment)) { fwrite(STDERR, "014 missing {$fragment}\n"); exit(1); }
echo "identity import/export non-PG contract checks passed\n";
