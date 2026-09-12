<?php

declare(strict_types=1);

/**
 * Run the final-candidate endurance probe without starting or changing services.
 * Secrets are referenced by environment-variable name and never written to evidence.
 */

$options = getopt('', ['plan:', 'output::', 'validate-only']);
$planPath = $options['plan'] ?? null;
$validateOnly = array_key_exists('validate-only', $options);
if (!is_string($planPath) || trim($planPath) === '') throw new InvalidArgumentException('--plan is required');
$planPath = realpath($planPath);
if (!is_string($planPath) || !is_file($planPath) || is_link($planPath)) throw new RuntimeException('endurance plan must be an existing regular file');
$planBytes = (string) file_get_contents($planPath);
if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|\bsiam_(?:at|wc|rt)_[A-Za-z0-9_-]{8,}\b|"Authorization"\s*:|\bBearer\s+[A-Za-z0-9._~-]{8,}/i', $planBytes) === 1) {
    throw new RuntimeException('endurance plan must not contain credentials or private keys');
}
$plan = json_decode($planBytes, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($plan) || ($plan['schema'] ?? null) !== 'sand-iam.endurance-plan/v1') throw new RuntimeException('invalid endurance plan schema');

/** @param list<string> $required @param list<string> $allowed */
$expectKeys = static function (array $value, array $required, array $allowed, string $label): void {
    $missing = array_values(array_diff($required, array_keys($value)));
    $unknown = array_values(array_diff(array_keys($value), $allowed));
    if ($missing !== []) throw new RuntimeException($label . ' is missing keys: ' . implode(', ', $missing));
    if ($unknown !== []) throw new RuntimeException($label . ' has unknown keys: ' . implode(', ', $unknown));
};

$expectKeys($plan, ['schema', 'candidate', 'acceptance_run_id', 'duration_seconds', 'interval_seconds', 'request_timeout_ms', 'allow_loopback_http', 'approved_hosts', 'targets', 'metrics', 'thresholds'], ['schema', 'candidate', 'acceptance_run_id', 'duration_seconds', 'interval_seconds', 'request_timeout_ms', 'allow_loopback_http', 'approved_hosts', 'targets', 'metrics', 'thresholds'], 'plan');
$candidate = $plan['candidate'];
if (!is_array($candidate)) throw new RuntimeException('candidate must be an object');
$expectKeys($candidate, ['version', 'archive_sha256', 'artifact_manifest_sha256', 'source_revision'], ['version', 'archive_sha256', 'artifact_manifest_sha256', 'source_revision'], 'candidate');
if (preg_match('/^\d+\.\d+\.\d+$/', (string) $candidate['version']) !== 1
    || preg_match('/^[0-9a-f]{64}$/', (string) $candidate['archive_sha256']) !== 1
    || preg_match('/^[0-9a-f]{64}$/', (string) $candidate['artifact_manifest_sha256']) !== 1
    || preg_match('/^[0-9a-f]{40,64}$/', (string) $candidate['source_revision']) !== 1) {
    throw new RuntimeException('candidate identity is incomplete');
}
if (!is_string($plan['acceptance_run_id']) || preg_match('/^sand_iam_endurance_[a-f0-9]{16}$/', $plan['acceptance_run_id']) !== 1) throw new RuntimeException('acceptance_run_id is invalid');
if (!is_int($plan['duration_seconds']) || $plan['duration_seconds'] < 86400) throw new RuntimeException('endurance duration must be at least 86400 seconds');
if (!is_int($plan['interval_seconds']) || $plan['interval_seconds'] < 10 || $plan['interval_seconds'] > 300) throw new RuntimeException('interval_seconds must be between 10 and 300');
if (!is_int($plan['request_timeout_ms']) || $plan['request_timeout_ms'] < 100 || $plan['request_timeout_ms'] >= $plan['interval_seconds'] * 1000) throw new RuntimeException('request timeout must fit inside the sample interval');
if (!is_bool($plan['allow_loopback_http'])) throw new RuntimeException('allow_loopback_http must be boolean');
if (!is_array($plan['approved_hosts']) || $plan['approved_hosts'] === [] || !array_is_list($plan['approved_hosts'])) throw new RuntimeException('approved_hosts must be a non-empty list');
$approvedHosts = [];
foreach ($plan['approved_hosts'] as $approvedHost) {
    if (!is_string($approvedHost) || preg_match('/^[a-z0-9.-]+$/', $approvedHost) !== 1) throw new RuntimeException('approved host is invalid');
    $approvedHosts[strtolower($approvedHost)] = true;
}
if (!is_array($plan['targets']) || $plan['targets'] === []) throw new RuntimeException('targets must be a non-empty array');

$requiredCategories = ['candidate', 'health', 'allow', 'deny', 'revoked', 'audit', 'metrics'];
$seenCategories = [];
$seenTargetIds = [];
$writeEffects = false;
foreach ($plan['targets'] as $index => $target) {
    if (!is_array($target)) throw new RuntimeException('target must be an object at index ' . $index);
    $keys = ['id', 'category', 'url', 'method', 'effect', 'authorization_env', 'body', 'expected_status', 'json_expect', 'max_p99_ms'];
    $expectKeys($target, ['id', 'category', 'url', 'method', 'effect', 'authorization_env', 'body', 'expected_status', 'json_expect', 'max_p99_ms'], $keys, 'target');
    $id = $target['id'] ?? null;
    $category = $target['category'] ?? null;
    if (!is_string($id) || preg_match('/^[a-z][a-z0-9_-]{2,63}$/', $id) !== 1 || isset($seenTargetIds[$id])) throw new RuntimeException('target id is invalid or duplicate');
    if (!is_string($category) || !in_array($category, $requiredCategories, true) || isset($seenCategories[$category])) throw new RuntimeException('target category is invalid or duplicate');
    $seenTargetIds[$id] = true;
    $seenCategories[$category] = true;
    $url = filter_var($target['url'] ?? null, FILTER_VALIDATE_URL);
    if (!is_string($url)) throw new RuntimeException($id . ' URL is invalid');
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $loopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
    if (isset($parts['user']) || isset($parts['pass'])) throw new RuntimeException($id . ' URL must not contain credentials');
    if (!isset($approvedHosts[$host])) throw new RuntimeException($id . ' host is not in approved_hosts');
    if ($scheme !== 'https' && !($plan['allow_loopback_http'] && $scheme === 'http' && $loopback)) throw new RuntimeException($id . ' must use HTTPS or explicitly allowed loopback HTTP');
    if (!in_array($target['method'], ['GET', 'POST'], true)) throw new RuntimeException($id . ' method must be GET or POST');
    if (!in_array($target['effect'], ['read_only', 'audit_only', 'idempotent_fixture'], true)) throw new RuntimeException($id . ' effect is invalid');
    if ($target['effect'] !== 'read_only') $writeEffects = true;
    if ($target['authorization_env'] !== null && (!is_string($target['authorization_env']) || preg_match('/^SAND_IAM_ENDURANCE_[A-Z0-9_]+$/', $target['authorization_env']) !== 1)) throw new RuntimeException($id . ' authorization_env is invalid');
    if ($target['method'] === 'GET' && $target['body'] !== null) throw new RuntimeException($id . ' GET target cannot have a body');
    if ($target['body'] !== null && !is_array($target['body'])) throw new RuntimeException($id . ' body must be an object or null');
    if (!is_int($target['expected_status']) || $target['expected_status'] < 100 || $target['expected_status'] > 599) throw new RuntimeException($id . ' expected_status is invalid');
    if (!is_array($target['json_expect']) || $target['json_expect'] === []) throw new RuntimeException($id . ' must declare JSON expectations');
    foreach ($target['json_expect'] as $pointer => $expected) {
        if (!is_string($pointer) || ($pointer !== '' && !str_starts_with($pointer, '/')) || !(is_scalar($expected) || $expected === null)) throw new RuntimeException($id . ' JSON expectation is invalid');
    }
    $expectedValues = array_values($target['json_expect']);
    if ($category === 'candidate' && (!in_array($candidate['archive_sha256'], $expectedValues, true) || !in_array($candidate['artifact_manifest_sha256'], $expectedValues, true))) {
        throw new RuntimeException('candidate probe must assert archive and artifact manifest SHA-256');
    }
    if ($category === 'allow' && ($target['expected_status'] < 200 || $target['expected_status'] >= 300 || !in_array(true, $expectedValues, true))) {
        throw new RuntimeException('allow probe must assert a successful explicit allow');
    }
    if (in_array($category, ['deny', 'revoked'], true)
        && !in_array($target['expected_status'], [401, 403], true)
        && !in_array(false, $expectedValues, true)) {
        throw new RuntimeException($category . ' probe must assert 401/403 or an explicit false decision');
    }
    if ($category === 'audit' && !in_array($plan['acceptance_run_id'], $expectedValues, true)) {
        throw new RuntimeException('audit probe must assert the acceptance_run_id');
    }
    if (!is_int($target['max_p99_ms']) || $target['max_p99_ms'] < 1) throw new RuntimeException($id . ' max_p99_ms is invalid');
}
$missingCategories = array_values(array_diff($requiredCategories, array_keys($seenCategories)));
if ($missingCategories !== [] || count($seenCategories) !== count($requiredCategories)) throw new RuntimeException('targets must contain each required category exactly once: ' . implode(', ', $missingCategories));

$requiredMetrics = ['rss_bytes', 'fd_count', 'queue_depth', 'unrecoverable_backlog', 'security_operation_retention_backlog', 'auth_rate_limit_retention_backlog', 'worker_exit_total', 'worker_restart_total', 'unauthorized_allow_total', 'data_corruption_total', 'event_loop_lag_ms', 'pool_wait_ms'];
if (!is_array($plan['metrics'])) throw new RuntimeException('metrics must be an object');
$expectKeys($plan['metrics'], $requiredMetrics, $requiredMetrics, 'metrics');
foreach ($plan['metrics'] as $name => $pointer) {
    if (!is_string($pointer) || !str_starts_with($pointer, '/')) throw new RuntimeException('metrics.' . $name . ' must be a JSON pointer');
}
$requiredThresholds = ['max_error_rate', 'max_gap_seconds', 'max_rss_bytes', 'max_rss_slope_bytes_per_hour', 'max_fd_count', 'max_fd_slope_per_hour', 'max_queue_depth', 'max_unrecoverable_backlog', 'max_security_operation_retention_backlog', 'max_auth_rate_limit_retention_backlog', 'max_worker_exit_delta', 'max_worker_restart_delta', 'max_unauthorized_allow_delta', 'max_data_corruption_delta', 'max_event_loop_lag_p99_ms', 'max_pool_wait_p99_ms'];
if (!is_array($plan['thresholds'])) throw new RuntimeException('thresholds must be an object');
$expectKeys($plan['thresholds'], $requiredThresholds, $requiredThresholds, 'thresholds');
foreach ($plan['thresholds'] as $name => $value) {
    if (!(is_int($value) || is_float($value)) || $value < 0) throw new RuntimeException('thresholds.' . $name . ' must be non-negative');
}
if ($plan['thresholds']['max_error_rate'] != 0) throw new RuntimeException('release endurance max_error_rate must be zero');
if ($plan['thresholds']['max_unrecoverable_backlog'] != 0 || $plan['thresholds']['max_security_operation_retention_backlog'] != 0 || $plan['thresholds']['max_auth_rate_limit_retention_backlog'] != 0 || $plan['thresholds']['max_worker_exit_delta'] != 0
    || $plan['thresholds']['max_worker_restart_delta'] != 0 || $plan['thresholds']['max_unauthorized_allow_delta'] != 0
    || $plan['thresholds']['max_data_corruption_delta'] != 0) {
    throw new RuntimeException('crash, restart, unauthorized allow, corruption, and unrecoverable or retention backlog thresholds must be zero');
}

if ($validateOnly) {
    echo 'SandIAM endurance plan valid: duration_seconds=' . $plan['duration_seconds'] . ' interval_seconds=' . $plan['interval_seconds'] . ' targets=7' . PHP_EOL;
    exit(0);
}

$output = $options['output'] ?? null;
if (!is_string($output) || trim($output) === '') throw new InvalidArgumentException('--output is required unless --validate-only is used');
$outputParent = realpath(dirname($output));
$packageRoot = realpath(dirname(__DIR__));
if (!is_string($outputParent) || !is_string($packageRoot) || $outputParent === $packageRoot || str_starts_with($outputParent, $packageRoot . '/')) throw new RuntimeException('endurance output must use an existing directory outside sand-iam/');
$output = $outputParent . '/' . basename($output);
if (file_exists($output) || is_link($output)) throw new RuntimeException('refusing to overwrite endurance evidence');
if ($writeEffects && getenv('SAND_IAM_ENDURANCE_ALLOW_WRITES') !== '1') throw new RuntimeException('plan contains audit/idempotent effects; SAND_IAM_ENDURANCE_ALLOW_WRITES=1 is required');
if (!function_exists('curl_multi_init')) throw new RuntimeException('PHP curl extension is required');

$jsonPointer = static function (mixed $document, string $pointer): mixed {
    if ($pointer === '') return $document;
    $current = $document;
    foreach (explode('/', substr($pointer, 1)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
        if (!is_array($current) || !array_key_exists($segment, $current)) throw new RuntimeException('JSON pointer not found: ' . $pointer);
        $current = $current[$segment];
    }
    return $current;
};
$canonical = null;
$canonical = static function (mixed $value) use (&$canonical): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = $canonical($item);
    return $value;
};
$canonicalJson = static fn (array $value): string => json_encode($canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

$evidenceHandle = fopen($output, 'xb');
if (!is_resource($evidenceHandle) || !chmod($output, 0600)) throw new RuntimeException('cannot create endurance evidence');
$startedWall = time();
$startedMono = hrtime(true);
$previousHash = str_repeat('0', 64);
$iteration = 0;
$latencies = [];
$metricsSeries = [];
$failedChecks = 0;
$totalChecks = 0;
$sampleTimes = [];
$acceptanceRunId = $plan['acceptance_run_id'];

try {
    do {
        $iterationStarted = hrtime(true);
        $multi = curl_multi_init();
        $handles = [];
        $bodies = [];
        $requestIds = [];
        foreach ($plan['targets'] as $target) {
            $id = $target['id'];
            $requestIds[$id] = 'sand-iam-endurance-' . bin2hex(random_bytes(12));
            $headers = ['Accept: application/json', 'X-Request-Id: ' . $requestIds[$id], 'X-Acceptance-Run-Id: ' . $acceptanceRunId];
            if (is_string($target['authorization_env'])) {
                $authorization = getenv($target['authorization_env']);
                if (!is_string($authorization) || trim($authorization) === '') throw new RuntimeException('missing authorization environment variable for ' . $id);
                $headers[] = 'Authorization: ' . trim($authorization);
            }
            $body = $target['body'] === null ? null : json_encode($target['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($body !== null) $headers[] = 'Content-Type: application/json';
            $curl = curl_init($target['url']);
            if ($curl === false) throw new RuntimeException('cannot initialize curl for ' . $id);
            $bodies[$id] = '';
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $target['method'], CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $plan['request_timeout_ms']), CURLOPT_TIMEOUT_MS => $plan['request_timeout_ms'],
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_POSTFIELDS => $body, CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bodies, $id): int {
                    if (strlen($bodies[$id]) + strlen($chunk) > 1048576) return 0;
                    $bodies[$id] .= $chunk;
                    return strlen($chunk);
                },
            ]);
            curl_multi_add_handle($multi, $curl);
            $handles[$id] = [$curl, $target];
        }
        do {
            $multiStatus = curl_multi_exec($multi, $active);
            if ($active) curl_multi_select($multi, 1.0);
        } while ($active && $multiStatus === CURLM_OK);
        $probeResults = [];
        $iterationMetrics = null;
        foreach ($handles as $id => [$curl, $target]) {
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $elapsedMs = (float) curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000;
            $error = curl_error($curl);
            $passed = $error === '' && $status === $target['expected_status'];
            $decoded = null;
            try { $decoded = json_decode($bodies[$id], true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { $passed = false; }
            if (is_array($decoded)) {
                foreach ($target['json_expect'] as $pointer => $expected) {
                    try { if ($jsonPointer($decoded, $pointer) !== $expected) $passed = false; } catch (RuntimeException) { $passed = false; }
                }
                if ($target['category'] === 'metrics') {
                    $iterationMetrics = [];
                    foreach ($plan['metrics'] as $name => $pointer) {
                        try { $value = $jsonPointer($decoded, $pointer); } catch (RuntimeException) { $value = null; }
                        if (!(is_int($value) || is_float($value)) || $value < 0) $passed = false;
                        $iterationMetrics[$name] = $value;
                    }
                }
            }
            $probeResults[$id] = ['category' => $target['category'], 'request_id' => $requestIds[$id], 'status' => $status, 'elapsed_ms' => round($elapsedMs, 3), 'response_sha256' => hash('sha256', $bodies[$id]), 'passed' => $passed, 'transport_error' => $error === '' ? null : $error];
            $latencies[$id][] = $elapsedMs;
            ++$totalChecks;
            if (!$passed) ++$failedChecks;
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
        }
        curl_multi_close($multi);
        if (is_array($iterationMetrics)) $metricsSeries[] = ['elapsed_seconds' => (hrtime(true) - $startedMono) / 1e9, 'values' => $iterationMetrics];
        $elapsedSeconds = (hrtime(true) - $startedMono) / 1e9;
        $record = ['schema' => 'sand-iam.endurance-sample/v1', 'iteration' => $iteration, 'wall_time' => gmdate('Y-m-d\TH:i:s\Z'), 'elapsed_seconds' => round($elapsedSeconds, 6), 'previous_sha256' => $previousHash, 'probes' => $probeResults, 'metrics' => $iterationMetrics];
        $recordHash = hash('sha256', $canonicalJson($record));
        $record['record_sha256'] = $recordHash;
        if (fwrite($evidenceHandle, $canonicalJson($record) . "\n") === false || !fflush($evidenceHandle)) throw new RuntimeException('cannot append endurance evidence');
        $previousHash = $recordHash;
        $sampleTimes[] = $elapsedSeconds;
        ++$iteration;
        $remaining = $plan['duration_seconds'] - $elapsedSeconds;
        if ($remaining <= 0) break;
        if ($remaining > 0) {
            $iterationRuntime = (hrtime(true) - $iterationStarted) / 1e9;
            $sleepSeconds = min($remaining, max(0.0, $plan['interval_seconds'] - $iterationRuntime));
            if ($sleepSeconds > 0) usleep((int) min(PHP_INT_MAX, round($sleepSeconds * 1e6)));
        }
    } while (true);
} finally {
    fclose($evidenceHandle);
}

$percentile = static function (array $values, float $quantile): float {
    if ($values === []) return INF;
    sort($values, SORT_NUMERIC);
    return $values[max(0, min(count($values) - 1, (int) ceil($quantile * count($values)) - 1))];
};
$slopePerHour = static function (array $series, string $metric): float {
    $count = count($series);
    if ($count < 2) return INF;
    $meanX = array_sum(array_column($series, 'elapsed_seconds')) / $count;
    $meanY = array_sum(array_map(static fn (array $sample): float => (float) $sample['values'][$metric], $series)) / $count;
    $numerator = 0.0; $denominator = 0.0;
    foreach ($series as $sample) {
        $x = $sample['elapsed_seconds'] - $meanX;
        $numerator += $x * ((float) $sample['values'][$metric] - $meanY);
        $denominator += $x * $x;
    }
    return $denominator == 0.0 ? INF : ($numerator / $denominator) * 3600;
};
$metricValues = static fn (string $name): array => array_map(static fn (array $sample): float => (float) $sample['values'][$name], $metricsSeries);
$metricDelta = static function (string $name) use ($metricsSeries): float {
    if (count($metricsSeries) < 2) return INF;
    return (float) end($metricsSeries)['values'][$name] - (float) $metricsSeries[0]['values'][$name];
};
$gaps = [];
for ($index = 1; $index < count($sampleTimes); ++$index) $gaps[] = $sampleTimes[$index] - $sampleTimes[$index - 1];
$latencySummary = [];
foreach ($latencies as $id => $values) $latencySummary[$id] = ['p50_ms' => round($percentile($values, 0.50), 3), 'p95_ms' => round($percentile($values, 0.95), 3), 'p99_ms' => round($percentile($values, 0.99), 3)];
$metricsComplete = count($metricsSeries) === $iteration && count($metricsSeries) >= 2;
$resourceSummary = $metricsComplete ? [
    'max_rss_bytes' => max($metricValues('rss_bytes')), 'rss_slope_bytes_per_hour' => $slopePerHour($metricsSeries, 'rss_bytes'),
    'max_fd_count' => max($metricValues('fd_count')), 'fd_slope_per_hour' => $slopePerHour($metricsSeries, 'fd_count'),
    'max_queue_depth' => max($metricValues('queue_depth')), 'max_unrecoverable_backlog' => max($metricValues('unrecoverable_backlog')),
    'max_security_operation_retention_backlog' => max($metricValues('security_operation_retention_backlog')),
    'max_auth_rate_limit_retention_backlog' => max($metricValues('auth_rate_limit_retention_backlog')),
    'worker_exit_delta' => $metricDelta('worker_exit_total'), 'worker_restart_delta' => $metricDelta('worker_restart_total'),
    'unauthorized_allow_delta' => $metricDelta('unauthorized_allow_total'), 'data_corruption_delta' => $metricDelta('data_corruption_total'),
    'event_loop_lag_p99_ms' => $percentile($metricValues('event_loop_lag_ms'), 0.99), 'pool_wait_p99_ms' => $percentile($metricValues('pool_wait_ms'), 0.99),
] : array_fill_keys([
    'max_rss_bytes', 'rss_slope_bytes_per_hour', 'max_fd_count', 'fd_slope_per_hour', 'max_queue_depth',
    'max_unrecoverable_backlog', 'max_security_operation_retention_backlog', 'max_auth_rate_limit_retention_backlog', 'worker_exit_delta', 'worker_restart_delta', 'unauthorized_allow_delta',
    'data_corruption_delta', 'event_loop_lag_p99_ms', 'pool_wait_p99_ms',
], null);
$summary = [
    'schema' => 'sand-iam.endurance-report/v1', 'candidate' => $candidate, 'acceptance_run_id' => $acceptanceRunId,
    'started_at' => gmdate('Y-m-d\TH:i:s\Z', $startedWall), 'ended_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'duration_seconds' => round((hrtime(true) - $startedMono) / 1e9, 3), 'samples' => $iteration,
    'checks' => ['passed' => $totalChecks - $failedChecks, 'total' => $totalChecks, 'failed' => $failedChecks, 'error_rate' => $totalChecks === 0 ? 1 : $failedChecks / $totalChecks],
    'max_gap_seconds' => $gaps === [] ? INF : max($gaps), 'latency' => $latencySummary,
    'resources' => $resourceSummary,
    'evidence' => ['jsonl' => basename($output), 'sha256' => hash_file('sha256', $output), 'final_record_sha256' => $previousHash],
    'production_baseline_rules_observed' => [1, 3, 5, 10, 11, 12],
];
$thresholds = $plan['thresholds'];
$passed = $metricsComplete && $summary['duration_seconds'] >= $plan['duration_seconds']
    && $summary['checks']['error_rate'] <= $thresholds['max_error_rate']
    && $summary['max_gap_seconds'] <= $thresholds['max_gap_seconds']
    && $summary['resources']['max_rss_bytes'] <= $thresholds['max_rss_bytes']
    && $summary['resources']['rss_slope_bytes_per_hour'] <= $thresholds['max_rss_slope_bytes_per_hour']
    && $summary['resources']['max_fd_count'] <= $thresholds['max_fd_count']
    && $summary['resources']['fd_slope_per_hour'] <= $thresholds['max_fd_slope_per_hour']
    && $summary['resources']['max_queue_depth'] <= $thresholds['max_queue_depth']
    && $summary['resources']['max_unrecoverable_backlog'] <= $thresholds['max_unrecoverable_backlog']
    && $summary['resources']['max_security_operation_retention_backlog'] <= $thresholds['max_security_operation_retention_backlog']
    && $summary['resources']['max_auth_rate_limit_retention_backlog'] <= $thresholds['max_auth_rate_limit_retention_backlog']
    && $summary['resources']['worker_exit_delta'] <= $thresholds['max_worker_exit_delta']
    && $summary['resources']['worker_restart_delta'] <= $thresholds['max_worker_restart_delta']
    && $summary['resources']['unauthorized_allow_delta'] <= $thresholds['max_unauthorized_allow_delta']
    && $summary['resources']['data_corruption_delta'] <= $thresholds['max_data_corruption_delta']
    && $summary['resources']['event_loop_lag_p99_ms'] <= $thresholds['max_event_loop_lag_p99_ms']
    && $summary['resources']['pool_wait_p99_ms'] <= $thresholds['max_pool_wait_p99_ms'];
foreach ($plan['targets'] as $target) if (($latencySummary[$target['id']]['p99_ms'] ?? INF) > $target['max_p99_ms']) $passed = false;
$summary['passed'] = $passed;
$summaryPath = $output . '.summary.json';
$summaryBytes = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($summaryPath, $summaryBytes, LOCK_EX) !== strlen($summaryBytes) || !chmod($summaryPath, 0644)) throw new RuntimeException('cannot write endurance summary');
echo 'SandIAM endurance acceptance ' . ($passed ? 'passed' : 'failed') . ': samples=' . $iteration . ' evidence=' . $output . PHP_EOL;
exit($passed ? 0 : 1);
