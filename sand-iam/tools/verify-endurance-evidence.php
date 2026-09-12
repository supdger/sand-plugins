<?php

declare(strict_types=1);

/** Verify a completed endurance JSONL independently from its writer. */

$options = getopt('', ['plan:', 'evidence:', 'summary:']);
foreach (['plan', 'evidence', 'summary'] as $required) {
    if (!is_string($options[$required] ?? null) || trim($options[$required]) === '') throw new InvalidArgumentException('--' . $required . ' is required');
}
$paths = [];
foreach (['plan', 'evidence', 'summary'] as $name) {
    $path = realpath($options[$name]);
    if (!is_string($path) || !is_file($path) || is_link($options[$name])) throw new RuntimeException($name . ' must be an existing regular file');
    $paths[$name] = $path;
}
$validatorOutput = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/run-endurance-acceptance.php') . ' --plan=' . escapeshellarg($paths['plan']) . ' --validate-only 2>&1', $validatorOutput, $validatorStatus);
if ($validatorStatus !== 0) throw new RuntimeException('endurance plan validation failed: ' . implode("\n", $validatorOutput));
$plan = json_decode((string) file_get_contents($paths['plan']), true, 512, JSON_THROW_ON_ERROR);
$summary = json_decode((string) file_get_contents($paths['summary']), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($summary) || ($summary['schema'] ?? null) !== 'sand-iam.endurance-report/v1' || ($summary['passed'] ?? null) !== true) throw new RuntimeException('summary is not a passed endurance report');
if (($summary['candidate'] ?? null) !== $plan['candidate'] || ($summary['acceptance_run_id'] ?? null) !== $plan['acceptance_run_id']) throw new RuntimeException('summary candidate or acceptance run identity differs from plan');

$canonicalize = null;
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = $canonicalize($item);
    return $value;
};
$canonicalJson = static fn (array $value): string => json_encode($canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
$targetMap = [];
foreach ($plan['targets'] as $target) $targetMap[$target['id']] = $target;
$sameKeySet = static function (array $left, array $right): bool {
    $leftKeys = array_keys($left); $rightKeys = array_keys($right);
    sort($leftKeys, SORT_STRING); sort($rightKeys, SORT_STRING);
    return $leftKeys === $rightKeys;
};
$previousHash = str_repeat('0', 64);
$iteration = 0;
$elapsedSeries = [];
$wallSeries = [];
$latencies = [];
$metricsSeries = [];
$requestIds = [];
$file = new SplFileObject($paths['evidence'], 'r');
while (!$file->eof()) {
    $line = trim((string) $file->fgets());
    if ($line === '') continue;
    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($record) || ($record['schema'] ?? null) !== 'sand-iam.endurance-sample/v1' || ($record['iteration'] ?? null) !== $iteration) throw new RuntimeException('invalid sample sequence at iteration ' . $iteration);
    $recordHash = $record['record_sha256'] ?? null;
    unset($record['record_sha256']);
    if (!is_string($recordHash) || !hash_equals($recordHash, hash('sha256', $canonicalJson($record))) || ($record['previous_sha256'] ?? null) !== $previousHash) throw new RuntimeException('endurance hash chain failed at iteration ' . $iteration);
    $elapsed = $record['elapsed_seconds'] ?? null;
    $wall = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', (string) ($record['wall_time'] ?? ''), new DateTimeZone('UTC'));
    if (!(is_int($elapsed) || is_float($elapsed)) || $elapsed < 0 || !$wall instanceof DateTimeImmutable) throw new RuntimeException('sample time is invalid at iteration ' . $iteration);
    if ($elapsedSeries !== [] && $elapsed <= end($elapsedSeries)) throw new RuntimeException('sample elapsed time did not increase');
    $probes = $record['probes'] ?? null;
    if (!is_array($probes) || !$sameKeySet($probes, $targetMap)) throw new RuntimeException('sample probe set differs from the plan');
    foreach ($targetMap as $id => $target) {
        $probe = $probes[$id] ?? null;
        if (!is_array($probe) || ($probe['category'] ?? null) !== $target['category'] || ($probe['passed'] ?? null) !== true || ($probe['status'] ?? null) !== $target['expected_status'] || ($probe['transport_error'] ?? null) !== null) throw new RuntimeException('probe failed or differs from plan: ' . $id);
        $requestId = $probe['request_id'] ?? null;
        if (!is_string($requestId) || preg_match('/^sand-iam-endurance-[a-f0-9]{24}$/', $requestId) !== 1 || isset($requestIds[$requestId])) throw new RuntimeException('probe request ID is invalid or reused');
        $requestIds[$requestId] = true;
        $latency = $probe['elapsed_ms'] ?? null;
        if (!(is_int($latency) || is_float($latency)) || $latency < 0) throw new RuntimeException('probe latency is invalid');
        $latencies[$id][] = (float) $latency;
    }
    $metrics = $record['metrics'] ?? null;
    if (!is_array($metrics) || !$sameKeySet($metrics, $plan['metrics'])) throw new RuntimeException('sample metrics differ from plan');
    foreach ($metrics as $name => $value) if (!(is_int($value) || is_float($value)) || $value < 0) throw new RuntimeException('sample metric is invalid: ' . $name);
    $metricsSeries[] = ['elapsed_seconds' => (float) $elapsed, 'values' => $metrics];
    $elapsedSeries[] = (float) $elapsed;
    $wallSeries[] = $wall->getTimestamp();
    $previousHash = $recordHash;
    ++$iteration;
}
if ($iteration < 2 || end($elapsedSeries) < $plan['duration_seconds']) throw new RuntimeException('endurance evidence did not reach the required duration');
$minimumSamples = (int) floor($plan['duration_seconds'] / $plan['thresholds']['max_gap_seconds']) + 1;
if ($iteration < $minimumSamples) throw new RuntimeException('endurance evidence has too few continuous samples');
$gaps = [];
for ($index = 1; $index < count($elapsedSeries); ++$index) {
    $gap = $elapsedSeries[$index] - $elapsedSeries[$index - 1];
    $gaps[] = $gap;
    if ($gap > $plan['thresholds']['max_gap_seconds']) throw new RuntimeException('endurance sample gap exceeded threshold');
}
if (end($wallSeries) - $wallSeries[0] < $plan['duration_seconds'] - 1) throw new RuntimeException('wall-clock evidence did not span 24 hours');

$percentile = static function (array $values, float $quantile): float {
    sort($values, SORT_NUMERIC);
    return $values[max(0, min(count($values) - 1, (int) ceil($quantile * count($values)) - 1))];
};
$slopePerHour = static function (array $series, string $metric): float {
    $count = count($series); $meanX = array_sum(array_column($series, 'elapsed_seconds')) / $count;
    $meanY = array_sum(array_map(static fn (array $sample): float => (float) $sample['values'][$metric], $series)) / $count;
    $numerator = 0.0; $denominator = 0.0;
    foreach ($series as $sample) { $x = $sample['elapsed_seconds'] - $meanX; $numerator += $x * ((float) $sample['values'][$metric] - $meanY); $denominator += $x * $x; }
    return $denominator == 0.0 ? INF : ($numerator / $denominator) * 3600;
};
$values = static fn (string $metric): array => array_map(static fn (array $sample): float => (float) $sample['values'][$metric], $metricsSeries);
$delta = static fn (string $metric): float => (float) end($metricsSeries)['values'][$metric] - (float) $metricsSeries[0]['values'][$metric];
$thresholds = $plan['thresholds'];
foreach ($targetMap as $id => $target) if ($percentile($latencies[$id], 0.99) > $target['max_p99_ms']) throw new RuntimeException('probe p99 exceeded threshold: ' . $id);
if (max($values('rss_bytes')) > $thresholds['max_rss_bytes'] || $slopePerHour($metricsSeries, 'rss_bytes') > $thresholds['max_rss_slope_bytes_per_hour']) throw new RuntimeException('RSS ceiling or slope failed');
if (max($values('fd_count')) > $thresholds['max_fd_count'] || $slopePerHour($metricsSeries, 'fd_count') > $thresholds['max_fd_slope_per_hour']) throw new RuntimeException('FD ceiling or slope failed');
if (max($values('queue_depth')) > $thresholds['max_queue_depth'] || max($values('unrecoverable_backlog')) !== 0.0) throw new RuntimeException('queue or backlog threshold failed');
if (max($values('security_operation_retention_backlog')) !== 0.0) throw new RuntimeException('security operation retention backlog threshold failed');
if (max($values('auth_rate_limit_retention_backlog')) !== 0.0) throw new RuntimeException('auth rate-limit retention backlog threshold failed');
foreach (['worker_exit_total', 'worker_restart_total', 'unauthorized_allow_total', 'data_corruption_total'] as $zeroDeltaMetric) if ($delta($zeroDeltaMetric) !== 0.0) throw new RuntimeException($zeroDeltaMetric . ' delta must be zero');
if ($percentile($values('event_loop_lag_ms'), 0.99) > $thresholds['max_event_loop_lag_p99_ms'] || $percentile($values('pool_wait_ms'), 0.99) > $thresholds['max_pool_wait_p99_ms']) throw new RuntimeException('event-loop or pool-wait p99 failed');

$evidenceHash = hash_file('sha256', $paths['evidence']);
if (!is_string($evidenceHash) || ($summary['evidence']['sha256'] ?? null) !== $evidenceHash || ($summary['evidence']['final_record_sha256'] ?? null) !== $previousHash
    || ($summary['samples'] ?? null) !== $iteration || ($summary['checks']['failed'] ?? null) !== 0 || ($summary['checks']['total'] ?? null) !== $iteration * count($targetMap)
    || ($summary['duration_seconds'] ?? 0) < $plan['duration_seconds']) {
    throw new RuntimeException('summary does not bind the verified endurance evidence');
}
echo 'SandIAM endurance evidence verified: samples=' . $iteration . ' duration_seconds=' . end($elapsedSeries) . ' final_record_sha256=' . $previousHash . PHP_EOL;
