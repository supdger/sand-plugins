<?php

declare(strict_types=1);

/** Validate an independent developer's public-docs-only installation and integration record. */

$options = getopt('', ['report:']);
$reportPath = $options['report'] ?? null;
if (!is_string($reportPath) || trim($reportPath) === '') throw new InvalidArgumentException('--report is required');
$reportPath = realpath($reportPath);
if (!is_string($reportPath) || !is_file($reportPath) || is_link($reportPath)) throw new RuntimeException('independent delivery report must be an existing regular file');
$reportRoot = dirname($reportPath);
$report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($report) || ($report['schema'] ?? null) !== 'sand-iam.independent-delivery/v1') throw new RuntimeException('invalid independent delivery report schema');

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
$parseTimestamp = static function (mixed $value, string $label): DateTimeImmutable {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) throw new RuntimeException($label . ' must use exact UTC second precision');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d\TH:i:s\Z') !== $value
        || (is_array($errors) && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))) throw new RuntimeException($label . ' must be a valid UTC timestamp');
    return $parsed;
};

$topKeys = ['schema', 'candidate', 'participant', 'environment', 'public_materials', 'steps', 'assertions', 'unresolved_failures'];
$expectKeys($report, $topKeys, $topKeys, 'report');
foreach (['candidate', 'participant', 'environment', 'public_materials', 'steps', 'assertions'] as $section) if (!is_array($report[$section])) throw new RuntimeException($section . ' must be an object or array');

$candidate = $report['candidate'];
$expectKeys($candidate, ['version', 'archive_sha256', 'artifact_manifest_sha256'], ['version', 'archive_sha256', 'artifact_manifest_sha256'], 'candidate');
if (preg_match('/^\d+\.\d+\.\d+$/', (string) ($candidate['version'] ?? '')) !== 1) throw new RuntimeException('candidate version must be semantic');
foreach (['archive_sha256', 'artifact_manifest_sha256'] as $field) if (preg_match('/^[0-9a-f]{64}$/', (string) ($candidate[$field] ?? '')) !== 1) throw new RuntimeException('candidate.' . $field . ' must be SHA-256');

$participant = $report['participant'];
$participantKeys = ['id', 'independent', 'contributed_code', 'prior_sandiam_experience', 'developer_assistance_requests', 'conflict_statement'];
$expectKeys($participant, $participantKeys, $participantKeys, 'participant');
$nonEmpty($participant['id'] ?? null, 'participant.id');
$nonEmpty($participant['conflict_statement'] ?? null, 'participant.conflict_statement');
if (($participant['independent'] ?? null) !== true || ($participant['contributed_code'] ?? null) !== false || ($participant['prior_sandiam_experience'] ?? null) !== false || ($participant['developer_assistance_requests'] ?? null) !== 0) {
    throw new RuntimeException('participant must be independent, new to SandIAM, and receive no developer assistance');
}

$environment = $report['environment'];
$environmentKeys = ['fingerprint', 'host', 'fresh_host', 'sandadmin_revision', 'sandpackage_version', 'php', 'postgresql'];
$expectKeys($environment, $environmentKeys, $environmentKeys, 'environment');
if (preg_match('/^[0-9a-f]{64}$/', (string) ($environment['fingerprint'] ?? '')) !== 1) throw new RuntimeException('environment fingerprint must be SHA-256');
foreach (['host', 'sandadmin_revision', 'sandpackage_version', 'php', 'postgresql'] as $field) $nonEmpty($environment[$field] ?? null, 'environment.' . $field);
if (($environment['fresh_host'] ?? null) !== true) throw new RuntimeException('independent delivery requires a fresh host');

$requiredMaterials = ['README.md', 'CONTRIBUTING.md', 'SECURITY.md', 'docs/user-guide/installation-and-upgrade.md', 'docs/user-guide/configuration-reference.md', 'docs/user-guide/application-integration.md', 'docs/user-guide/application-user-guide.md', 'docs/user-guide/sand-iam-operator-guide.md', 'docs/user-guide/troubleshooting.md', 'docs/user-guide/security-hardening.md', 'docs/user-guide/backup-and-restore.md', 'docs/user-guide/release-package-verification.md'];
if ($report['public_materials'] !== $requiredMaterials) throw new RuntimeException('public_materials must be the exact ordered public documentation set');

$requiredSteps = ['package-verification', 'runtime-preflight', 'fresh-install', 'initial-configuration', 'human-business-app', 'machine-service', 'failure-recovery', 'uninstall-cleanup'];
$seenSteps = [];
$seenEvidence = [];
$totalDuration = 0;
$totalOperations = 0;
foreach ($report['steps'] as $index => $step) {
    if (!is_array($step)) throw new RuntimeException('step must be an object at index ' . $index);
    $stepKeys = ['id', 'started_at', 'ended_at', 'duration_seconds', 'manual_operations', 'recovery_attempts', 'completed', 'evidence'];
    $expectKeys($step, $stepKeys, $stepKeys, 'step');
    $id = $step['id'] ?? null;
    if (!is_string($id) || !in_array($id, $requiredSteps, true) || isset($seenSteps[$id])) throw new RuntimeException('unknown or duplicate delivery step');
    $seenSteps[$id] = true;
    $startedAt = $parseTimestamp($step['started_at'] ?? null, $id . ' started_at');
    $endedAt = $parseTimestamp($step['ended_at'] ?? null, $id . ' ended_at');
    foreach (['duration_seconds', 'manual_operations', 'recovery_attempts'] as $field) if (!is_int($step[$field] ?? null) || $step[$field] < 0) throw new RuntimeException($id . ' has invalid ' . $field);
    if ($endedAt->getTimestamp() - $startedAt->getTimestamp() !== $step['duration_seconds']) throw new RuntimeException($id . ' timestamps do not match duration');
    if (($step['completed'] ?? null) !== true) throw new RuntimeException($id . ' was not completed');
    if (!is_array($step['evidence']) || $step['evidence'] === []) throw new RuntimeException($id . ' has no evidence');
    foreach ($step['evidence'] as $evidence) {
        if (!is_array($evidence)) throw new RuntimeException('evidence reference must be an object');
        $expectKeys($evidence, ['path', 'sha256'], ['path', 'sha256'], 'evidence');
        $relative = $evidence['path'] ?? null;
        if (!is_string($relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || preg_match('#(?:^|/)\.\.?(?:/|$)#', $relative) === 1 || isset($seenEvidence[$relative])) throw new RuntimeException('evidence path must be unique, safe, and relative');
        $seenEvidence[$relative] = true;
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
    $totalDuration += $step['duration_seconds'];
    $totalOperations += $step['manual_operations'];
}
$missingSteps = array_values(array_diff($requiredSteps, array_keys($seenSteps)));
if ($missingSteps !== []) throw new RuntimeException('independent delivery is missing steps: ' . implode(', ', $missingSteps));

$requiredAssertions = ['public_docs_only', 'no_internal_material', 'no_undocumented_commands', 'package_signature_verified', 'fresh_install_succeeded', 'configuration_preflight_succeeded', 'human_business_side_effect_verified', 'machine_business_side_effect_verified', 'allow_deny_revocation_verified', 'dual_audit_verified', 'error_recovery_succeeded', 'host_other_plugins_unchanged', 'no_secret_retained', 'cleanup_verified'];
$expectKeys($report['assertions'], $requiredAssertions, $requiredAssertions, 'assertions');
foreach ($requiredAssertions as $assertion) if (($report['assertions'][$assertion] ?? null) !== true) throw new RuntimeException('independent delivery did not prove ' . $assertion);
if (($report['unresolved_failures'] ?? null) !== 0) throw new RuntimeException('independent delivery has unresolved failures');

echo json_encode([
    'schema' => 'sand-iam.independent-delivery-validation/v1', 'passed' => true,
    'steps_passed' => count($requiredSteps), 'assertions_verified' => count($requiredAssertions),
    'evidence_files_verified' => count($seenEvidence), 'total_duration_seconds' => $totalDuration,
    'total_manual_operations' => $totalOperations, 'participant_id' => $participant['id'],
    'environment_fingerprint' => $environment['fingerprint'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
