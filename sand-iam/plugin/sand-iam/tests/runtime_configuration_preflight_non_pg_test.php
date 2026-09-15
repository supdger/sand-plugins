<?php

declare(strict_types=1);

// behavior-test-gate: observable-behavior

use plugin\SandIam\bin\RuntimeConfigurationPreflight;

require_once dirname(__DIR__) . '/bin/RuntimeConfigurationPreflight.php';

$preflight = new RuntimeConfigurationPreflight();
$key = base64_encode(str_repeat('k', 32));
$secretKey = str_repeat('s', 32);
$checks = [];

$valid = $preflight->inspect([
    'SAND_IAM_DEBUG' => '0',
    'SAND_IAM_CONTEXT_SIGNING_KEY' => $secretKey,
    'SAND_IAM_AUTH_PEPPER' => $secretKey,
    'SAND_IAM_MFA_ENCRYPTION_KEY' => $key,
    'SAND_IAM_OIDC_SUBJECT_KEY' => $secretKey,
    'SAND_IAM_FEDERATION_ENCRYPTION_KEY' => $key,
    'SAND_IAM_WEBHOOK_WORKER_ENABLED' => '1',
    'SAND_IAM_WEBHOOK_ENCRYPTION_KEY' => $key,
    'SAND_IAM_SECURITY_OPERATION_RETENTION_WORKER_ENABLED' => '1',
    'SAND_IAM_SECURITY_OPERATION_RETENTION_DAYS' => '30',
    'SAND_IAM_SECURITY_OPERATION_RETENTION_INTERVAL_SECONDS' => '3600',
    'SAND_IAM_SECURITY_OPERATION_RETENTION_BATCH_SIZE' => '200',
    'SAND_IAM_AUTH_RATE_LIMIT_RETENTION_HOURS' => '24',
    'SAND_IAM_OIDC_ISSUER' => 'https://iam.example.test/api/sand-iam/v1',
    'SAND_IAM_MFA_ENCRYPTION_KEY_VERSION' => 'v2',
    'SAND_IAM_MFA_ENCRYPTION_KEYS' => json_encode(['v1' => $key], JSON_THROW_ON_ERROR),
    'SAND_IAM_AUTH_EMAIL_SENDER' => 'App\\Iam\\MailSender',
    'SAND_IAM_IDENTITY_LIFECYCLE_ENABLED' => '1',
    'SAND_IAM_SYNC_RECOVER_ABANDONED_RUNS' => '1',
    'SAND_IAM_KERBEROS_ENABLED' => '1',
    'SAND_IAM_KERBEROS_KEYTABS' => '{"iam-spn-v1":"/run/secrets/iam.keytab"}',
]);
$checks['valid release configuration shape passes'] = $valid['passed'] === true
    && $valid['errors'] === []
    && $valid['scope'] === 'configuration-shape-only';

$emptyRelease = $preflight->inspect([]);
$checks['empty release environment fails closed on every baseline value'] = $emptyRelease['passed'] === false
    && $emptyRelease['checked_keys'] === 0
    && count(array_filter(
        $emptyRelease['errors'],
        static fn (array $issue): bool => ($issue['code'] ?? null) === 'MISSING_RELEASE_VALUE',
    )) === 6;

$unsafe = $preflight->inspect([
    'SAND_IAM_DEBUG' => '1',
    'SAND_IAM_ACCEPTANCE_FIXTURE_CLEANUP_ENABLED' => '1',
]);
$unsafeCodes = array_column($unsafe['errors'], 'code');
$checks['release-only unsafe switches fail closed'] = $unsafe['passed'] === false
    && count(array_filter($unsafeCodes, static fn (string $code): bool => $code === 'RELEASE_UNSAFE_SWITCH')) === 2;

$invalid = $preflight->inspect([
    'SAND_IAM_MESSAGE_PROVIDER_ENABLED' => '1',
    'SAND_IAM_DIRECTORY_SYNC_WORKER_ENABLED' => '1',
    'SAND_IAM_SYNC_OUTBOX_MAX_ATTEMPTS' => '0',
    'SAND_IAM_OIDC_ISSUER' => 'http://user:secret@example.test/api/sand-iam/v1',
    'SAND_IAM_MFA_ENCRYPTION_KEY' => base64_encode('short'),
    'SAND_IAM_MFA_ENCRYPTION_KEYS' => '{"v1":"bad"}',
    'SAND_IAM_WEBHOOK_BATCH_SIZE' => '101',
    'SAND_IAM_WEBHOOK_POLL_INTERVAL_MS' => '999999999999999999999999999999999999',
    'SAND_IAM_SECURITY_OPERATION_RETENTION_DAYS' => '29',
    'SAND_IAM_AUTH_RATE_LIMIT_RETENTION_HOURS' => '0',
    'SAND_IAM_OIDC_PRIVATE_KEY_BASE64' => base64_encode('not a private key'),
    'SAND_IAM_AUTH_PHONE_SENDER' => 'not-a-class',
    'SAND_IAM_SYNC_RECOVER_ABANDONED_RUNS' => '1',
    'SAND_IAM_KERBEROS_ENABLED' => '1',
    'SAND_IAM_KERBEROS_KEYTABS' => '{"direct-path":"relative.keytab"}',
]);
$invalidCodes = array_values(array_unique(array_column($invalid['errors'], 'code')));
$checks['invalid keys urls ranges and dependencies are rejected'] = $invalid['passed'] === false
    && array_diff([
        'MISSING_REQUIRED_VALUE', 'MISSING_REQUIRED_SWITCH', 'INVALID_OIDC_ISSUER',
        'INVALID_ENCRYPTION_KEY', 'INVALID_KEYRING_ENTRY', 'INTEGER_OUT_OF_RANGE',
        'INVALID_OIDC_KID', 'INVALID_OIDC_PRIVATE_KEY',
        'INVALID_CLASS_NAME',
        'INVALID_PATH_MAP_ENTRY',
    ], $invalidCodes) === [];

$missingKeytab = $preflight->inspect(['SAND_IAM_KERBEROS_ENABLED' => '1']);
$checks['Kerberos built-ins still require a deployment keytab reference map'] = $missingKeytab['passed'] === false
    && in_array('SAND_IAM_KERBEROS_KEYTABS', array_column($missingKeytab['errors'], 'key'), true);

$acceptance = $preflight->inspect(['SAND_IAM_ACCEPTANCE_FIXTURE_CLEANUP_ENABLED' => '1'], 'acceptance');
$checks['acceptance profile permits explicitly scoped fixture cleanup'] = $acceptance['passed'] === true;

$secret = 'do-not-print-this-secret';
$warning = $preflight->inspect(['SAND_IAM_AUTH_PEPPER' => $secret], 'acceptance');
$serialized = json_encode($warning, JSON_THROW_ON_ERROR);
$checks['acceptance warnings identify keys without echoing secret values'] = $warning['passed'] === true
    && ($warning['warnings'][0]['key'] ?? null) === 'SAND_IAM_AUTH_PEPPER'
    && !str_contains($serialized, $secret);

$releaseShortSecret = $preflight->inspect([
    'SAND_IAM_CONTEXT_SIGNING_KEY' => $secretKey,
    'SAND_IAM_AUTH_PEPPER' => $secret,
    'SAND_IAM_MFA_ENCRYPTION_KEY' => $key,
    'SAND_IAM_OIDC_ISSUER' => 'https://iam.example.test/api/sand-iam/v1',
    'SAND_IAM_OIDC_SUBJECT_KEY' => $secretKey,
    'SAND_IAM_FEDERATION_ENCRYPTION_KEY' => $key,
]);
$checks['release profile rejects short deployment secrets'] = $releaseShortSecret['passed'] === false
    && in_array('SHORT_SECRET', array_column($releaseShortSecret['errors'], 'code'), true);

$overflow = $preflight->inspect(['SAND_IAM_WEBHOOK_POLL_INTERVAL_MS' => '999999999999999999999999999999999999']);
$checks['integer overflow cannot be truncated into a passing value'] = $overflow['passed'] === false
    && in_array('INTEGER_OUT_OF_RANGE', array_column($overflow['errors'], 'code'), true);

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/check-runtime-configuration.php') . ' --json';
$output = [];
exec('SAND_IAM_DEBUG=1 SAND_IAM_AUTH_PEPPER=' . escapeshellarg($secret) . ' ' . $command . ' 2>&1', $output, $status);
$cli = json_decode(implode("\n", $output), true);
$checks['shipped CLI returns nonzero and machine-readable secret-safe evidence'] = $status === 1
    && is_array($cli)
    && ($cli['schema'] ?? null) === 'sand-iam.runtime-configuration-preflight/v1'
    && ($cli['passed'] ?? null) === false
    && !str_contains(implode("\n", $output), 'do-not-print-this-secret');

$emptyOutput = [];
exec('env -i PATH=' . escapeshellarg((string) getenv('PATH')) . ' ' . $command . ' 2>&1', $emptyOutput, $emptyStatus);
$emptyCli = json_decode(implode("\n", $emptyOutput), true);
$checks['shipped CLI rejects an empty release environment'] = $emptyStatus === 1
    && is_array($emptyCli)
    && ($emptyCli['checked_keys'] ?? null) === 0
    && count(array_filter(
        $emptyCli['errors'] ?? [],
        static fn (array $issue): bool => ($issue['code'] ?? null) === 'MISSING_RELEASE_VALUE',
    )) === 6;

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
echo 'Runtime configuration preflight: passed=' . (count($checks) - count($failed)) . '/' . count($checks) . '; failed=' . count($failed) . PHP_EOL;
exit($failed === [] ? 0 : 1);
