<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$tool = (string) file_get_contents($root . '/tools/admin-notification-live-acceptance.php');

$required = [
    "const L01_CONFIRM = 'I_CONFIRM_L01_EXISTING_DATABASE_FIXTURES_AND_CLEANUP'",
    "preg_match('/^sand_iam_acceptance_[a-f0-9]{16}_$/', \$prefix)",
    "str_starts_with(\$databaseDsn, 'pgsql:')",
    "SELECT current_database()",
    "!== 'sandadmin'",
    'SAND_IAM_L01_PLATFORM_AUTHORIZATION',
    'SAND_IAM_L01_ORGANIZATION_AUTHORIZATION',
    'SAND_IAM_L01_APPLICATION_AUTHORIZATION',
    'SAND_IAM_L01_OUT_OF_SCOPE_AUTHORIZATION',
    '/app/sand-iam/admin/message-provider/save',
    '/app/sand-iam/admin/message-provider/configure',
    '/app/sand-iam/admin/message-provider/mount',
    '/app/sand-iam/admin/message-provider/unmount',
    '/app/sand-iam/admin/message-provider/disable',
    'notification_configured_and_mounted',
    'out_of_scope_denied',
    'delegated_audit_attribution',
    'delegation_revoked',
    'l01PhysicalCleanup(',
    'l01FallbackDisableAnchors(',
    'message provider cleanup boundary mismatch',
    'refuses direct anchor disable',
    "'application API disable: '",
    "'organization API disable: '",
    "'bounded cleanup: '",
    'expected one exact audit row',
    "'status' => 'passed'",
];
foreach ($required as $needle) {
    if (!str_contains($tool, $needle)) {
        fwrite(STDERR, "missing L01 live contract token: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    'CREATE DATABASE',
    'DROP DATABASE',
    "'token' => \$configured",
    "'token' => \$checks",
    "'authorization' => \$platformAuthorization",
];
foreach ($forbidden as $needle) {
    if (stripos($tool, $needle) !== false) {
        fwrite(STDERR, "forbidden L01 live contract token: {$needle}\n");
        exit(1);
    }
}

if (substr_count($tool, "'application.access'") < 2
    || substr_count($tool, "'organization.access'") < 1
    || !str_contains($tool, "'outcome' => \$outcome")
    || substr_count($tool, '$cleanupFailures[]') < 4) {
    fwrite(STDERR, "L01 deny/revoke audit checks are incomplete\n");
    exit(1);
}

echo "admin notification live acceptance contract passed\n";
