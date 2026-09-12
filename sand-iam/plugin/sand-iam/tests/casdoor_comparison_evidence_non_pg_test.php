<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$validator = $root . '/tools/validate-casdoor-comparison.php';

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

$seed = tempnam('/private/tmp', 'sand-iam-casdoor-');
if ($seed === false || !unlink($seed) || !mkdir($seed, 0700) || !mkdir($seed . '/evidence', 0700)) throw new RuntimeException('cannot create comparison fixture');
try {
    $journeyTargets = [
        'webman-api-governance' => [600, 6],
        'human-auth-mfa-business-api' => [400, 7],
        'machine-service-action' => [500, 6],
    ];
    $journeys = [];
    foreach ($journeyTargets as $journeyId => [$sandiamDuration, $sandiamOperations]) {
        $runs = [];
        foreach (['sandiam', 'casdoor'] as $system) {
            foreach ([1, 2] as $round) {
                $duration = $system === 'sandiam' ? $sandiamDuration + $round : $sandiamDuration + 101 + $round;
                $operations = $system === 'sandiam' ? $sandiamOperations : $sandiamOperations + 1;
                $evidencePath = 'evidence/' . $journeyId . '-' . $system . '-' . $round . '.json';
                $evidenceBytes = json_encode(['journey' => $journeyId, 'system' => $system, 'round' => $round], JSON_THROW_ON_ERROR) . "\n";
                file_put_contents($seed . '/' . $evidencePath, $evidenceBytes);
                $runs[] = [
                    'system' => $system, 'round' => $round,
                    'started_at' => '2026-09-12T00:00:00Z',
                    'ended_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-09-12T00:00:00Z') + $duration),
                    'duration_seconds' => $duration, 'manual_operations' => $operations,
                    'commands' => 2, 'recovery_attempts' => 0, 'unresolved_failures' => 0,
                    'business_code_change_points' => 1, 'completed' => true,
                    'result_equivalent' => true, 'security_equivalent' => true, 'cleanup_verified' => true,
                    'evidence' => [['path' => $evidencePath, 'sha256' => hash('sha256', $evidenceBytes)]],
                ];
            }
        }
        $journeys[] = ['id' => $journeyId, 'runs' => $runs];
    }
    $report = [
        'schema' => 'sand-iam.casdoor-comparison/v1',
        'candidate' => ['version' => '0.7.0', 'archive_sha256' => str_repeat('a', 64), 'artifact_manifest_sha256' => str_repeat('b', 64)],
        'reviewer' => ['id' => 'independent-reviewer-01', 'independent' => true, 'webman_experience' => true, 'conflict_statement' => 'I did not develop either tested integration.'],
        'environment' => ['fingerprint' => str_repeat('c', 64), 'host' => 'fixture-host', 'browser' => 'fixture-browser', 'php' => '8.4', 'postgresql' => '18', 'network_profile' => 'same-local-profile'],
        'journeys' => $journeys,
    ];
    $reportPath = $seed . '/report.json';
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$validStatus, $validOutput] = $run($reportPath);

    $validReport = $report;

    $report['journeys'][0]['runs'][0]['security_equivalent'] = false;
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$unsafeStatus, $unsafeOutput] = $run($reportPath);
    $report['journeys'][0]['runs'][0]['security_equivalent'] = true;
    array_pop($report['journeys'][1]['runs']);
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$missingStatus, $missingOutput] = $run($reportPath);

    if (!symlink($seed . '/evidence', $seed . '/linked-evidence')) throw new RuntimeException('cannot create evidence link fixture');
    $linkedReport = $validReport;
    $linkedReport['journeys'][0]['runs'][0]['evidence'][0]['path'] = str_replace('evidence/', 'linked-evidence/', $linkedReport['journeys'][0]['runs'][0]['evidence'][0]['path']);
    file_put_contents($reportPath, json_encode($linkedReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$linkedStatus, $linkedOutput] = $run($reportPath);

    $invalidTimestampReport = $validReport;
    $invalidTimestampReport['journeys'][0]['runs'][0]['started_at'] = '2026-02-30T00:00:00Z';
    file_put_contents($reportPath, json_encode($invalidTimestampReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$invalidTimestampStatus, $invalidTimestampOutput] = $run($reportPath);

    $passed = $validStatus === 0 && str_contains($validOutput, '"runs_verified": 12')
        && $unsafeStatus !== 0 && str_contains($unsafeOutput, 'did not prove security_equivalent')
        && $missingStatus !== 0 && str_contains($missingOutput, 'must contain exactly four runs')
        && $linkedStatus !== 0 && str_contains($linkedOutput, 'path contains a symbolic link')
        && $invalidTimestampStatus !== 0 && str_contains($invalidTimestampOutput, 'must be a valid UTC timestamp');
    if (!$passed) throw new RuntimeException('comparison validator did not enforce complete and security-equivalent evidence');
} finally {
    $removeTree($seed);
}

echo "SandIAM Casdoor comparison evidence checks passed\n";
