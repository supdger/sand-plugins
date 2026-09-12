<?php

declare(strict_types=1);

// behavior-test-gate: observable-behavior

use plugin\SandIam\bin\RuntimeRequirementsPreflight;

require_once dirname(__DIR__) . '/bin/RuntimeRequirementsPreflight.php';

$preflight = new RuntimeRequirementsPreflight();
$complete = array_fill_keys([
    'ctype', 'curl', 'dom', 'json', 'ldap', 'libxml', 'mbstring', 'openssl', 'pdo', 'pdo_pgsql', 'sodium', 'zip', 'zlib',
], true);
$checks = [];

$valid = $preflight->inspect('8.2.0', $complete);
$checks['complete PHP 8.2 capability set passes'] = $valid['passed'] === true
    && $valid['checked'] === 14
    && $valid['scope'] === 'complete-plugin-runtime-shape-only';

$old = $preflight->inspect('8.1.99', $complete);
$checks['unsupported PHP version fails closed'] = $old['passed'] === false
    && ($old['errors'][0]['code'] ?? null) === 'PHP_VERSION_UNSUPPORTED';

$missing = $preflight->inspect('8.4.0', array_replace($complete, [
    'curl' => false,
    'ldap' => false,
    'pdo_pgsql' => false,
    'sodium' => false,
]));
$requirements = array_column($missing['errors'], 'requirement');
$checks['missing protocol database and crypto capabilities are all reported'] = $missing['passed'] === false
    && array_diff(['ext-curl', 'ext-ldap', 'ext-pdo_pgsql', 'ext-sodium'], $requirements) === [];

$detected = RuntimeRequirementsPreflight::detect();
$checks['detector returns every declared capability as booleans'] = array_keys($detected) === array_keys($complete)
    && array_filter($detected, static fn (mixed $value): bool => !is_bool($value)) === [];

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/check-runtime-requirements.php') . ' --json';
$output = [];
exec($command . ' 2>&1', $output, $status);
$cli = json_decode(implode("\n", $output), true);
$checks['shipped CLI returns machine-readable evidence for the current PHP'] = in_array($status, [0, 1], true)
    && is_array($cli)
    && ($cli['schema'] ?? null) === 'sand-iam.runtime-requirements-preflight/v1'
    && ($cli['php_version'] ?? null) === PHP_VERSION
    && ($cli['checked'] ?? null) === 14;

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
echo 'Runtime requirements preflight: passed=' . (count($checks) - count($failed)) . '/' . count($checks) . '; failed=' . count($failed) . PHP_EOL;
exit($failed === [] ? 0 : 1);
