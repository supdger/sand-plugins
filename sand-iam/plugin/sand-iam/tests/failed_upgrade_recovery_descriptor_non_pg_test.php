<?php

declare(strict_types=1);

// behavior-test-gate: static-rule
// This test exercises the payload policy and historical descriptor bytes only;
// it never invokes a host recovery, service, or database.

$root = dirname(__DIR__, 3);
require_once $root . '/tools/package-payload-policy.php';

$rootDescriptor = $root . '/recovery/failed-upgrade.v2.json';
$packageDescriptor = $root . '/plugin/sand-iam/recovery/failed-upgrade.v2.json';
$rootBytes = is_file($rootDescriptor) ? file_get_contents($rootDescriptor) : false;
$packageBytes = is_file($packageDescriptor) ? file_get_contents($packageDescriptor) : false;
$descriptor = is_string($rootBytes) ? json_decode($rootBytes, true) : null;
$payload = sandIamPayloadFiles($root, true);

$canonicalize = null;
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        $value[$key] = $canonicalize($item);
    }
    return $value;
};

$profile = is_array($descriptor) ? ($descriptor['profile'] ?? null) : null;
$assertionTypes = is_array($profile) && is_array($profile['assertions'] ?? null)
    ? array_values(array_unique(array_column($profile['assertions'], 'type')))
    : [];
sort($assertionTypes, SORT_STRING);

$checks = [
    'historical descriptor remains root/plugin byte-identical' => is_string($rootBytes) && $rootBytes !== '' && $rootBytes === $packageBytes,
    'historical descriptor remains canonical and bound to the old 0.6.0 to 0.7.0 recovery profile' => is_array($descriptor)
        && $rootBytes === json_encode($canonicalize($descriptor), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        && ($descriptor['schema'] ?? null) === 'sandpackage.failed-upgrade-recovery/v2'
        && ($descriptor['from_version'] ?? null) === '0.6.0'
        && ($descriptor['to_version'] ?? null) === '0.7.0'
        && ($profile['state'] ?? null) === 'prefix_033_034'
        && in_array('ledger_absent', $assertionTypes, true),
    'normal payload policy excludes both historical descriptor mirrors' => !in_array('recovery/failed-upgrade.v2.json', $payload, true)
        && !in_array('plugin/sand-iam/recovery/failed-upgrade.v2.json', $payload, true),
    'normal payload policy preserves normal package roots after recovery exclusion' => in_array('info.ini', $payload, true)
        && in_array('update.sql', $payload, true)
        && in_array('plugin/sand-iam/info.ini', $payload, true),
];

$passed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $passed += $ok ? 1 : 0;
}
$total = count($checks);
echo "Historical failed-upgrade recovery descriptor contract: passed={$passed}/{$total}; failed=" . ($total - $passed) . PHP_EOL;
exit($passed === $total ? 0 : 1);
