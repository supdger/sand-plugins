<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$validator = $root . '/tools/validate-independent-delivery.php';
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
$seed = tempnam('/private/tmp', 'sand-iam-independent-');
if ($seed === false || !unlink($seed) || !mkdir($seed, 0700) || !mkdir($seed . '/evidence', 0700)) throw new RuntimeException('cannot create independent delivery fixture');

try {
    $stepIds = ['package-verification', 'runtime-preflight', 'fresh-install', 'initial-configuration', 'human-business-app', 'machine-service', 'failure-recovery', 'uninstall-cleanup'];
    $steps = [];
    foreach ($stepIds as $index => $id) {
        $path = 'evidence/' . $id . '.json';
        $bytes = json_encode(['step' => $id, 'result' => 'redacted-pass'], JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($seed . '/' . $path, $bytes);
        $start = strtotime('2026-09-12T00:00:00Z') + ($index * 600);
        $steps[] = [
            'id' => $id, 'started_at' => gmdate('Y-m-d\TH:i:s\Z', $start), 'ended_at' => gmdate('Y-m-d\TH:i:s\Z', $start + 300),
            'duration_seconds' => 300, 'manual_operations' => 3, 'recovery_attempts' => $id === 'failure-recovery' ? 1 : 0,
            'completed' => true, 'evidence' => [['path' => $path, 'sha256' => hash('sha256', $bytes)]],
        ];
    }
    $materials = ['README.md', 'CONTRIBUTING.md', 'SECURITY.md', 'docs/user-guide/installation-and-upgrade.md', 'docs/user-guide/configuration-reference.md', 'docs/user-guide/application-integration.md', 'docs/user-guide/application-user-guide.md', 'docs/user-guide/sand-iam-operator-guide.md', 'docs/user-guide/troubleshooting.md', 'docs/user-guide/security-hardening.md', 'docs/user-guide/backup-and-restore.md', 'docs/user-guide/release-package-verification.md'];
    $assertions = array_fill_keys(['public_docs_only', 'no_internal_material', 'no_undocumented_commands', 'package_signature_verified', 'fresh_install_succeeded', 'configuration_preflight_succeeded', 'human_business_side_effect_verified', 'machine_business_side_effect_verified', 'allow_deny_revocation_verified', 'dual_audit_verified', 'error_recovery_succeeded', 'host_other_plugins_unchanged', 'no_secret_retained', 'cleanup_verified'], true);
    $valid = [
        'schema' => 'sand-iam.independent-delivery/v1',
        'candidate' => ['version' => '0.7.0', 'archive_sha256' => str_repeat('a', 64), 'artifact_manifest_sha256' => str_repeat('b', 64)],
        'participant' => ['id' => 'new-developer-01', 'independent' => true, 'contributed_code' => false, 'prior_sandiam_experience' => false, 'developer_assistance_requests' => 0, 'conflict_statement' => 'I did not contribute to SandIAM or receive private guidance.'],
        'environment' => ['fingerprint' => str_repeat('c', 64), 'host' => 'fresh-controlled-host', 'fresh_host' => true, 'sandadmin_revision' => str_repeat('d', 40), 'sandpackage_version' => '6.1.4', 'php' => '8.2.29', 'postgresql' => '18.0'],
        'public_materials' => $materials, 'steps' => $steps, 'assertions' => $assertions, 'unresolved_failures' => 0,
    ];
    $reportPath = $seed . '/report.json';
    $write = static fn (array $report): int|false => file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $write($valid);
    [$validStatus, $validOutput] = $run($reportPath);

    $assisted = $valid;
    $assisted['participant']['developer_assistance_requests'] = 1;
    $write($assisted);
    [$assistedStatus, $assistedOutput] = $run($reportPath);

    $internalDocs = $valid;
    $internalDocs['public_materials'][] = 'docs/development/internal-task-board.md';
    $write($internalDocs);
    [$docsStatus, $docsOutput] = $run($reportPath);

    $missingStep = $valid;
    array_splice($missingStep['steps'], 5, 1);
    $write($missingStep);
    [$stepStatus, $stepOutput] = $run($reportPath);

    $falseOutcome = $valid;
    $falseOutcome['assertions']['dual_audit_verified'] = false;
    $write($falseOutcome);
    [$outcomeStatus, $outcomeOutput] = $run($reportPath);

    $passed = $validStatus === 0 && str_contains($validOutput, '"steps_passed": 8') && str_contains($validOutput, '"assertions_verified": 14')
        && $assistedStatus !== 0 && str_contains($assistedOutput, 'receive no developer assistance')
        && $docsStatus !== 0 && str_contains($docsOutput, 'exact ordered public documentation set')
        && $stepStatus !== 0 && str_contains($stepOutput, 'independent delivery is missing steps: machine-service')
        && $outcomeStatus !== 0 && str_contains($outcomeOutput, 'did not prove dual_audit_verified');
    if (!$passed) throw new RuntimeException('independent delivery validator did not enforce public-docs-only end-to-end evidence');
} finally {
    $removeTree($seed);
}

echo "SandIAM independent delivery evidence checks passed\n";
