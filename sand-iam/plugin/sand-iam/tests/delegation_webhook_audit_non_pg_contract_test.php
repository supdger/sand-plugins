<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/**
 * T06 source contract floor. This test does not connect to or mutate a database.
 */
$package = dirname(__DIR__);
$root = dirname(__DIR__, 3);
$checks = [
    $package . '/app/admin/support/AdminOrganizationAccess.php' => [
        'public function applicationIds()',
        'public function assertApplication(',
        'AdminApplicationGrant::where',
        'Organization::where',
        'SAND_IAM_APPLICATION_ACCESS_DENIED',
    ],
    $package . '/app/admin/controller/AdminApplicationGrantController.php' => [
        'protected ?string $keywordField = null;',
        'public function adminOptions(',
        "field(['id', 'username', 'realname'])",
        'mb_strlen($keyword) < 2',
        'assertOrganization(',
        'scopeIndexToOrganizations',
        'SAND_IAM_ADMIN_APPLICATION_GRANT_CONFLICT',
    ],
    $package . '/app/service/WebhookSecretCipher.php' => [
        'sodium_crypto_secretbox(',
        'sodium_crypto_secretbox_open(',
        'webhook_encryption_key_version',
        'webhook_encryption_keys',
    ],
    $package . '/app/webhook/NativeWebhookHttpAdapter.php' => [
        '($parts[\'scheme\'] ?? \'\') !== \'https\'',
        'isset($parts[\'user\'])',
        'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE',
        'CURLOPT_FOLLOWLOCATION => false',
        "CURLOPT_PROXY => ''",
        'CURLOPT_RESOLVE',
        'CURLOPT_SSL_VERIFYPEER => true',
    ],
    $package . '/app/service/WebhookService.php' => [
        "->lock('FOR UPDATE SKIP LOCKED')",
        "[60, 300, 1800, 7200, 43200]",
        'hash_hmac(\'sha256\', $timestamp . \'.\' . $body, $secret)',
        "'X-SandIAM-Event-Id'",
        "'X-SandIAM-Secret-Version'",
        "'password', 'current_password', 'new_password'",
        'SAND_IAM_WEBHOOK_PAYLOAD_TOO_LARGE',
        '\'event_id\' => $eventId',
        'RequestId::normalize($value)',
    ],
    $package . '/app/admin/controller/WebhookController.php' => [
        'assertApplication(',
        'endpointPayload(',
        'deliveryPayload(',
        'IdempotencyService::fingerprint($payload)',
        "'webhook.create'",
        "'webhook.secret_rotate'",
        'RequestId::fromRequestCached($request)',
        '新签名密钥不会再次显示',
        "withHeader('Cache-Control', 'no-store')",
        '签名密钥只显示本次',
    ],
    $package . '/app/service/IdempotencyService.php' => [
        "'secret_available' => false",
        'redactSecrets',
    ],
    $package . '/app/admin/controller/AuditLogController.php' => [
        "#[Permission('SandIAM 审计导出', 'sand_iam:audit:export')]",
        'limit(10_001)',
        '31 * 86400',
        'assertApplication(',
        'assertOrganization(',
        "preg_match('/^[=+\\-@]/u'",
        "withHeader('Cache-Control', 'no-store')",
    ],
    $package . '/config/route.php' => [
        "'/audit/export'",
        "'/webhook/secret/rotate'",
        "'/webhook/delivery/retry'",
        "'admin-application-grant' => AdminApplicationGrantController::class",
        "'/admin-application-grant/admin-options'",
    ],
    $package . '/app/model/AdminApplicationGrant.php' => [
        'protected $append = [\'admin_user_name\']',
        'getAdminUserNameAttr',
    ],
    $package . '/config/process.php' => [
        "env('SAND_IAM_WEBHOOK_WORKER_ENABLED', '0')",
        "'handler' => WebhookWorker::class",
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

$controller = file_get_contents($package . '/app/admin/controller/WebhookController.php');
if (!is_string($controller) || str_contains($controller, "'encrypted_secret' =>")) {
    fwrite(STDERR, "webhook management DTO may expose encrypted_secret\n");
    exit(1);
}

foreach (['009_admin_application_grant.pgsql', '010_webhook_delivery.pgsql'] as $migrationName) {
    $sourceMigration = $root . '/migrations/' . $migrationName;
    $packageMigration = $package . '/migrations/' . $migrationName;
    if (!is_file($sourceMigration) || !is_file($packageMigration) || hash_file('sha256', $sourceMigration) !== hash_file('sha256', $packageMigration)) {
        fwrite(STDERR, "root/plugin migration differs: {$migrationName}\n");
        exit(1);
    }
    $migration = file_get_contents($sourceMigration);
    if (!is_string($migration) || preg_match('/\bAUTO_INCREMENT\b|\bUNSIGNED\b|\bENGINE\s*=/i', $migration)) {
        fwrite(STDERR, "migration is unreadable or contains non-PostgreSQL syntax: {$migrationName}\n");
        exit(1);
    }
}

echo "delegation, webhook and audit non-PG contract checks passed\n";
