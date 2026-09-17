<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$tool = file_get_contents($root . '/tools/standalone-consumer-live-acceptance.php');
$schema = file_get_contents($root . '/examples/webman-business-app/standalone/schema.pgsql');
$config = file_get_contents($root . '/examples/webman-business-app/standalone/src/Config.php');
$repository = file_get_contents($root . '/examples/webman-business-app/standalone/src/PgsqlRepository.php');

foreach (compact('tool', 'schema', 'config', 'repository') as $name => $contents) {
    if (!is_string($contents) || trim($contents) === '') {
        fwrite(STDERR, "standalone live acceptance source is missing: {$name}\n");
        exit(1);
    }
}

$requiredToolFragments = [
    "SELECT current_database()",
    "to_regclass('public.standalone_work_item')",
    "to_regclass('public.standalone_business_audit')",
    "non-ai-business-consumer",
    "human-auth-session-mfa",
    "/app/sand-iam/admin/application/disable",
    "/app/sand-iam/admin/organization/disable",
    "DROP TABLE standalone_business_audit",
    "DROP TABLE standalone_work_item",
    "dual audit evidence is incomplete",
    "out-of-scope business close produced a side effect",
    "cross-application token was not rejected and audited before the business effect",
    "cross-application dual audit evidence could not be exported",
    "revoked application session still reached the business object",
    "revoked-session dual audit evidence could not be exported",
    "retained_disabled_audit_anchors",
    "'audit_evidence' => \$auditEvidence",
];
foreach ($requiredToolFragments as $fragment) {
    if (!str_contains($tool, $fragment)) {
        fwrite(STDERR, "standalone live acceptance contract missing: {$fragment}\n");
        exit(1);
    }
}
foreach (['CREATE DATABASE', 'createdb ', 'DROP DATABASE'] as $forbidden) {
    if (stripos($tool . "\n" . $schema, $forbidden) !== false) {
        fwrite(STDERR, "standalone live acceptance must not manage databases: {$forbidden}\n");
        exit(1);
    }
}
if (!str_contains($schema, 'action varchar(96) NOT NULL')) {
    fwrite(STDERR, "standalone business audit API code does not match the public 96-character contract\n");
    exit(1);
}
foreach (['SAND_IAM_READ_API_CODE', 'SAND_IAM_CLOSE_API_CODE'] as $name) {
    if (!str_contains($config, $name)) {
        fwrite(STDERR, "standalone consumer does not expose {$name}\n");
        exit(1);
    }
}
if (!str_contains($repository, 'writeSuccess($this->database, $action,')) {
    fwrite(STDERR, "standalone close audit does not preserve the configured API code\n");
    exit(1);
}

echo "standalone consumer live acceptance non-PG checks passed\n";
