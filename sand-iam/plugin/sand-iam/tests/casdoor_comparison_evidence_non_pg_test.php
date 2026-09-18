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
    $archiveHash = str_repeat('a', 64);
    $manifestHash = str_repeat('b', 64);
    $environmentHash = str_repeat('c', 64);
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
                $measurementComplete = $system === 'sandiam';
                $securityTargetMet = $system === 'sandiam';
                $metrics = $measurementComplete ? [
                    'duration_seconds' => $duration,
                    'manual_operations' => $operations,
                    'commands' => 2,
                    'recovery_attempts' => 0,
                    'unresolved_failures' => 0,
                    'business_code_change_points' => 1,
                ] : [
                    'duration_seconds' => null,
                    'manual_operations' => null,
                    'commands' => null,
                    'recovery_attempts' => null,
                    'unresolved_failures' => null,
                    'business_code_change_points' => null,
                ];
                $startedAt = '2026-09-12T00:00:00Z';
                $endedAt = gmdate('Y-m-d\TH:i:s\Z', strtotime($startedAt) + $duration);
                $runDirectory = 'evidence/' . $journeyId . '/' . $system . '-' . $round;
                if (!mkdir($seed . '/' . $runDirectory, 0700, true)) throw new RuntimeException('cannot create comparison run evidence directory');
                $evidence = [];
                $pathsByKind = [];
                foreach (['browser', 'system', 'cleanup'] as $kind) {
                    $relative = $runDirectory . '/' . $kind . '.json';
                    $bytes = json_encode(['journey' => $journeyId, 'system' => $system, 'round' => $round, 'kind' => $kind], JSON_THROW_ON_ERROR) . "\n";
                    file_put_contents($seed . '/' . $relative, $bytes);
                    $evidence[] = ['kind' => $kind, 'path' => $relative, 'sha256' => hash('sha256', $bytes)];
                    $pathsByKind[$kind] = $relative;
                }
                $structured = [
                    'schema' => 'sand-iam.casdoor-comparison-evidence/v2',
                    'journey' => $journeyId,
                    'system' => $system,
                    'round' => $round,
                    'candidate_archive_sha256' => $archiveHash,
                    'environment_fingerprint' => $environmentHash,
                    'started_at' => $startedAt,
                    'ended_at' => $endedAt,
                    'metrics' => $metrics,
                    'assertions' => [
                        'measurement_complete' => $measurementComplete,
                        'completed' => true,
                        'business_outcome_achieved' => true,
                        'security_target_met' => $securityTargetMet,
                        'cleanup_verified' => true,
                    ],
                    'request_ids' => ['compare-' . $journeyId . '-' . $system . '-' . $round],
                    'business_effect_refs' => ['business-effect-' . $journeyId . '-' . $system . '-' . $round],
                    'audit_refs' => ['audit-' . $journeyId . '-' . $system . '-' . $round],
                    'artifacts' => [
                        'browser' => [$pathsByKind['browser']],
                        'system' => [$pathsByKind['system']],
                        'cleanup' => [$pathsByKind['cleanup']],
                    ],
                    'cleanup' => ['verified' => true, 'residual_count' => 0],
                ];
                $structuredPath = $runDirectory . '/structured.json';
                $structuredBytes = json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                file_put_contents($seed . '/' . $structuredPath, $structuredBytes);
                array_unshift($evidence, ['kind' => 'structured', 'path' => $structuredPath, 'sha256' => hash('sha256', $structuredBytes)]);
                $runs[] = [
                    'system' => $system, 'round' => $round,
                    'started_at' => $startedAt,
                    'ended_at' => $endedAt,
                    ...$metrics, 'measurement_complete' => $measurementComplete,
                    'completed' => true, 'business_outcome_achieved' => true,
                    'security_target_met' => $securityTargetMet, 'cleanup_verified' => true,
                    'evidence' => $evidence,
                ];
            }
        }
        $journeys[] = ['id' => $journeyId, 'runs' => $runs];
    }
    $report = [
        'schema' => 'sand-iam.casdoor-comparison/v3',
        'candidate' => ['version' => '0.7.0', 'archive_sha256' => $archiveHash, 'artifact_manifest_sha256' => $manifestHash],
        'comparison_policy' => [
            'sandiam_acceptance_basis' => 'absolute_target',
            'comparator_role' => 'relative_observation',
            'require_comparator_security_target' => false,
            'require_quantitative_superiority' => false,
            'quantitative_superiority_claimed' => false,
        ],
        'participants' => [
            'sandiam' => ['id' => 'sandiam-participant-01', 'independent' => true, 'webman_experience' => true, 'conflict_statement' => 'I did not implement SandIAM.'],
            'casdoor' => ['id' => 'casdoor-participant-02', 'independent' => true, 'webman_experience' => true, 'conflict_statement' => 'I did not implement Casdoor.'],
            'same_participant' => false,
        ],
        'reviewer' => ['id' => 'independent-reviewer-01', 'independent' => true, 'webman_experience' => true, 'conflict_statement' => 'I did not develop either tested integration.'],
        'environment' => ['fingerprint' => $environmentHash, 'host' => 'fixture-host', 'browser' => 'fixture-browser', 'php' => '8.4', 'postgresql' => '18', 'network_profile' => 'same-local-profile'],
        'journeys' => $journeys,
    ];
    $reportPath = $seed . '/report.json';
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$validStatus, $validOutput] = $run($reportPath);

    $validReport = $report;

    $tamperedEvidenceHash = $validReport;
    $tamperedEvidenceHash['journeys'][0]['runs'][0]['evidence'][0]['sha256'] = str_repeat('f', 64);
    file_put_contents($reportPath, json_encode($tamperedEvidenceHash, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$tamperedEvidenceHashStatus, $tamperedEvidenceHashOutput] = $run($reportPath);

    $uncleanReport = $validReport;
    $uncleanReport['journeys'][0]['runs'][0]['cleanup_verified'] = false;
    file_put_contents($reportPath, json_encode($uncleanReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$uncleanStatus, $uncleanOutput] = $run($reportPath);

    $structuredPath = $seed . '/evidence/webman-api-governance/sandiam-1/structured.json';
    $structuredBytes = (string) file_get_contents($structuredPath);
    $structuredBinding = $validReport;
    $structuredDocument = json_decode($structuredBytes, true, 512, JSON_THROW_ON_ERROR);
    $structuredDocument['candidate_archive_sha256'] = str_repeat('d', 64);
    $changedStructuredBytes = json_encode($structuredDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($structuredPath, $changedStructuredBytes);
    $structuredBinding['journeys'][0]['runs'][0]['evidence'][0]['sha256'] = hash('sha256', $changedStructuredBytes);
    file_put_contents($reportPath, json_encode($structuredBinding, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$structuredBindingStatus, $structuredBindingOutput] = $run($reportPath);
    file_put_contents($structuredPath, $structuredBytes);

    $missingSystemArtifact = $validReport;
    $structuredDocument = json_decode($structuredBytes, true, 512, JSON_THROW_ON_ERROR);
    $structuredDocument['artifacts']['system'] = [];
    $changedStructuredBytes = json_encode($structuredDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($structuredPath, $changedStructuredBytes);
    $missingSystemArtifact['journeys'][0]['runs'][0]['evidence'][0]['sha256'] = hash('sha256', $changedStructuredBytes);
    file_put_contents($reportPath, json_encode($missingSystemArtifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$missingSystemArtifactStatus, $missingSystemArtifactOutput] = $run($reportPath);
    file_put_contents($structuredPath, $structuredBytes);

    $report['journeys'][0]['runs'][0]['security_target_met'] = false;
    $structuredDocument = json_decode($structuredBytes, true, 512, JSON_THROW_ON_ERROR);
    $structuredDocument['assertions']['security_target_met'] = false;
    $changedStructuredBytes = json_encode($structuredDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($structuredPath, $changedStructuredBytes);
    $report['journeys'][0]['runs'][0]['evidence'][0]['sha256'] = hash('sha256', $changedStructuredBytes);
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$unsafeStatus, $unsafeOutput] = $run($reportPath);
    file_put_contents($structuredPath, $structuredBytes);
    $report['journeys'][0]['runs'][0]['security_target_met'] = true;
    $report['journeys'][0]['runs'][0]['evidence'][0]['sha256'] = hash('sha256', $structuredBytes);
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

    $inconsistentParticipants = $validReport;
    $inconsistentParticipants['participants']['same_participant'] = true;
    file_put_contents($reportPath, json_encode($inconsistentParticipants, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$inconsistentParticipantsStatus, $inconsistentParticipantsOutput] = $run($reportPath);

    $unprovedQuantitativeClaim = $validReport;
    $unprovedQuantitativeClaim['comparison_policy']['quantitative_superiority_claimed'] = true;
    file_put_contents($reportPath, json_encode($unprovedQuantitativeClaim, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$unprovedQuantitativeClaimStatus, $unprovedQuantitativeClaimOutput] = $run($reportPath);

    $quantitativeReport = $validReport;
    $quantitativeReport['comparison_policy']['quantitative_superiority_claimed'] = true;
    $quantitativeReport['participants']['casdoor'] = $quantitativeReport['participants']['sandiam'];
    $quantitativeReport['participants']['same_participant'] = true;
    foreach ($quantitativeReport['journeys'] as &$journey) {
        $journeyId = $journey['id'];
        foreach ($journey['runs'] as &$candidateRun) {
            if ($candidateRun['system'] !== 'casdoor') continue;
            $duration = strtotime($candidateRun['ended_at']) - strtotime($candidateRun['started_at']);
            $metrics = [
                'duration_seconds' => $duration,
                'manual_operations' => $journeyTargets[$journeyId][1] + 1,
                'commands' => 2,
                'recovery_attempts' => 0,
                'unresolved_failures' => 0,
                'business_code_change_points' => 1,
            ];
            foreach ($metrics as $field => $value) $candidateRun[$field] = $value;
            $structuredRelative = $candidateRun['evidence'][0]['path'];
            $structuredDocument = json_decode((string) file_get_contents($seed . '/' . $structuredRelative), true, 512, JSON_THROW_ON_ERROR);
            $structuredDocument['metrics'] = $metrics;
            $structuredBytes = json_encode($structuredDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            file_put_contents($seed . '/' . $structuredRelative, $structuredBytes);
            $candidateRun['evidence'][0]['sha256'] = hash('sha256', $structuredBytes);
        }
        unset($candidateRun);
    }
    unset($journey);
    file_put_contents($reportPath, json_encode($quantitativeReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$incompleteQuantitativeStatus, $incompleteQuantitativeOutput] = $run($reportPath);

    foreach ($quantitativeReport['journeys'] as &$journey) {
        foreach ($journey['runs'] as &$candidateRun) {
            if ($candidateRun['system'] !== 'casdoor') continue;
            $candidateRun['measurement_complete'] = true;
            $structuredRelative = $candidateRun['evidence'][0]['path'];
            $structuredDocument = json_decode((string) file_get_contents($seed . '/' . $structuredRelative), true, 512, JSON_THROW_ON_ERROR);
            $structuredDocument['assertions']['measurement_complete'] = true;
            $structuredBytes = json_encode($structuredDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            file_put_contents($seed . '/' . $structuredRelative, $structuredBytes);
            $candidateRun['evidence'][0]['sha256'] = hash('sha256', $structuredBytes);
        }
        unset($candidateRun);
    }
    unset($journey);
    file_put_contents($reportPath, json_encode($quantitativeReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    [$quantitativeStatus, $quantitativeOutput] = $run($reportPath);

    $passed = $validStatus === 0 && str_contains($validOutput, '"runs_verified": 12') && str_contains($validOutput, '"evidence_files_verified": 48')
        && str_contains($validOutput, '"casdoor_mean_duration_seconds": null')
        && $tamperedEvidenceHashStatus !== 0 && str_contains($tamperedEvidenceHashOutput, 'evidence SHA-256 mismatch')
        && $uncleanStatus !== 0 && str_contains($uncleanOutput, 'did not prove cleanup_verified')
        && $structuredBindingStatus !== 0 && str_contains($structuredBindingOutput, 'structured evidence binding mismatch')
        && $missingSystemArtifactStatus !== 0 && str_contains($missingSystemArtifactOutput, 'structured artifact binding is invalid: system')
        && $unsafeStatus !== 0 && str_contains($unsafeOutput, 'SandIAM did not meet its security target')
        && $missingStatus !== 0 && str_contains($missingOutput, 'must contain exactly four runs')
        && $linkedStatus !== 0 && str_contains($linkedOutput, 'path contains a symbolic link')
        && $invalidTimestampStatus !== 0 && str_contains($invalidTimestampOutput, 'must be a valid UTC timestamp')
        && $inconsistentParticipantsStatus !== 0 && str_contains($inconsistentParticipantsOutput, 'same_participant must match')
        && $unprovedQuantitativeClaimStatus !== 0 && str_contains($unprovedQuantitativeClaimOutput, 'quantitative superiority requires the same')
        && $incompleteQuantitativeStatus !== 0 && str_contains($incompleteQuantitativeOutput, 'quantitative superiority requires complete measurement')
        && $quantitativeStatus === 0 && str_contains($quantitativeOutput, '"quantitative_superiority_claimed": true');
    if (!$passed) throw new RuntimeException('comparison validator did not separate SandIAM acceptance from comparator gaps');
} finally {
    $removeTree($seed);
}

echo "SandIAM Casdoor comparison evidence checks passed\n";
