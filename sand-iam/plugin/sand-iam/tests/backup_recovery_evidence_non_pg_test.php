<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$validator = $root . '/tools/validate-backup-recovery.php';
/** @return array{0:int,1:string} */
$run = static function (string $report) use ($validator): array {
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($validator) . ' --report=' . escapeshellarg($report) . ' 2>&1', $output, $status);
    return [$status, implode("\n", $output)];
};
$removeTree = null;
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $removeTree($path . '/' . $entry);
    rmdir($path);
};
$seed = tempnam('/private/tmp', 'sand-iam-recovery-');
if ($seed === false || !unlink($seed) || !mkdir($seed, 0700) || !mkdir($seed . '/evidence', 0700)) throw new RuntimeException('cannot create recovery fixture');

try {
    $types = ['backup-command', 'archive-list', 'restore-command', 'source-state', 'restored-state', 'business-probes', 'cleanup'];
    $evidence = [];
    foreach ($types as $type) {
        $path = 'evidence/' . $type . '.json';
        $bytes = json_encode(['type' => $type, 'result' => 'redacted-pass'], JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($seed . '/' . $path, $bytes);
        $evidence[] = ['type' => $type, 'path' => $path, 'sha256' => hash('sha256', $bytes)];
    }
    $state = [
        'logical_state_sha256' => str_repeat('d', 64), 'audit_chain_sha256' => str_repeat('e', 64),
        'sand_iam_tables' => 86, 'migration_rows' => 37, 'organizations' => 2, 'applications' => 3,
        'environments' => 4, 'identities' => 5, 'active_credentials' => 2, 'revoked_credentials' => 1,
        'revoked_sessions' => 1, 'audit_rows' => 25,
    ];
    $checks = array_fill_keys(['archive_list_complete', 'restore_single_transaction', 'migration_ledger_equal', 'authorization_allow_equal', 'authorization_deny_equal', 'revoked_access_denied', 'revoked_sessions_not_resurrected', 'audit_chain_equal', 'signature_verification_equal', 'post_restore_audit_append', 'host_objects_unchanged', 'other_plugins_unchanged', 'cleanup_verified'], true);
    $valid = [
        'schema' => 'sand-iam.backup-recovery/v1',
        'candidate' => ['version' => '0.7.0', 'archive_sha256' => str_repeat('a', 64), 'artifact_manifest_sha256' => str_repeat('b', 64)],
        'reviewer' => ['id' => 'independent-recovery-reviewer', 'independent' => true, 'conflict_statement' => 'I did not perform the restore implementation.'],
        'environment' => ['fingerprint' => str_repeat('c', 64), 'host' => 'isolated-restore-host', 'postgresql' => '18', 'source_dsn_sha256' => str_repeat('1', 64), 'restore_dsn_sha256' => str_repeat('2', 64), 'restore_target_precreated' => true, 'production_target' => false],
        'backup' => ['format' => 'custom', 'archive_sha256' => str_repeat('3', 64), 'archive_list_sha256' => str_repeat('4', 64), 'pg_dump_version' => '18.0', 'pg_restore_version' => '18.0', 'started_at' => '2026-09-12T00:00:00Z', 'completed_at' => '2026-09-12T00:10:00Z', 'restore_flags' => ['--exit-on-error', '--single-transaction', '--no-owner', '--no-acl']],
        'state' => ['source' => $state, 'restored' => $state], 'checks' => $checks,
        'unresolved_failures' => 0, 'evidence' => $evidence,
    ];
    $reportPath = $seed . '/report.json';
    $write = static fn (array $report): int|false => file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $write($valid);
    [$validStatus, $validOutput] = $run($reportPath);

    $sameDatabase = $valid;
    $sameDatabase['environment']['restore_dsn_sha256'] = $sameDatabase['environment']['source_dsn_sha256'];
    $write($sameDatabase);
    [$sameStatus, $sameOutput] = $run($reportPath);

    $unsafeFlags = $valid;
    $unsafeFlags['backup']['restore_flags'][] = '--clean';
    $write($unsafeFlags);
    [$flagsStatus, $flagsOutput] = $run($reportPath);

    $resurrected = $valid;
    $resurrected['state']['restored']['revoked_sessions'] = 0;
    $write($resurrected);
    [$stateStatus, $stateOutput] = $run($reportPath);

    $missingEvidence = $valid;
    array_pop($missingEvidence['evidence']);
    $write($missingEvidence);
    [$evidenceStatus, $evidenceOutput] = $run($reportPath);

    $passed = $validStatus === 0 && str_contains($validOutput, '"checks_verified": 13') && str_contains($validOutput, '"evidence_files_verified": 7')
        && $sameStatus !== 0 && str_contains($sameOutput, 'DSN fingerprints must differ')
        && $flagsStatus !== 0 && str_contains($flagsOutput, 'restore flags contain forbidden --clean')
        && $stateStatus !== 0 && str_contains($stateOutput, 'source/restored state mismatch: revoked_sessions')
        && $evidenceStatus !== 0 && str_contains($evidenceOutput, 'recovery evidence is missing types: cleanup');
    if (!$passed) throw new RuntimeException('backup recovery validator did not enforce isolated, safe, state-equivalent evidence');
} finally {
    $removeTree($seed);
}

echo "SandIAM backup recovery evidence checks passed\n";
