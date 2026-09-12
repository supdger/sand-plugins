<?php

declare(strict_types=1);

/** Validate candidate-bound PostgreSQL backup/restore evidence without touching a database. */

$options = getopt('', ['report:']);
$reportPath = $options['report'] ?? null;
if (!is_string($reportPath) || trim($reportPath) === '') throw new InvalidArgumentException('--report is required');
$reportPath = realpath($reportPath);
if (!is_string($reportPath) || !is_file($reportPath) || is_link($reportPath)) throw new RuntimeException('recovery report must be an existing regular file');
$reportRoot = dirname($reportPath);
$report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($report) || ($report['schema'] ?? null) !== 'sand-iam.backup-recovery/v1') throw new RuntimeException('invalid recovery report schema');

/** @param list<string> $required @param list<string> $allowed */
$expectKeys = static function (array $value, array $required, array $allowed, string $label): void {
    $missing = array_values(array_diff($required, array_keys($value)));
    $unknown = array_values(array_diff(array_keys($value), $allowed));
    if ($missing !== []) throw new RuntimeException($label . ' is missing keys: ' . implode(', ', $missing));
    if ($unknown !== []) throw new RuntimeException($label . ' has unknown keys: ' . implode(', ', $unknown));
};
$nonEmpty = static function (mixed $value, string $label): string {
    if (!is_string($value) || trim($value) === '') throw new RuntimeException($label . ' is required');
    return trim($value);
};
$timestamp = static function (mixed $value, string $label): DateTimeImmutable {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) throw new RuntimeException($label . ' must use exact UTC second precision');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d\TH:i:s\Z') !== $value
        || (is_array($errors) && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))) throw new RuntimeException($label . ' must be a valid UTC timestamp');
    return $parsed;
};

$topKeys = ['schema', 'candidate', 'reviewer', 'environment', 'backup', 'state', 'checks', 'unresolved_failures', 'evidence'];
$expectKeys($report, $topKeys, $topKeys, 'report');
foreach (['candidate', 'reviewer', 'environment', 'backup', 'state', 'checks', 'evidence'] as $section) if (!is_array($report[$section])) throw new RuntimeException($section . ' must be an object or array');

$candidate = $report['candidate'];
$expectKeys($candidate, ['version', 'archive_sha256', 'artifact_manifest_sha256'], ['version', 'archive_sha256', 'artifact_manifest_sha256'], 'candidate');
if (preg_match('/^\d+\.\d+\.\d+$/', (string) ($candidate['version'] ?? '')) !== 1) throw new RuntimeException('candidate version must be semantic');
foreach (['archive_sha256', 'artifact_manifest_sha256'] as $field) if (preg_match('/^[0-9a-f]{64}$/', (string) ($candidate[$field] ?? '')) !== 1) throw new RuntimeException('candidate.' . $field . ' must be SHA-256');

$reviewer = $report['reviewer'];
$expectKeys($reviewer, ['id', 'independent', 'conflict_statement'], ['id', 'independent', 'conflict_statement'], 'reviewer');
$nonEmpty($reviewer['id'] ?? null, 'reviewer.id');
$nonEmpty($reviewer['conflict_statement'] ?? null, 'reviewer.conflict_statement');
if (($reviewer['independent'] ?? null) !== true) throw new RuntimeException('reviewer must be independent');

$environment = $report['environment'];
$environmentKeys = ['fingerprint', 'host', 'postgresql', 'source_dsn_sha256', 'restore_dsn_sha256', 'restore_target_precreated', 'production_target'];
$expectKeys($environment, $environmentKeys, $environmentKeys, 'environment');
foreach (['fingerprint', 'source_dsn_sha256', 'restore_dsn_sha256'] as $field) if (preg_match('/^[0-9a-f]{64}$/', (string) ($environment[$field] ?? '')) !== 1) throw new RuntimeException('environment.' . $field . ' must be SHA-256');
foreach (['host', 'postgresql'] as $field) $nonEmpty($environment[$field] ?? null, 'environment.' . $field);
if (hash_equals((string) $environment['source_dsn_sha256'], (string) $environment['restore_dsn_sha256'])) throw new RuntimeException('source and restore DSN fingerprints must differ');
if (($environment['restore_target_precreated'] ?? null) !== true || ($environment['production_target'] ?? null) !== false) throw new RuntimeException('restore target must be a precreated non-production database');

$backup = $report['backup'];
$backupKeys = ['format', 'archive_sha256', 'archive_list_sha256', 'pg_dump_version', 'pg_restore_version', 'started_at', 'completed_at', 'restore_flags'];
$expectKeys($backup, $backupKeys, $backupKeys, 'backup');
if (($backup['format'] ?? null) !== 'custom') throw new RuntimeException('backup format must be PostgreSQL custom');
foreach (['archive_sha256', 'archive_list_sha256'] as $field) if (preg_match('/^[0-9a-f]{64}$/', (string) ($backup[$field] ?? '')) !== 1) throw new RuntimeException('backup.' . $field . ' must be SHA-256');
foreach (['pg_dump_version', 'pg_restore_version'] as $field) $nonEmpty($backup[$field] ?? null, 'backup.' . $field);
$startedAt = $timestamp($backup['started_at'] ?? null, 'backup.started_at');
$completedAt = $timestamp($backup['completed_at'] ?? null, 'backup.completed_at');
if ($completedAt <= $startedAt) throw new RuntimeException('backup.completed_at must be after started_at');
if (!is_array($backup['restore_flags'])) throw new RuntimeException('backup.restore_flags must be an array');
$flags = array_values(array_unique(array_map(static fn (mixed $flag): string => is_string($flag) ? $flag : '', $backup['restore_flags'])));
foreach (['--exit-on-error', '--single-transaction', '--no-owner', '--no-acl'] as $requiredFlag) if (!in_array($requiredFlag, $flags, true)) throw new RuntimeException('restore flags are missing ' . $requiredFlag);
foreach (['--create', '-C', '--clean', '-c'] as $forbiddenFlag) if (in_array($forbiddenFlag, $flags, true)) throw new RuntimeException('restore flags contain forbidden ' . $forbiddenFlag);

$state = $report['state'];
$stateKeys = ['source', 'restored'];
$expectKeys($state, $stateKeys, $stateKeys, 'state');
$countKeys = ['sand_iam_tables', 'migration_rows', 'organizations', 'applications', 'environments', 'identities', 'active_credentials', 'revoked_credentials', 'revoked_sessions', 'audit_rows'];
foreach ($stateKeys as $side) {
    if (!is_array($state[$side])) throw new RuntimeException('state.' . $side . ' must be an object');
    $expectKeys($state[$side], array_merge(['logical_state_sha256', 'audit_chain_sha256'], $countKeys), array_merge(['logical_state_sha256', 'audit_chain_sha256'], $countKeys), 'state.' . $side);
    foreach (['logical_state_sha256', 'audit_chain_sha256'] as $field) if (preg_match('/^[0-9a-f]{64}$/', (string) ($state[$side][$field] ?? '')) !== 1) throw new RuntimeException('state.' . $side . '.' . $field . ' must be SHA-256');
    foreach ($countKeys as $field) if (!is_int($state[$side][$field] ?? null) || $state[$side][$field] < 0) throw new RuntimeException('state.' . $side . '.' . $field . ' must be a non-negative integer');
}
foreach (array_merge(['logical_state_sha256', 'audit_chain_sha256'], $countKeys) as $field) {
    if ($state['source'][$field] !== $state['restored'][$field]) throw new RuntimeException('source/restored state mismatch: ' . $field);
}

$requiredChecks = ['archive_list_complete', 'restore_single_transaction', 'migration_ledger_equal', 'authorization_allow_equal', 'authorization_deny_equal', 'revoked_access_denied', 'revoked_sessions_not_resurrected', 'audit_chain_equal', 'signature_verification_equal', 'post_restore_audit_append', 'host_objects_unchanged', 'other_plugins_unchanged', 'cleanup_verified'];
$expectKeys($report['checks'], $requiredChecks, $requiredChecks, 'checks');
foreach ($requiredChecks as $check) if (($report['checks'][$check] ?? null) !== true) throw new RuntimeException('recovery did not prove ' . $check);
if (($report['unresolved_failures'] ?? null) !== 0) throw new RuntimeException('recovery has unresolved failures');

$requiredEvidenceTypes = ['backup-command', 'archive-list', 'restore-command', 'source-state', 'restored-state', 'business-probes', 'cleanup'];
$seenTypes = [];
$seenPaths = [];
foreach ($report['evidence'] as $evidence) {
    if (!is_array($evidence)) throw new RuntimeException('evidence reference must be an object');
    $expectKeys($evidence, ['type', 'path', 'sha256'], ['type', 'path', 'sha256'], 'evidence');
    $type = $evidence['type'] ?? null;
    if (!is_string($type) || !in_array($type, $requiredEvidenceTypes, true) || isset($seenTypes[$type])) throw new RuntimeException('unknown or duplicate recovery evidence type');
    $seenTypes[$type] = true;
    $relative = $evidence['path'] ?? null;
    if (!is_string($relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || preg_match('#(?:^|/)\.\.?(?:/|$)#', $relative) === 1 || isset($seenPaths[$relative])) throw new RuntimeException('evidence path must be unique, safe, and relative');
    $seenPaths[$relative] = true;
    $lexicalPath = $reportRoot;
    foreach (explode('/', $relative) as $component) {
        $lexicalPath .= '/' . $component;
        if (is_link($lexicalPath)) throw new RuntimeException('evidence path contains a symbolic link: ' . $relative);
    }
    $path = realpath($lexicalPath);
    if (!is_string($path) || !is_file($path) || !str_starts_with($path, $reportRoot . '/')) throw new RuntimeException('evidence file is missing or outside report root: ' . $relative);
    $hash = hash_file('sha256', $path);
    if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/', (string) ($evidence['sha256'] ?? '')) !== 1 || !hash_equals((string) $evidence['sha256'], $hash)) throw new RuntimeException('evidence SHA-256 mismatch: ' . $relative);
    $bytes = file_get_contents($path);
    if (is_string($bytes) && preg_match('//u', $bytes) === 1 && preg_match('/(?:postgres(?:ql)?:\/\/[^\s:@]+:[^\s@]+@)|-----BEGIN [A-Z ]*PRIVATE KEY-----|\bsiam_(?:at|wc|rt)_[A-Za-z0-9_-]{8,}\b/i', $bytes) === 1) throw new RuntimeException('evidence contains a high-confidence secret: ' . $relative);
}
$missingTypes = array_values(array_diff($requiredEvidenceTypes, array_keys($seenTypes)));
if ($missingTypes !== []) throw new RuntimeException('recovery evidence is missing types: ' . implode(', ', $missingTypes));

echo json_encode([
    'schema' => 'sand-iam.backup-recovery-validation/v1', 'passed' => true,
    'checks_verified' => count($requiredChecks), 'state_fields_verified' => count($countKeys) + 2,
    'evidence_files_verified' => count($seenPaths), 'reviewer_id' => $reviewer['id'],
    'environment_fingerprint' => $environment['fingerprint'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
