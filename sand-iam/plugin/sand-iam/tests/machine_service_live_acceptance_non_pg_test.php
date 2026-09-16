<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/machine-service-live-acceptance.php');
$provider = dirname(__DIR__, 3) . '/examples/machine-service-client/provider';
$assertions = [
    'runner refuses database creation' => !preg_match('/CREATE\\s+DATABASE|createdb/i', $source),
    'runner pins existing database' => str_contains($source, "current_database()') !== 'sandadmin'"),
    'runner refuses pre-existing business tables' => str_contains($source, 'refuses a pre-existing'),
    'runner uses official SDK issue path' => str_contains($source, 'new SandIamClient') && str_contains($source, 'issueContext('),
    'runner starts real provider HTTP service' => str_contains($source, "127.0.0.1:8089") && str_contains($source, "public/index.php"),
    'runner proves one business effect and replay' => str_contains($source, "business_effect_once") && str_contains($source, "idempotent_replay"),
    'runner proves tamper and revocation denial' => str_contains($source, 'tampered context was not rejected') && str_contains($source, 'revoked context reached the business side effect'),
    'runner verifies IAM and provider audits' => str_contains($source, 'dual_audit_requests') && str_contains($source, "action' => 'context.verify'"),
    'runner drops only named provider tables' => str_contains($source, 'DROP TABLE provider_b_audit_log') && str_contains($source, 'DROP TABLE provider_b_document_process') && str_contains($source, 'DROP TABLE provider_b_document'),
    'runner verifies zero residual and retains audit anchors' => str_contains($source, "zero_residual")
        && str_contains($source, 'retained_disabled_audit_anchors')
        && str_contains($source, 'cleanup lost SandIAM audit evidence or retained idempotency rows'),
    'runner revokes through normal APIs before cleanup' => str_contains($source, '/credential/revoke')
        && str_contains($source, '/grant/revoke')
        && str_contains($source, '/organization/disable'),
    'runner exports redacted dual audit evidence' => str_contains($source, "'audit_evidence' => \$auditEvidence")
        && str_contains($source, 'SELECT request_id,context_id,action,outcome,error_code,http_status,replayed'),
    'provider dependency lock is committed source' => is_file($provider . '/composer.lock') && is_file($provider . '/.gitignore'),
    'provider contract is deployment fixed' => str_contains((string) file_get_contents($provider . '/src/ProviderConfig.php'), 'PROVIDER_B_SERVICE_CODE')
        && str_contains((string) file_get_contents($provider . '/src/ProviderApplication.php'), '$this->serviceCode'),
    'provider audits configured action' => str_contains((string) file_get_contents($provider . '/src/PdoProcessStore.php'), '$action')
        && str_contains((string) file_get_contents($provider . '/src/FailureAuditWriter.php'), '$this->config->action'),
];

$failed = [];
foreach ($assertions as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
echo 'Machine service live acceptance contract: passed=' . (count($assertions) - count($failed)) . '/' . count($assertions) . '; failed=' . count($failed) . PHP_EOL;
exit($failed === [] ? 0 : 1);
