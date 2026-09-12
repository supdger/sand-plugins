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
$verify = static function (string $planPath, string $evidencePath, string $summaryPath) use ($verifier): array {
    $output = [];
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($verifier)
        . ' --plan=' . escapeshellarg($planPath) . ' --evidence=' . escapeshellarg($evidencePath)
        . ' --summary=' . escapeshellarg($summaryPath);
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
$canonicalJson = static fn (array $value): string => json_encode($canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

$categories = ['candidate', 'health', 'allow', 'deny', 'revoked', 'audit', 'metrics'];
$targets = [];
foreach ($categories as $category) {
    $jsonExpect = match ($category) {
        'candidate' => ['/archive_sha256' => str_repeat('a', 64), '/artifact_manifest_sha256' => str_repeat('b', 64)],
        'allow' => ['/allowed' => true],
        'deny', 'revoked' => ['/allowed' => false],
        'audit' => ['/acceptance_run_id' => 'sand_iam_endurance_0123456789abcdef'],
        default => ['/ok' => true],
    };
    $targets[] = [
        'id' => $category . '-probe', 'category' => $category,
        'url' => 'https://endurance.example.invalid/' . $category, 'method' => 'GET', 'effect' => 'read_only',
        'authorization_env' => null, 'body' => null, 'expected_status' => 200,
        'json_expect' => $jsonExpect, 'max_p99_ms' => 500,
    ];
}
$metricNames = ['rss_bytes', 'fd_count', 'queue_depth', 'unrecoverable_backlog', 'security_operation_retention_backlog', 'auth_rate_limit_retention_backlog', 'worker_exit_total', 'worker_restart_total', 'unauthorized_allow_total', 'data_corruption_total', 'event_loop_lag_ms', 'pool_wait_ms'];
$metrics = [];
foreach ($metricNames as $name) $metrics[$name] = '/metrics/' . $name;
$thresholds = [
    'max_error_rate' => 0, 'max_gap_seconds' => 90, 'max_rss_bytes' => 536870912,
    'max_rss_slope_bytes_per_hour' => 1048576, 'max_fd_count' => 1024, 'max_fd_slope_per_hour' => 1,
    'max_queue_depth' => 100, 'max_unrecoverable_backlog' => 0, 'max_security_operation_retention_backlog' => 0, 'max_auth_rate_limit_retention_backlog' => 0, 'max_worker_exit_delta' => 0,
    'max_worker_restart_delta' => 0, 'max_unauthorized_allow_delta' => 0, 'max_data_corruption_delta' => 0,
    'max_event_loop_lag_p99_ms' => 50, 'max_pool_wait_p99_ms' => 50,
];
$plan = [
    'schema' => 'sand-iam.endurance-plan/v1',
    'candidate' => ['version' => '0.7.0', 'archive_sha256' => str_repeat('a', 64), 'artifact_manifest_sha256' => str_repeat('b', 64), 'source_revision' => str_repeat('c', 40)],
    'acceptance_run_id' => 'sand_iam_endurance_0123456789abcdef',
    'duration_seconds' => 86400, 'interval_seconds' => 60, 'request_timeout_ms' => 5000,
    'allow_loopback_http' => false, 'approved_hosts' => ['endurance.example.invalid'],
    'targets' => $targets, 'metrics' => $metrics, 'thresholds' => $thresholds,
];
try {
    file_put_contents($seed, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    [$validStatus, $validOutput] = $run($seed);
    $short = $plan; $short['duration_seconds'] = 86399;
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
    if (!($validStatus === 0 && str_contains($validOutput, 'duration_seconds=86400')
        && $shortStatus !== 0 && str_contains($shortOutput, 'at least 86400 seconds')
        && $missingStatus !== 0 && str_contains($missingOutput, 'required category exactly once')
        && $unsafeStatus !== 0 && str_contains($unsafeOutput, 'must use HTTPS')
        && $secretStatus !== 0 && str_contains($secretOutput, 'must not contain credentials')
        && $retentionStatus !== 0 && str_contains($retentionOutput, 'retention backlog thresholds must be zero')
        && $rateLimitStatus !== 0 && str_contains($rateLimitOutput, 'retention backlog thresholds must be zero'))) {
        throw new RuntimeException('endurance plan gate did not fail closed: ' . json_encode([
            'valid' => [$validStatus, $validOutput], 'short' => [$shortStatus, $shortOutput],
            'missing' => [$missingStatus, $missingOutput], 'unsafe' => [$unsafeStatus, $unsafeOutput],
            'secret' => [$secretStatus, $secretOutput],
            'retention_backlog' => [$retentionStatus, $retentionOutput],
            'auth_rate_limit_retention_backlog' => [$rateLimitStatus, $rateLimitOutput],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    file_put_contents($seed, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $evidencePath = $seed . '.jsonl';
    $summaryPath = $evidencePath . '.summary.json';
    $previousHash = str_repeat('0', 64);
    $lines = '';
    $requestSequence = 0;
    for ($iteration = 0; $iteration <= 960; ++$iteration) {
        $elapsed = $iteration * 90;
        $probes = [];
        foreach ($targets as $target) {
            $probes[$target['id']] = [
                'category' => $target['category'],
                'request_id' => 'sand-iam-endurance-' . sprintf('%024x', ++$requestSequence),
                'status' => $target['expected_status'], 'elapsed_ms' => 10.0,
                'response_sha256' => str_repeat('d', 64), 'passed' => true, 'transport_error' => null,
            ];
        }
        $metricValues = [];
        foreach ($metricNames as $name) $metricValues[$name] = match ($name) {
            'rss_bytes' => 134217728, 'fd_count' => 100, 'event_loop_lag_ms', 'pool_wait_ms' => 1, default => 0,
        };
        $record = [
            'schema' => 'sand-iam.endurance-sample/v1', 'iteration' => $iteration,
            'wall_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-09-12T00:00:00Z') + $elapsed),
            'elapsed_seconds' => (float) $elapsed, 'previous_sha256' => $previousHash,
            'probes' => $probes, 'metrics' => $metricValues,
        ];
        $recordHash = hash('sha256', $canonicalJson($record));
        $record['record_sha256'] = $recordHash;
        $lines .= $canonicalJson($record) . "\n";
        $previousHash = $recordHash;
    }
    file_put_contents($evidencePath, $lines);
    $summary = [
        'schema' => 'sand-iam.endurance-report/v1', 'passed' => true, 'candidate' => $plan['candidate'],
        'acceptance_run_id' => $plan['acceptance_run_id'], 'duration_seconds' => 86400.0, 'samples' => 961,
        'checks' => ['passed' => 6727, 'total' => 6727, 'failed' => 0, 'error_rate' => 0],
        'evidence' => ['jsonl' => basename($evidencePath), 'sha256' => hash('sha256', $lines), 'final_record_sha256' => $previousHash],
    ];
    file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    [$verifiedStatus, $verifiedOutput] = $verify($seed, $evidencePath, $summaryPath);
    $tampered = str_replace('"elapsed_ms":10.0', '"elapsed_ms":11.0', $lines, $replacements);
    if ($replacements < 1) throw new RuntimeException('cannot create tampered endurance fixture');
    file_put_contents($evidencePath, $tampered);
    [$tamperedStatus, $tamperedOutput] = $verify($seed, $evidencePath, $summaryPath);
    if (!($verifiedStatus === 0 && str_contains($verifiedOutput, 'samples=961')
        && $tamperedStatus !== 0 && (str_contains($tamperedOutput, 'hash chain failed') || str_contains($tamperedOutput, 'does not bind')))) {
        throw new RuntimeException('endurance verifier did not independently accept and reject evidence: ' . json_encode([
            'verified' => [$verifiedStatus, $verifiedOutput], 'tampered' => [$tamperedStatus, $tamperedOutput],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
} finally {
    unlink($seed);
    foreach ([$seed . '.jsonl', $seed . '.jsonl.summary.json'] as $fixturePath) if (is_file($fixturePath)) unlink($fixturePath);
}

echo "SandIAM endurance acceptance plan checks passed\n";
