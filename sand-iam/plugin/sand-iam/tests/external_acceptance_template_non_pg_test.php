<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
$generator = $root . '/tools/prepare-external-acceptance.php';
$enduranceValidator = $root . '/tools/run-endurance-acceptance.php';
$casdoorValidator = $root . '/tools/validate-casdoor-comparison.php';
$interopValidator = $root . '/tools/validate-protocol-interop.php';
$recoveryValidator = $root . '/tools/validate-backup-recovery.php';
$independentValidator = $root . '/tools/validate-independent-delivery.php';
$seed = tempnam('/private/tmp', 'sand-iam-external-template-');
if ($seed === false || !unlink($seed) || !mkdir($seed, 0700)) throw new RuntimeException('cannot create external template fixture');

/** @return array{0:int,1:string} */
$run = static function (array $arguments): array {
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    $output = [];
    exec($command . ' 2>&1', $output, $status);
    return [$status, implode("\n", $output)];
};
$removeTree = null;
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $removeTree($path . '/' . $entry);
    rmdir($path);
};

try {
    $manifest = [
        'schema' => 'sand-iam.artifact-manifest/v7', 'kind' => 'release-candidate-unsigned', 'release_state' => 'release/unsigned',
        'archive_authority_parity' => ['passed' => true, 'normal_package_recovery_descriptors' => 'excluded'],
        'reproducibility' => ['bit_identical_zip' => true, 'entry_list_identical' => true],
        'package' => ['app' => 'sand-iam', 'version' => '0.7.1', 'archive' => 'sand-iam.zip', 'sha256' => str_repeat('a', 64), 'bytes' => 1, 'entry_count' => 1],
        'source_revision' => [
            'vcs' => 'git', 'commit' => str_repeat('b', 40), 'tree' => str_repeat('c', 40),
            'subtree' => 'sand-iam/', 'clean' => true,
        ],
        'source_snapshot' => ['sha256' => str_repeat('c', 64)],
        'files' => ['README.md' => ['sha256' => str_repeat('1', 64), 'bytes' => 1], 'update.sql' => ['sha256' => str_repeat('f', 64), 'bytes' => 1]],
    ];
    $manifestPath = $seed . '/manifest.json';
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $endurancePath = $seed . '/endurance.json';
    $casdoorPath = $seed . '/casdoor.json';
    $interopPath = $seed . '/interop.json';
    $recoveryPath = $seed . '/recovery.json';
    $independentPath = $seed . '/independent-delivery.json';
    [$enduranceGenerateStatus] = $run([PHP_BINARY, $generator, '--kind=endurance', '--artifact-manifest=' . $manifestPath, '--output=' . $endurancePath]);
    [$casdoorGenerateStatus] = $run([PHP_BINARY, $generator, '--kind=casdoor', '--artifact-manifest=' . $manifestPath, '--output=' . $casdoorPath]);
    [$interopGenerateStatus] = $run([PHP_BINARY, $generator, '--kind=interop', '--artifact-manifest=' . $manifestPath, '--output=' . $interopPath]);
    [$recoveryGenerateStatus] = $run([PHP_BINARY, $generator, '--kind=recovery', '--artifact-manifest=' . $manifestPath, '--output=' . $recoveryPath]);
    [$independentGenerateStatus] = $run([PHP_BINARY, $generator, '--kind=independent-delivery', '--artifact-manifest=' . $manifestPath, '--output=' . $independentPath]);
    [$enduranceStatus, $enduranceOutput] = $run([PHP_BINARY, $enduranceValidator, '--plan=' . $endurancePath, '--validate-only']);
    [$casdoorStatus, $casdoorOutput] = $run([PHP_BINARY, $casdoorValidator, '--report=' . $casdoorPath]);
    [$interopStatus, $interopOutput] = $run([PHP_BINARY, $interopValidator, '--report=' . $interopPath]);
    [$recoveryStatus, $recoveryOutput] = $run([PHP_BINARY, $recoveryValidator, '--report=' . $recoveryPath]);
    [$independentStatus, $independentOutput] = $run([PHP_BINARY, $independentValidator, '--report=' . $independentPath]);
    $endurance = json_decode((string) file_get_contents($endurancePath), true);
    $casdoor = json_decode((string) file_get_contents($casdoorPath), true);
    $interop = json_decode((string) file_get_contents($interopPath), true);
    $recovery = json_decode((string) file_get_contents($recoveryPath), true);
    $independent = json_decode((string) file_get_contents($independentPath), true);
    $readyEndurance = $endurance;
    $readyEndurance['approved_hosts'] = ['endurance.example.invalid'];
    foreach ($readyEndurance['targets'] as &$target) {
        $target['url'] = str_replace('__REQUIRED_APPROVED_HOST__', 'endurance.example.invalid', $target['url']);
        $target['max_p99_ms'] = 500;
    }
    unset($target);
    foreach ($readyEndurance['thresholds'] as $name => $value) {
        if ($value < 0) $readyEndurance['thresholds'][$name] = str_contains($name, 'slope') ? 1 : 1024;
    }
    file_put_contents($endurancePath, json_encode($readyEndurance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    [$readyEnduranceStatus, $readyEnduranceOutput] = $run([PHP_BINARY, $enduranceValidator, '--plan=' . $endurancePath, '--validate-only']);
    $manifestHash = hash_file('sha256', $manifestPath);
    $requiredRetentionMetrics = ['security_operation_retention_backlog', 'auth_rate_limit_retention_backlog'];
    $requiredRetentionThresholds = ['max_security_operation_retention_backlog', 'max_auth_rate_limit_retention_backlog'];
    $passed = $enduranceGenerateStatus === 0 && $casdoorGenerateStatus === 0 && $interopGenerateStatus === 0 && $recoveryGenerateStatus === 0 && $independentGenerateStatus === 0
        && ($endurance['candidate']['archive_sha256'] ?? null) === str_repeat('a', 64)
        && ($casdoor['candidate']['artifact_manifest_sha256'] ?? null) === $manifestHash
        && count($endurance['targets'] ?? []) === 7 && count($casdoor['journeys'] ?? []) === 3
        && array_diff($requiredRetentionMetrics, array_keys($endurance['metrics'] ?? [])) === []
        && array_reduce($requiredRetentionThresholds, static fn (bool $ok, string $key): bool => $ok && ($endurance['thresholds'][$key] ?? null) === 0, true)
        && $readyEnduranceStatus === 0 && str_contains($readyEnduranceOutput, 'duration_seconds=86400')
        && count($interop['cases'] ?? []) === 7
        && count($recovery['evidence'] ?? []) === 7 && ($recovery['environment']['production_target'] ?? null) === true
        && count($independent['steps'] ?? []) === 8 && ($independent['participant']['independent'] ?? null) === false
        && count($casdoor['journeys'][0]['runs'] ?? []) === 4
        && $enduranceStatus !== 0 && (str_contains($enduranceOutput, 'approved host is invalid') || str_contains($enduranceOutput, 'URL is invalid'))
        && $casdoorStatus !== 0 && str_contains($casdoorOutput, 'reviewer must be identified')
        && $interopStatus !== 0 && str_contains($interopOutput, 'reviewer must be independent')
        && $recoveryStatus !== 0 && str_contains($recoveryOutput, 'reviewer must be independent')
        && $independentStatus !== 0 && str_contains($independentOutput, 'participant must be independent');
    if (!$passed) throw new RuntimeException('candidate-bound templates were not complete and fail-closed');
} finally {
    $removeTree($seed);
}

echo "SandIAM external acceptance template checks passed\n";
