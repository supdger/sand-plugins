<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$tool = $root . '/tools/run-endurance-acceptance.php';
$verifier = $root . '/tools/verify-endurance-evidence.php';
$seed = tempnam('/private/tmp', 'sand-iam-endurance-plan-');
if ($seed === false) throw new RuntimeException('cannot create endurance plan fixture');

/** @return array{0:int,1:string} */
$run = static function (string $path) use ($tool): array {
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' --plan=' . escapeshellarg($path) . ' --validate-only 2>&1', $output, $status);
    return [$status, implode("\n", $output)];
};

/** @return array{0:int,1:string} */
$verify = static function (string $planPath, string $evidencePath, string $summaryPath, string $archivePath, string $manifestPath) use ($verifier): array {
    if (!chmod($evidencePath, 0444) || (!is_link($summaryPath) && !chmod($summaryPath, 0444))) throw new RuntimeException('cannot seal immutable verifier fixture');
    $output = [];
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($verifier)
        . ' --plan=' . escapeshellarg($planPath) . ' --evidence=' . escapeshellarg($evidencePath)
        . ' --summary=' . escapeshellarg($summaryPath) . ' --archive=' . escapeshellarg($archivePath)
        . ' --artifact-manifest=' . escapeshellarg($manifestPath);
    exec($command . ' 2>&1', $output, $status);
    return [$status, implode("\n", $output)];
};

$canonicalize = null;
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = $canonicalize($item);
    return $value;
};
$canonicalJson = static fn (mixed $value): string => json_encode($canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

$categories = ['candidate', 'health', 'allow', 'deny', 'revoked', 'audit', 'metrics'];
$targets = [];
foreach ($categories as $category) {
    $jsonExpect = match ($category) {
        'candidate' => ['/archive_sha256' => str_repeat('a', 64), '/artifact_manifest_sha256' => str_repeat('b', 64)],
        'allow' => ['/allowed' => true],
        'deny', 'revoked' => ['/allowed' => false],
        'audit' => ['/acceptance_run_id' => 'sand_iam_endurance_0123456789abcdef'],
        'health' => ['/ok' => true, '/sample_number' => 7, '/sample_null' => null, '/password' => 'not-a-real-password', '/client_secret' => 'not-a-real-client-secret', '/otp' => 987321],
        default => ['/ok' => true],
    };
    $targets[] = [
        'id' => $category . '-probe', 'category' => $category,
        'url' => 'https://endurance.example.invalid/' . $category, 'method' => $category === 'audit' ? 'POST' : 'GET', 'effect' => $category === 'audit' ? 'audit_only' : 'read_only',
        'authorization_env' => null, 'body' => $category === 'audit' ? ['acceptance_run_id' => 'sand_iam_endurance_0123456789abcdef'] : null, 'expected_status' => 200,
        'json_expect' => $jsonExpect, 'max_p99_ms' => 500,
        'audit_context' => in_array($category, ['allow', 'deny', 'revoked'], true) ? ['decision' => $category === 'allow' ? 'allow' : $category, 'subject' => 'subject-test-001', 'scope' => 'scope-test-001', 'candidate' => str_repeat('a', 64), 'side_effect_pointer' => '/side_effect', 'business_audit_ref_pointer' => '/business_audit_ref'] : null,
        'audit_response' => $category === 'audit' ? ['records_pointer' => '/records', 'request_id_pointer' => '/request_id', 'decision_pointer' => '/decision', 'subject_pointer' => '/subject', 'scope_pointer' => '/scope', 'candidate_pointer' => '/candidate', 'acceptance_run_id_pointer' => '/acceptance_run_id', 'business_audit_ref_pointer' => '/business_audit_ref', 'service_audit_ref_pointer' => '/service_audit_ref', 'side_effect_pointer' => '/side_effect'] : null,
    ];
}
$metricNames = ['rss_bytes', 'fd_count', 'queue_depth', 'unrecoverable_backlog', 'unrecoverable_backlog_total', 'security_operation_retention_backlog', 'auth_rate_limit_retention_backlog', 'worker_exit_total', 'worker_restart_total', 'unauthorized_allow_total', 'data_corruption_total', 'event_loop_lag_ms', 'pool_wait_ms'];
$metrics = [];
foreach ($metricNames as $name) $metrics[$name] = '/metrics/' . $name;
$thresholds = [
    'max_error_rate' => 0, 'max_gap_seconds' => 90, 'max_rss_bytes' => 536870912,
    'max_rss_slope_bytes_per_hour' => 1048576, 'max_fd_count' => 1024, 'max_fd_slope_per_hour' => 1,
    'max_queue_depth' => 100, 'max_unrecoverable_backlog' => 0, 'max_unrecoverable_backlog_delta' => 0, 'max_security_operation_retention_backlog' => 0, 'max_auth_rate_limit_retention_backlog' => 0, 'max_worker_exit_delta' => 0,
    'max_worker_restart_delta' => 0, 'max_unauthorized_allow_delta' => 0, 'max_data_corruption_delta' => 0,
    'max_event_loop_lag_p99_ms' => 50, 'max_pool_wait_p99_ms' => 50,
];
$plan = [
    'schema' => 'sand-iam.endurance-plan/v2',
    'candidate' => ['version' => '0.7.0', 'archive_sha256' => str_repeat('a', 64), 'archive_bytes' => 1, 'artifact_manifest_sha256' => str_repeat('b', 64), 'source_revision' => ['commit' => str_repeat('c', 40), 'tree' => str_repeat('e', 40)]],
    'acceptance_run_id' => 'sand_iam_endurance_0123456789abcdef',
    'environment' => ['id' => 'test-environment'], 'collector' => ['id' => 'test-collector', 'version' => '1.0.0'],
    'duration_seconds' => 28800, 'interval_seconds' => 60, 'max_jitter_seconds' => 30, 'max_clock_skew_seconds' => 2, 'runtime_identity' => ['boot_id' => '/runtime/boot_id', 'process_group_id' => '/runtime/process_group_id', 'supervisor_restart_total' => '/runtime/supervisor_restart_total'], 'request_timeout_ms' => 5000,
    'allow_loopback_http' => false, 'approved_hosts' => ['endurance.example.invalid'],
    'targets' => $targets, 'metrics' => $metrics, 'thresholds' => $thresholds,
];
try {
    $fixtureDirectory = sys_get_temp_dir() . '/sand-iam-endurance-evidence-' . bin2hex(random_bytes(6));
    if (!mkdir($fixtureDirectory, 0700)) throw new RuntimeException('cannot create endurance evidence fixture directory');
    $archivePath = $fixtureDirectory . '/sand-iam-0.7.0.zip';
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE) !== true || !$zip->addFromString('release-build-contract.json', 'test-build-contract') || !$zip->addFromString('update.sql', 'test-update-sql') || !$zip->close()) throw new RuntimeException('cannot create endurance ZIP fixture');
    $contractBytes = 'test-build-contract'; $updateBytes = 'test-update-sql';
    $manifest = [
        'schema' => 'sand-iam.artifact-manifest/v8', 'kind' => 'release-candidate-unsigned', 'release_state' => 'release/unsigned',
        'archive_authority_parity' => ['passed' => true, 'normal_package_recovery_descriptors' => 'excluded'],
        'reproducibility' => ['bit_identical_zip' => true, 'entry_list_identical' => true],
        'package' => ['app' => 'sand-iam', 'version' => '0.7.0', 'archive' => basename($archivePath), 'sha256' => hash_file('sha256', $archivePath), 'bytes' => filesize($archivePath), 'entry_count' => 2],
        'source_revision' => ['vcs' => 'git', 'commit' => str_repeat('c', 40), 'tree' => str_repeat('e', 40), 'subtree' => 'sand-iam/', 'clean' => true],
        'source_snapshot' => ['sha256' => str_repeat('f', 64)],
        'files' => ['release-build-contract.json' => ['sha256' => hash('sha256', $contractBytes), 'bytes' => strlen($contractBytes)], 'update.sql' => ['sha256' => hash('sha256', $updateBytes), 'bytes' => strlen($updateBytes)]],
        'source_provenance' => ['mode' => 'git-blob-only', 'commit' => str_repeat('c', 40), 'tree' => str_repeat('e', 40), 'stage_matches_git_blobs' => true, 'independent_git_stage_rebuild' => true, 'build_contract_sha256' => hash('sha256', $contractBytes)],
    ];
    $manifestPath = $fixtureDirectory . '/manifest.json';
    file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $plan['candidate']['archive_sha256'] = hash_file('sha256', $archivePath);
    $plan['candidate']['archive_bytes'] = filesize($archivePath);
    $plan['candidate']['artifact_manifest_sha256'] = hash_file('sha256', $manifestPath);
    $plan['targets'][0]['json_expect'] = ['/archive_sha256' => $plan['candidate']['archive_sha256'], '/artifact_manifest_sha256' => $plan['candidate']['artifact_manifest_sha256']];
    foreach ($plan['targets'] as &$target) if (is_array($target['audit_context'])) $target['audit_context']['candidate'] = $plan['candidate']['archive_sha256'];
    unset($target);
    $targets = $plan['targets'];
    file_put_contents($seed, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    [$validStatus, $validOutput] = $run($seed);
    $v1 = $plan; $v1['schema'] = 'sand-iam.endurance-plan/v1';
    file_put_contents($seed, json_encode($v1, JSON_THROW_ON_ERROR));
    [$v1Status, $v1Output] = $run($seed);
    $short = $plan; $short['duration_seconds'] = 28799;
    file_put_contents($seed, json_encode($short, JSON_THROW_ON_ERROR));
    [$shortStatus, $shortOutput] = $run($seed);
    $missing = $plan; array_pop($missing['targets']);
    file_put_contents($seed, json_encode($missing, JSON_THROW_ON_ERROR));
    [$missingStatus, $missingOutput] = $run($seed);
    $unsafe = $plan; $unsafe['targets'][0]['url'] = 'http://endurance.example.invalid/candidate';
    file_put_contents($seed, json_encode($unsafe, JSON_THROW_ON_ERROR));
    [$unsafeStatus, $unsafeOutput] = $run($seed);
    $secret = $plan; $secret['targets'][0]['body'] = ['Authorization' => 'Bearer secret-value-123']; $secret['targets'][0]['method'] = 'POST';
    file_put_contents($seed, json_encode($secret, JSON_THROW_ON_ERROR));
    [$secretStatus, $secretOutput] = $run($seed);
    $retentionBacklog = $plan; $retentionBacklog['thresholds']['max_security_operation_retention_backlog'] = 1;
    file_put_contents($seed, json_encode($retentionBacklog, JSON_THROW_ON_ERROR));
    [$retentionStatus, $retentionOutput] = $run($seed);
    $rateLimitBacklog = $plan; $rateLimitBacklog['thresholds']['max_auth_rate_limit_retention_backlog'] = 1;
    file_put_contents($seed, json_encode($rateLimitBacklog, JSON_THROW_ON_ERROR));
    [$rateLimitStatus, $rateLimitOutput] = $run($seed);
    $wrongGap = $plan; $wrongGap['thresholds']['max_gap_seconds'] = 91;
    file_put_contents($seed, json_encode($wrongGap, JSON_THROW_ON_ERROR));
    [$wrongGapStatus, $wrongGapOutput] = $run($seed);
    $oversizeJitter = $plan; $oversizeJitter['max_jitter_seconds'] = 61;
    file_put_contents($seed, json_encode($oversizeJitter, JSON_THROW_ON_ERROR));
    [$oversizeJitterStatus, $oversizeJitterOutput] = $run($seed);
    $missingRuntimeIdentity = $plan; unset($missingRuntimeIdentity['runtime_identity']['boot_id']);
    file_put_contents($seed, json_encode($missingRuntimeIdentity, JSON_THROW_ON_ERROR));
    [$missingRuntimeIdentityStatus, $missingRuntimeIdentityOutput] = $run($seed);
    $maximumRange = $plan;
    $maximumRange['duration_seconds'] = intdiv(PHP_INT_MAX, 1000000);
    $maximumRange['interval_seconds'] = 300; $maximumRange['max_jitter_seconds'] = 60; $maximumRange['thresholds']['max_gap_seconds'] = 360;
    if (!chmod($seed, 0600)) throw new RuntimeException('cannot reopen plan fixture'); file_put_contents($seed, json_encode($maximumRange, JSON_THROW_ON_ERROR));
    [$maximumRangeStatus, $maximumRangeOutput] = $run($seed);
    $overRange = $maximumRange; ++$overRange['duration_seconds'];
    if (!chmod($seed, 0600)) throw new RuntimeException('cannot reopen plan fixture'); file_put_contents($seed, json_encode($overRange, JSON_THROW_ON_ERROR));
    [$overRangeStatus, $overRangeOutput] = $run($seed);
    if (!($validStatus === 0 && str_contains($validOutput, 'duration_seconds=28800')
        && $v1Status !== 0 && str_contains($v1Output, 'v1 evidence cannot pass release verification')
        && $shortStatus !== 0 && str_contains($shortOutput, 'at least 28800 seconds')
        && $missingStatus !== 0 && str_contains($missingOutput, 'required category exactly once')
        && $unsafeStatus !== 0 && str_contains($unsafeOutput, 'must use HTTPS')
        && $secretStatus !== 0 && str_contains($secretOutput, 'must not contain credentials')
        && $retentionStatus !== 0 && str_contains($retentionOutput, 'retention backlog thresholds must be zero')
        && $rateLimitStatus !== 0 && str_contains($rateLimitOutput, 'retention backlog thresholds must be zero')
        && $wrongGapStatus !== 0 && str_contains($wrongGapOutput, 'must equal interval_seconds plus max_jitter_seconds')
        && $oversizeJitterStatus !== 0 && str_contains($oversizeJitterOutput, 'between 0 and 60')
        && $missingRuntimeIdentityStatus !== 0 && str_contains($missingRuntimeIdentityOutput, 'runtime_identity is missing keys')
        && $maximumRangeStatus === 0 && str_contains($maximumRangeOutput, 'duration_seconds=' . $maximumRange['duration_seconds'])
        && $overRangeStatus !== 0 && str_contains($overRangeOutput, 'exceeds exact microsecond range'))) {
        throw new RuntimeException('endurance plan gate did not fail closed: ' . json_encode([
            'valid' => [$validStatus, $validOutput], 'v1' => [$v1Status, $v1Output], 'short' => [$shortStatus, $shortOutput],
            'missing' => [$missingStatus, $missingOutput], 'unsafe' => [$unsafeStatus, $unsafeOutput],
            'secret' => [$secretStatus, $secretOutput],
            'retention_backlog' => [$retentionStatus, $retentionOutput],
            'auth_rate_limit_retention_backlog' => [$rateLimitStatus, $rateLimitOutput],
            'wrong_gap' => [$wrongGapStatus, $wrongGapOutput], 'oversize_jitter' => [$oversizeJitterStatus, $oversizeJitterOutput],
            'missing_runtime_identity' => [$missingRuntimeIdentityStatus, $missingRuntimeIdentityOutput],
            'maximum_range' => [$maximumRangeStatus, $maximumRangeOutput], 'over_range' => [$overRangeStatus, $overRangeOutput],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    if (!chmod($seed, 0600)) throw new RuntimeException('cannot reopen plan fixture'); file_put_contents($seed, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $evidencePath = $seed . '.jsonl';
    $summaryPath = $evidencePath . '.summary.json';
    $identity = ['plan_sha256' => hash_file('sha256', $seed), 'acceptance_run_id' => $plan['acceptance_run_id'], 'candidate' => $plan['candidate'], 'environment' => $plan['environment'], 'collector' => $plan['collector'], 'thresholds_sha256' => hash('sha256', $canonicalJson($plan['thresholds'])), 'endpoints_sha256' => hash('sha256', $canonicalJson($plan['targets'])), 'runtime_identity_sha256' => hash('sha256', $canonicalJson($plan['runtime_identity']))];
    $valueKind = static function (mixed $value): string {
        return $value === null ? 'null' : (is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : (is_float($value) ? 'float' : (is_string($value) ? 'string' : (array_is_list($value) ? 'array' : 'object')))));
    };
    $previousHash = str_repeat('0', 64);
    $lines = ''; $records = [];
    $requestSequence = 0;
    for ($iteration = 0; $iteration <= 322; ++$iteration) {
        $elapsed = $iteration === 0 ? 1000000 : ($iteration === 322 ? 28801500001 : (1500000 + (($iteration - 1) * 90000000)));
        $probes = [];
        foreach ($targets as $target) {
            $assertions = [];
            foreach ($target['json_expect'] as $pointer => $expected) {
                $safe = $expected === null || is_bool($expected) || is_int($expected) || is_float($expected);
                $sensitive = preg_match('/(?:password|client_secret|secret|token|otp|code)/i', $pointer) === 1;
                $assertions[$pointer] = ['pointer' => $pointer, 'expected_kind' => $valueKind($expected), 'expected_sha256' => hash('sha256', $canonicalJson($expected)), 'actual_kind' => $valueKind($expected), 'actual' => $safe && !$sensitive ? $expected : null, 'actual_sha256' => hash('sha256', $canonicalJson($expected)), 'result' => 'matched'];
            }
            $probes[$target['id']] = [
                'category' => $target['category'],
                'request_id' => 'sand-iam-endurance-' . sprintf('%024x', ++$requestSequence),
                'status' => $target['expected_status'], 'elapsed_ms' => 10.0, 'assertions' => $assertions,
                'response_sha256' => str_repeat('d', 64), 'passed' => true, 'transport_error' => null,
            ];
            if (is_array($target['audit_context'])) $probes[$target['id']]['business_observation'] = ['decision' => $target['audit_context']['decision'], 'subject' => $target['audit_context']['subject'], 'scope' => $target['audit_context']['scope'], 'candidate' => $target['audit_context']['candidate'], 'side_effect' => $target['category'] === 'allow' ? 'applied' : ($target['category'] === 'deny' ? 'rejected' : 'revoked'), 'business_audit_ref' => 'biz-' . $iteration . '-' . $target['category']];
        }
        $auditRequests = []; $auditRecords = [];
        foreach (['allow-probe', 'deny-probe', 'revoked-probe'] as $id) { $request = ['request_id' => $probes[$id]['request_id'], ...$probes[$id]['business_observation'], 'acceptance_run_id' => $plan['acceptance_run_id']]; $auditRequests[] = $request; $auditRecords[] = [...$request, 'service_audit_ref' => 'service-' . $iteration . '-' . $id]; }
        $probes['audit-probe']['audit_observation'] = ['requests' => $auditRequests, 'records' => $auditRecords];
        $metricValues = [];
        foreach ($metricNames as $name) $metricValues[$name] = match ($name) {
            'rss_bytes' => 134217728, 'fd_count' => 100, 'event_loop_lag_ms', 'pool_wait_ms' => 1, 'worker_restart_total' => '9007199254740992', default => 0,
        };
        $record = [
            'schema' => 'sand-iam.endurance-sample/v2', 'identity' => $identity, 'iteration' => $iteration,
            'run_started_at' => '2026-09-12T00:00:00Z', 'runtime_identity' => ['boot_id' => 'boot-test-001', 'process_group_id' => 'group-test-001', 'supervisor_restart_total' => '9007199254740992'],
            'wall_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-09-12T00:00:00Z') + intdiv($elapsed, 1000000)),
            'elapsed_microseconds' => $elapsed, 'previous_sha256' => $previousHash,
            'probes' => $probes, 'metrics' => $metricValues,
        ];
        $recordHash = hash('sha256', $canonicalJson($record));
        $record['record_sha256'] = $recordHash;
        $records[] = $record;
        $lines .= $canonicalJson($record) . "\n";
        $previousHash = $recordHash;
    }
    file_put_contents($evidencePath, $lines);
    $summary = [
        'schema' => 'sand-iam.endurance-report/v2', 'passed' => true, 'identity' => $identity, 'candidate' => $plan['candidate'],
        'acceptance_run_id' => $plan['acceptance_run_id'], 'started_at' => '2026-09-12T00:00:00Z', 'runtime_identity' => ['boot_id' => 'boot-test-001', 'process_group_id' => 'group-test-001', 'supervisor_restart_total' => '9007199254740992'], 'duration_seconds' => 28801.500001, 'duration_microseconds' => 28801500001, 'samples' => 323,
        'checks' => ['passed' => 2261, 'total' => 2261, 'failed' => 0, 'error_rate' => 0],
        'evidence' => ['jsonl' => basename($evidencePath), 'sha256' => hash('sha256', $lines), 'final_record_sha256' => $previousHash],
    ];
    file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    [$verifiedStatus, $verifiedOutput] = $verify($seed, $evidencePath, $summaryPath, $archivePath, $manifestPath);
    file_put_contents($seed, json_encode($maximumRange, JSON_THROW_ON_ERROR));
    [$maximumRangeVerifierStatus, $maximumRangeVerifierOutput] = $verify($seed, $evidencePath, $summaryPath, $archivePath, $manifestPath);
    file_put_contents($seed, json_encode($overRange, JSON_THROW_ON_ERROR));
    [$overRangeVerifierStatus, $overRangeVerifierOutput] = $verify($seed, $evidencePath, $summaryPath, $archivePath, $manifestPath);
    file_put_contents($seed, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $rebuildEvidence = static function (array $samples) use ($canonicalJson): string {
        $previous = str_repeat('0', 64); $output = '';
        foreach ($samples as $sample) {
            unset($sample['record_sha256']); $sample['previous_sha256'] = $previous;
            $sample['record_sha256'] = hash('sha256', $canonicalJson($sample));
            $previous = $sample['record_sha256']; $output .= $canonicalJson($sample) . "\n";
        }
        return $output;
    };
    $negativeResults = [];
    $reject = static function (callable $mutate) use (&$records, $rebuildEvidence, $evidencePath, $verify, $seed, $summaryPath, $archivePath, $manifestPath, $summary): array {
        if (!chmod($seed, 0600) || !chmod($evidencePath, 0600) || !chmod($summaryPath, 0600)) throw new RuntimeException('cannot reopen immutable negative fixture');
        $copy = $records; $mutate($copy); $rebuilt = $rebuildEvidence($copy); file_put_contents($evidencePath, $rebuilt);
        $changedSummary = $summary; $lines = explode("\n", trim($rebuilt)); $last = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        $changedSummary['evidence']['sha256'] = hash('sha256', $rebuilt); $changedSummary['evidence']['final_record_sha256'] = $last['record_sha256'];
        file_put_contents($summaryPath, json_encode($changedSummary, JSON_THROW_ON_ERROR));
        return $verify($seed, $evidencePath, $summaryPath, $archivePath, $manifestPath);
    };
    $negativeResults['null'] = $reject(static function (array &$samples): void { $samples[0]['probes']['health-probe']['assertions']['/sample_null']['actual_kind'] = 'integer'; });
    $negativeResults['true'] = $reject(static function (array &$samples): void { $samples[0]['probes']['allow-probe']['assertions']['/allowed']['actual'] = false; });
    $negativeResults['number'] = $reject(static function (array &$samples): void { $samples[0]['probes']['health-probe']['assertions']['/sample_number']['actual'] = 8; });
    $negativeResults['string'] = $reject(static function (array &$samples): void { $samples[0]['probes']['candidate-probe']['assertions']['/archive_sha256']['actual_sha256'] = str_repeat('0', 64); });
    $negativeResults['missing_pointer'] = $reject(static function (array &$samples): void { $samples[0]['probes']['health-probe']['assertions']['/ok']['result'] = 'pointer_missing'; });
    $negativeResults['password_exposed'] = $reject(static function (array &$samples): void { $samples[0]['probes']['health-probe']['assertions']['/password']['actual'] = 'not-a-real-password'; });
    $negativeResults['client_secret_exposed'] = $reject(static function (array &$samples): void { $samples[0]['probes']['health-probe']['assertions']['/client_secret']['actual'] = 'not-a-real-client-secret'; });
    $negativeResults['otp_exposed'] = $reject(static function (array &$samples): void { $samples[0]['probes']['health-probe']['assertions']['/otp']['actual'] = 987321; });
    $negativeResults['missing_expected_hash'] = $reject(static function (array &$samples): void { unset($samples[0]['probes']['health-probe']['assertions']['/ok']['expected_sha256']); });
    $negativeResults['missing_actual'] = $reject(static function (array &$samples): void { unset($samples[0]['probes']['health-probe']['assertions']['/ok']['actual']); });
    $negativeResults['missing_actual_hash'] = $reject(static function (array &$samples): void { unset($samples[0]['probes']['health-probe']['assertions']['/ok']['actual_sha256']); });
    $negativeResults['unknown_assertion_field'] = $reject(static function (array &$samples): void { $samples[0]['probes']['health-probe']['assertions']['/ok']['unexpected'] = true; });
    $negativeResults['wrong_expected_hash_type'] = $reject(static function (array &$samples): void { $samples[0]['probes']['health-probe']['assertions']['/ok']['expected_sha256'] = true; });
    $negativeResults['wrong_candidate'] = $reject(static function (array &$samples): void { $samples[0]['identity']['candidate']['version'] = '9.9.9'; });
    $negativeResults['jsonl_run'] = $reject(static function (array &$samples): void { $samples[0]['identity']['acceptance_run_id'] = 'sand_iam_endurance_ffffffffffffffff'; });
    $negativeResults['jsonl_plan_hash'] = $reject(static function (array &$samples): void { $samples[0]['identity']['plan_sha256'] = str_repeat('0', 64); });
    $negativeResults['jsonl_threshold'] = $reject(static function (array &$samples): void { $samples[0]['identity']['thresholds_sha256'] = str_repeat('0', 64); });
    $negativeResults['jsonl_endpoint'] = $reject(static function (array &$samples): void { $samples[0]['identity']['endpoints_sha256'] = str_repeat('0', 64); });
    $negativeResults['counter_2pow53_increment'] = $reject(static function (array &$samples): void { $samples[1]['metrics']['worker_restart_total'] = '9007199254740993'; });
    $negativeResults['counter_0_1_0'] = $reject(static function (array &$samples): void { $samples[0]['metrics']['worker_exit_total'] = 0; $samples[1]['metrics']['worker_exit_total'] = 1; $samples[2]['metrics']['worker_exit_total'] = 0; });
    $negativeResults['counter_float'] = $reject(static function (array &$samples): void { $samples[1]['metrics']['worker_exit_total'] = 0.0; });
    $negativeResults['counter_decrease'] = $reject(static function (array &$samples): void { $samples[0]['metrics']['data_corruption_total'] = 2; $samples[1]['metrics']['data_corruption_total'] = 1; });
    $negativeResults['negative_metric'] = $reject(static function (array &$samples): void { $samples[1]['metrics']['queue_depth'] = -1; });
    $negativeResults['boot_change'] = $reject(static function (array &$samples): void { $samples[1]['runtime_identity']['boot_id'] = 'boot-test-002'; });
    $negativeResults['process_change'] = $reject(static function (array &$samples): void { $samples[1]['runtime_identity']['process_group_id'] = 'group-test-002'; });
    $negativeResults['supervisor_restart'] = $reject(static function (array &$samples): void { $samples[1]['runtime_identity']['supervisor_restart_total'] = 1; });
    $negativeResults['two_samples'] = $reject(static function (array &$samples): void { $samples = array_slice($samples, 0, 2); });
    $negativeResults['zero_elapsed'] = $reject(static function (array &$samples): void { $samples[1]['elapsed_microseconds'] = 0; });
    $negativeResults['oversize_gap'] = $reject(static function (array &$samples): void { $samples[1]['elapsed_microseconds'] = 91000001; });
    $negativeResults['first_gap'] = $reject(static function (array &$samples): void { $samples[0]['elapsed_microseconds'] = 90000001; });
    $negativeResults['tail_gap'] = $reject(static function (array &$samples): void { $samples[count($samples) - 1]['elapsed_microseconds'] = 28891000000; });
    $negativeResults['wall_back'] = $reject(static function (array &$samples): void { $samples[2]['wall_time'] = $samples[1]['wall_time']; });
    $negativeResults['wall_forward'] = $reject(static function (array &$samples): void { $samples[2]['wall_time'] = '2026-09-12T00:10:00Z'; });
    $negativeResults['wall_slow_9s'] = $reject(static function (array &$samples): void { $samples[2]['wall_time'] = '2026-09-12T00:01:23Z'; });
    $negativeResults['wall_invalid_date'] = $reject(static function (array &$samples): void { $samples[2]['wall_time'] = '2026-02-30T00:00:00Z'; });
    $negativeResults['audit_wrong_request'] = $reject(static function (array &$samples): void { $samples[1]['probes']['audit-probe']['audit_observation']['records'][0]['request_id'] = 'sand-iam-endurance-ffffffffffffffffffffffff'; });
    $negativeResults['audit_duplicate'] = $reject(static function (array &$samples): void { $samples[1]['probes']['audit-probe']['audit_observation']['records'][1] = $samples[1]['probes']['audit-probe']['audit_observation']['records'][0]; });
    $negativeResults['audit_cached_run'] = $reject(static function (array &$samples): void { $samples[1]['probes']['audit-probe']['audit_observation']['records'][0]['acceptance_run_id'] = 'sand_iam_endurance_ffffffffffffffff'; });
    $negativeResults['audit_wrong_decision'] = $reject(static function (array &$samples): void { $samples[1]['probes']['audit-probe']['audit_observation']['records'][0]['decision'] = 'deny'; });
    $negativeResults['audit_missing_side_effect'] = $reject(static function (array &$samples): void { unset($samples[1]['probes']['allow-probe']['business_observation']['side_effect']); });
    foreach (['NaN', 'INF'] as $special) {
        if (!chmod($evidencePath, 0600) || !chmod($summaryPath, 0600)) throw new RuntimeException('cannot reopen non-finite fixture');
        $raw = str_replace('"queue_depth":0', '"queue_depth":' . $special, $lines, $replacements);
        if ($replacements < 1) throw new RuntimeException('cannot create non-finite metric fixture');
        file_put_contents($evidencePath, $raw); file_put_contents($summaryPath, json_encode($summary, JSON_THROW_ON_ERROR));
        $negativeResults['nonfinite_' . $special] = $verify($seed, $evidencePath, $summaryPath, $archivePath, $manifestPath);
    }
    if (!chmod($evidencePath, 0600) || !chmod($summaryPath, 0600)) throw new RuntimeException('cannot reopen evidence fixture');
    file_put_contents($evidencePath, $lines);
    file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($evidencePath, substr($lines, 0, -1));
    $negativeResults['tail_truncation'] = $verify($seed, $evidencePath, $summaryPath, $archivePath, $manifestPath);
    if (!chmod($evidencePath, 0600)) throw new RuntimeException('cannot reopen tail fixture');
    file_put_contents($evidencePath, $lines);
    $summaryMoved = $summaryPath . '.moved'; rename($summaryPath, $summaryMoved);
    if (!symlink($summaryMoved, $summaryPath)) throw new RuntimeException('cannot create summary symlink negative fixture');
    $negativeResults['summary_symlink'] = $verify($seed, $evidencePath, $summaryPath, $archivePath, $manifestPath);
    unlink($summaryPath); rename($summaryMoved, $summaryPath);
    $keyOrder = $records;
    $keyOrder[1]['runtime_identity'] = ['supervisor_restart_total' => '9007199254740992', 'process_group_id' => 'group-test-001', 'boot_id' => 'boot-test-001'];
    if (!chmod($evidencePath, 0600) || !chmod($summaryPath, 0600)) throw new RuntimeException('cannot reopen ordered fixture');
    $orderedEvidence = $rebuildEvidence($keyOrder); file_put_contents($evidencePath, $orderedEvidence);
    $orderedSummary = $summary; $orderedLines = explode("\n", trim($orderedEvidence)); $orderedLast = json_decode((string) end($orderedLines), true, 512, JSON_THROW_ON_ERROR);
    $orderedSummary['evidence']['sha256'] = hash('sha256', $orderedEvidence); $orderedSummary['evidence']['final_record_sha256'] = $orderedLast['record_sha256'];
    file_put_contents($summaryPath, json_encode($orderedSummary, JSON_THROW_ON_ERROR));
    $keyOrderResult = $verify($seed, $evidencePath, $summaryPath, $archivePath, $manifestPath);
    if (!chmod($evidencePath, 0600) || !chmod($summaryPath, 0600)) throw new RuntimeException('cannot reopen summary fixture');
    file_put_contents($evidencePath, $lines); file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    foreach (['not-a-real-password', 'not-a-real-client-secret', '987321'] as $forbidden) {
        if (str_contains($lines, $forbidden) || str_contains($verifiedOutput, $forbidden) || str_contains(implode("\n", array_map(static fn (array $result): string => $result[1], $negativeResults)), $forbidden)) throw new RuntimeException('endurance evidence or output exposed a fixture secret');
    }
    $summaryMutations = [
        'candidate' => static function (array &$value): void { $value['identity']['candidate']['version'] = '9.9.9'; },
        'run' => static function (array &$value): void { $value['identity']['acceptance_run_id'] = 'sand_iam_endurance_ffffffffffffffff'; },
        'plan_hash' => static function (array &$value): void { $value['identity']['plan_sha256'] = str_repeat('0', 64); },
        'threshold' => static function (array &$value): void { $value['identity']['thresholds_sha256'] = str_repeat('0', 64); },
        'endpoint' => static function (array &$value): void { $value['identity']['endpoints_sha256'] = str_repeat('0', 64); },
    ];
    foreach ($summaryMutations as $label => $mutate) {
        if (!chmod($summaryPath, 0600)) throw new RuntimeException('cannot reopen summary mutation fixture');
        $changed = $summary; $mutate($changed); file_put_contents($summaryPath, json_encode($changed, JSON_THROW_ON_ERROR));
        $negativeResults['summary_' . $label] = $verify($seed, $evidencePath, $summaryPath, $archivePath, $manifestPath);
    }
    if (!chmod($evidencePath, 0600) || !chmod($summaryPath, 0600)) throw new RuntimeException('cannot reopen final fixture');
    file_put_contents($evidencePath, $lines);
    file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $changedPlan = $plan; $changedPlan['thresholds']['max_queue_depth'] = 99;
    if (!chmod($seed, 0600)) throw new RuntimeException('cannot reopen final plan fixture'); file_put_contents($seed, json_encode($changedPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $negativeResults['plan_threshold'] = $verify($seed, $evidencePath, $summaryPath, $archivePath, $manifestPath);
    if (!chmod($seed, 0600)) throw new RuntimeException('cannot restore final plan fixture'); file_put_contents($seed, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $negativePassed = array_reduce($negativeResults, static fn (bool $carry, array $result): bool => $carry && $result[0] !== 0, true);
    if (!($verifiedStatus === 0 && str_contains($verifiedOutput, 'samples=323') && $maximumRangeVerifierStatus !== 0 && !str_contains($maximumRangeVerifierOutput, 'endurance plan validation failed') && $overRangeVerifierStatus !== 0 && str_contains($overRangeVerifierOutput, 'endurance plan validation failed') && $keyOrderResult[0] === 0 && $negativePassed)) {
        throw new RuntimeException('endurance verifier did not independently accept and reject evidence: ' . json_encode([
            'verified' => [$verifiedStatus, $verifiedOutput], 'maximum_range_verifier' => [$maximumRangeVerifierStatus, $maximumRangeVerifierOutput], 'over_range_verifier' => [$overRangeVerifierStatus, $overRangeVerifierOutput], 'key_order' => $keyOrderResult, 'negative' => $negativeResults,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
} finally {
    unlink($seed);
    foreach ([$seed . '.jsonl', $seed . '.jsonl.summary.json'] as $fixturePath) if (is_file($fixturePath)) unlink($fixturePath);
    if (isset($fixtureDirectory) && is_dir($fixtureDirectory)) {
        foreach (glob($fixtureDirectory . '/*') ?: [] as $fixturePath) unlink($fixturePath);
        rmdir($fixtureDirectory);
    }
}

echo "SandIAM endurance acceptance plan checks passed\n";
