<?php

declare(strict_types=1);

/** Verify a completed endurance JSONL independently from its writer. */

$options = getopt('', ['plan:', 'evidence:', 'summary:', 'archive:', 'artifact-manifest:', 'self-test-snapshot']);
$selfTestSnapshot = array_key_exists('self-test-snapshot', $options);
if (!$selfTestSnapshot) foreach (['plan', 'evidence', 'summary', 'archive', 'artifact-manifest'] as $required) {
    if (!is_string($options[$required] ?? null) || trim($options[$required]) === '') throw new InvalidArgumentException('--' . $required . ' is required');
}
$readStableSnapshot = static function (string $path, string $label, ?callable $afterOpen = null, bool $requireImmutable = true): array {
    clearstatcache(true, $path);
    $before = @lstat($path);
    if (!is_array($before) || is_link($path) || (($before['mode'] & 0170000) !== 0100000) || ($requireImmutable && (($before['mode'] & 0222) !== 0))) throw new RuntimeException($label . ' must be an immutable non-symlink regular file');
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) throw new RuntimeException($label . ' cannot be opened');
    try {
        $opened = fstat($handle);
        if (!is_array($opened) || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino'] || (($opened['mode'] & 0170000) !== 0100000) || ($requireImmutable && (($opened['mode'] & 0222) !== 0)) || !@flock($handle, LOCK_SH | LOCK_NB)) throw new RuntimeException($label . ' is writable or locked before fixed-FD snapshot');
        if ($afterOpen !== null) $afterOpen();
        $bytes = stream_get_contents($handle);
        clearstatcache(true, $path); $after = @lstat($path); $finished = fstat($handle);
        if (!is_string($bytes) || !is_array($after) || is_link($path) || !is_array($finished)) throw new RuntimeException($label . ' changed during fixed-FD snapshot');
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) if (($after[$field] ?? null) !== ($opened[$field] ?? null) || ($finished[$field] ?? null) !== ($opened[$field] ?? null)) throw new RuntimeException($label . ' changed during fixed-FD snapshot');
        return ['path' => $path, 'bytes' => $bytes];
    } finally { @flock($handle, LOCK_UN); fclose($handle); }
};
if ($selfTestSnapshot) {
    $directory = sys_get_temp_dir() . '/sand-iam-endurance-snapshot-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700)) throw new RuntimeException('cannot create snapshot self-test directory');
    $path = $directory . '/evidence.jsonl';
    try {
        file_put_contents($path, "one\n");
        try { $readStableSnapshot($path, 'self-test evidence'); throw new RuntimeException('writable snapshot self-test did not fail'); } catch (RuntimeException $exception) { if (!str_contains($exception->getMessage(), 'immutable')) throw $exception; }
        if (!chmod($path, 0444)) throw new RuntimeException('cannot make snapshot self-test immutable');
        $exclusive = fopen($path, 'rb'); if (!is_resource($exclusive) || !flock($exclusive, LOCK_EX)) throw new RuntimeException('cannot lock snapshot self-test file');
        try { $readStableSnapshot($path, 'self-test evidence'); throw new RuntimeException('locked snapshot self-test did not fail'); } catch (RuntimeException $exception) { if (!str_contains($exception->getMessage(), 'writable or locked')) throw $exception; } finally { flock($exclusive, LOCK_UN); fclose($exclusive); }
        if ($readStableSnapshot($path, 'self-test evidence')['bytes'] !== "one\n") throw new RuntimeException('immutable snapshot self-test did not read the expected bytes');
        echo "SandIAM endurance snapshot self-test passed\n";
    } finally { if (is_file($path) || is_link($path)) { @chmod($path, 0600); @unlink($path); } @rmdir($directory); }
    exit(0);
}
$paths = []; $snapshots = [];
foreach (['plan', 'evidence', 'summary'] as $name) {
    $snapshot = $readStableSnapshot($options[$name], $name, null, $name !== 'plan'); $paths[$name] = $snapshot['path']; $snapshots[$name] = $snapshot['bytes'];
}
$packageRoot = dirname(__DIR__);
require_once __DIR__ . '/release-bundle-attestation.php';
$archivePath = sandIamAssertExternalPath($options['archive'], $packageRoot, 'release archive');
$manifestPath = sandIamAssertExternalPath($options['artifact-manifest'], $packageRoot, 'artifact manifest');
$manifest = sandIamReadArtifactManifest($manifestPath);
$validatorOutput = [];
$planSnapshot = tempnam(sys_get_temp_dir(), 'sand-iam-endurance-plan-');
if ($planSnapshot === false || file_put_contents($planSnapshot, $snapshots['plan'], LOCK_EX) !== strlen($snapshots['plan'])) throw new RuntimeException('cannot create fixed plan snapshot');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/run-endurance-acceptance.php') . ' --plan=' . escapeshellarg($planSnapshot) . ' --validate-only 2>&1', $validatorOutput, $validatorStatus);
@unlink($planSnapshot);
if ($validatorStatus !== 0) throw new RuntimeException('endurance plan validation failed: ' . implode("\n", $validatorOutput));
$plan = json_decode($snapshots['plan'], true, 512, JSON_THROW_ON_ERROR);
$summary = json_decode($snapshots['summary'], true, 512, JSON_THROW_ON_ERROR);
if (!is_array($summary) || ($summary['schema'] ?? null) !== 'sand-iam.endurance-report/v2') throw new RuntimeException('summary is not an endurance v2 report');

$canonicalize = null;
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = $canonicalize($item);
    return $value;
};
$canonicalJson = static fn (mixed $value): string => json_encode($canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
$identity = [
    'plan_sha256' => hash('sha256', $snapshots['plan']), 'acceptance_run_id' => $plan['acceptance_run_id'], 'candidate' => $plan['candidate'],
    'environment' => $plan['environment'], 'collector' => $plan['collector'],
    'thresholds_sha256' => hash('sha256', $canonicalJson($plan['thresholds'])), 'endpoints_sha256' => hash('sha256', $canonicalJson($plan['targets'])),
    'runtime_identity_sha256' => hash('sha256', $canonicalJson($plan['runtime_identity'])),
];
if ($canonicalJson($summary['identity'] ?? null) !== $canonicalJson($identity)
    || $canonicalJson($summary['candidate'] ?? null) !== $canonicalJson($plan['candidate'])
    || ($summary['acceptance_run_id'] ?? null) !== $plan['acceptance_run_id']) throw new RuntimeException('summary identity, threshold, endpoint, candidate, or run binding differs from plan');
$package = $manifest['package'];
$archiveFiles = sandIamInspectReleaseZip($archivePath);
if (hash_file('sha256', $archivePath) !== $plan['candidate']['archive_sha256'] || filesize($archivePath) !== $plan['candidate']['archive_bytes']
    || hash_file('sha256', $manifestPath) !== $plan['candidate']['artifact_manifest_sha256']
    || ($package['version'] ?? null) !== $plan['candidate']['version'] || ($package['sha256'] ?? null) !== $plan['candidate']['archive_sha256']
    || ($package['bytes'] ?? null) !== $plan['candidate']['archive_bytes'] || ($manifest['source_revision']['commit'] ?? null) !== ($plan['candidate']['source_revision']['commit'] ?? null)
    || ($manifest['source_revision']['tree'] ?? null) !== ($plan['candidate']['source_revision']['tree'] ?? null)
    || ($archiveFiles['entry_count'] ?? null) !== ($package['entry_count'] ?? null) || ($archiveFiles['files'] ?? null) !== ($manifest['files'] ?? null)) {
    throw new RuntimeException('external ZIP, manifest, file map, or source provenance does not bind the plan candidate');
}
$targetMap = [];
foreach ($plan['targets'] as $target) $targetMap[$target['id']] = $target;
$sameKeySet = static function (array $left, array $right): bool {
    $leftKeys = array_keys($left); $rightKeys = array_keys($right);
    sort($leftKeys, SORT_STRING); sort($rightKeys, SORT_STRING);
    return $leftKeys === $rightKeys;
};
$assertionFields = array_fill_keys(['pointer', 'expected_kind', 'expected_sha256', 'actual_kind', 'actual', 'actual_sha256', 'result'], true);
$valueKind = static function (mixed $value): string {
    return $value === null ? 'null' : (is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : (is_float($value) ? 'float' : (is_string($value) ? 'string' : (array_is_list($value) ? 'array' : 'object')))));
};
$parseUtc = static function (mixed $value): DateTimeImmutable {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) throw new RuntimeException('wall timestamp is not strict UTC RFC3339');
    $wall = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$wall instanceof DateTimeImmutable || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) || $wall->format('Y-m-d\TH:i:s\Z') !== $value) throw new RuntimeException('wall timestamp is invalid or normalized');
    return $wall;
};
$sameKeySet = static function (array $left, array $right): bool {
    $leftKeys = array_keys($left); $rightKeys = array_keys($right); sort($leftKeys, SORT_STRING); sort($rightKeys, SORT_STRING);
    return $leftKeys === $rightKeys;
};
$runtimeIdentityFields = ['boot_id', 'process_group_id', 'supervisor_restart_total'];
$sideEffectByDecision = ['allow' => 'applied', 'deny' => 'rejected', 'revoked' => 'revoked'];
$counterMetrics = ['worker_exit_total', 'worker_restart_total', 'unauthorized_allow_total', 'data_corruption_total', 'unrecoverable_backlog_total'];
$zeroCounterMetrics = ['unrecoverable_backlog', 'security_operation_retention_backlog', 'auth_rate_limit_retention_backlog'];
$counterString = static function (mixed $value, string $name): string {
    if (is_int($value) && $value >= 0) return (string) $value;
    if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/', $value) === 1) return $value;
    throw new RuntimeException($name . ' must be a non-negative integer or decimal integer string');
};
$sameCounter = static function (mixed $left, mixed $right, string $name) use ($counterString): bool {
    return $counterString($left, $name) === $counterString($right, $name);
};
$number = static function (mixed $value, string $name): float {
    if (!(is_int($value) || is_float($value)) || !is_finite((float) $value) || $value < 0) throw new RuntimeException($name . ' must be finite and non-negative');
    return (float) $value;
};
$durationMicroseconds = $plan['duration_seconds'] * 1000000;
$maxGapMicroseconds = $plan['thresholds']['max_gap_seconds'] * 1000000;
$clockSkewMicroseconds = $plan['max_clock_skew_seconds'] * 1000000;
$minimumSamplesFor = static function (int $duration, int $gap): int {
    $quotient = intdiv($duration, $gap);
    $remainder = $duration % $gap;
    $increment = $remainder === 0 ? 1 : 2;
    if ($quotient > PHP_INT_MAX - $increment) throw new RuntimeException('endurance minimum sample count exceeds exact integer range');
    return $quotient + $increment;
};
$previousHash = str_repeat('0', 64);
$iteration = 0;
$elapsedSeries = [];
$wallSeries = [];
$latencies = [];
$metricsSeries = [];
$metricBaseline = null;
$runtimeIdentityBaseline = null;
$runStartedAt = null;
$runStartedTimestamp = null;
$requestIds = [];
$auditedBusinessRequestIds = []; $auditServiceReferences = [];
$evidenceBytes = $snapshots['evidence'];
if ($evidenceBytes === '' || !str_ends_with($evidenceBytes, "\n")) throw new RuntimeException('endurance evidence has a tail truncation or lacks its JSONL terminator');
foreach (explode("\n", substr($evidenceBytes, 0, -1)) as $line) {
    if ($line === '') throw new RuntimeException('endurance evidence has a blank or truncated JSONL record');
    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($record) || !$sameKeySet($record, array_fill_keys(['schema', 'identity', 'iteration', 'run_started_at', 'runtime_identity', 'wall_time', 'elapsed_microseconds', 'previous_sha256', 'probes', 'metrics', 'record_sha256'], true)) || ($record['schema'] ?? null) !== 'sand-iam.endurance-sample/v2' || ($record['iteration'] ?? null) !== $iteration
        || $canonicalJson($record['identity'] ?? null) !== $canonicalJson($identity)) throw new RuntimeException('invalid v2 sample identity or sequence at iteration ' . $iteration);
    $recordHash = $record['record_sha256'] ?? null;
    unset($record['record_sha256']);
    if (!is_string($recordHash) || !hash_equals($recordHash, hash('sha256', $canonicalJson($record))) || ($record['previous_sha256'] ?? null) !== $previousHash) throw new RuntimeException('endurance hash chain failed at iteration ' . $iteration);
    $elapsed = $record['elapsed_microseconds'] ?? null;
    if (!is_int($elapsed) || $elapsed < 0) throw new RuntimeException('sample monotonic microseconds are invalid at iteration ' . $iteration);
    $wall = $parseUtc($record['wall_time'] ?? null);
    $start = $parseUtc($record['run_started_at'] ?? null);
    if ($runStartedAt === null) { $runStartedAt = $record['run_started_at']; $runStartedTimestamp = $start->getTimestamp(); }
    elseif ($record['run_started_at'] !== $runStartedAt) throw new RuntimeException('sample start baseline changed');
    $previousElapsed = $elapsedSeries === [] ? 0 : (int) end($elapsedSeries);
    $previousWallElapsed = $wallSeries === [] ? 0 : ((int) end($wallSeries) - $runStartedTimestamp) * 1000000;
    $wallElapsed = ($wall->getTimestamp() - $runStartedTimestamp) * 1000000;
    $elapsedGap = $elapsed - $previousElapsed;
    $wallGap = $wallElapsed - $previousWallElapsed;
    if ($elapsedGap <= 0 || $elapsedGap > $maxGapMicroseconds || $wallGap < 0 || abs($wallElapsed - $elapsed) > $clockSkewMicroseconds) throw new RuntimeException('sample timing continuity failed at iteration ' . $iteration);
    $probes = $record['probes'] ?? null;
    if (!is_array($probes) || !$sameKeySet($probes, $targetMap)) throw new RuntimeException('sample probe set differs from the plan');
    foreach ($targetMap as $id => $target) {
        $probe = $probes[$id] ?? null;
        if (!is_array($probe) || ($probe['category'] ?? null) !== $target['category'] || ($probe['passed'] ?? null) !== true || ($probe['status'] ?? null) !== $target['expected_status'] || ($probe['transport_error'] ?? null) !== null
            || !is_string($probe['response_sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $probe['response_sha256']) !== 1) throw new RuntimeException('probe failed or differs from plan: ' . $id);
        $requestId = $probe['request_id'] ?? null;
        if (!is_string($requestId) || preg_match('/^sand-iam-endurance-[a-f0-9]{24}$/', $requestId) !== 1 || isset($requestIds[$requestId])) throw new RuntimeException('probe request ID is invalid or reused');
        $requestIds[$requestId] = true;
        $latency = $probe['elapsed_ms'] ?? null;
        if (!(is_int($latency) || is_float($latency)) || !is_finite((float) $latency) || $latency < 0) throw new RuntimeException('probe latency is invalid');
        $latencies[$id][] = (float) $latency;
        $assertions = $probe['assertions'] ?? null;
        if (!is_array($assertions) || !$sameKeySet($assertions, $target['json_expect'])) throw new RuntimeException('probe assertion set differs from plan: ' . $id);
        foreach ($target['json_expect'] as $pointer => $expected) {
            $assertion = $assertions[$pointer] ?? null;
            if (!is_array($assertion) || !$sameKeySet($assertion, $assertionFields)
                || !array_key_exists('actual', $assertion) || ($assertion['pointer'] ?? null) !== $pointer
                || !is_string($assertion['expected_kind'] ?? null) || !is_string($assertion['expected_sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $assertion['expected_sha256']) !== 1
                || !is_string($assertion['actual_kind'] ?? null) || !is_string($assertion['actual_sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $assertion['actual_sha256']) !== 1
                || !in_array($assertion['result'] ?? null, ['matched', 'mismatch', 'pointer_missing', 'response_not_object_or_array'], true)) throw new RuntimeException('probe assertion has an invalid fixed field set: ' . $id . ' ' . $pointer);
            if (($assertion['result'] ?? null) !== 'matched') throw new RuntimeException('probe assertion failed: ' . $id . ' ' . $pointer);
            $safe = $expected === null || is_bool($expected) || is_int($expected) || is_float($expected);
            $expectedKind = $valueKind($expected); $expectedHash = hash('sha256', $canonicalJson($expected));
            $sensitive = preg_match('/(?:password|client_secret|secret|token|otp|code)/i', $pointer) === 1;
            if ($assertion['expected_kind'] !== $expectedKind || !hash_equals($expectedHash, $assertion['expected_sha256']) || $assertion['actual_kind'] !== $expectedKind || !hash_equals($expectedHash, $assertion['actual_sha256'])) throw new RuntimeException('probe assertion binding differs from plan: ' . $id . ' ' . $pointer);
            if ((!$safe || $sensitive) && $assertion['actual'] !== null) throw new RuntimeException('probe assertion exposed a protected value: ' . $id . ' ' . $pointer);
            if ($safe && !$sensitive && $assertion['actual'] !== $expected) throw new RuntimeException('probe safe assertion does not independently match: ' . $id . ' ' . $pointer);
        }
    }
    $auditTarget = null; $expectedAuditRequests = [];
    foreach ($targetMap as $id => $target) {
        if ($target['category'] === 'audit') { $auditTarget = $target; continue; }
        if (!is_array($target['audit_context'])) continue;
        $probe = $probes[$id]; $context = $target['audit_context']; $observation = $probe['business_observation'] ?? null;
        $expectedSideEffect = $sideEffectByDecision[$target['category']];
        if (!is_array($observation) || !$sameKeySet($observation, array_fill_keys(['decision', 'subject', 'scope', 'candidate', 'side_effect', 'business_audit_ref'], true))
            || $observation['decision'] !== $context['decision'] || $observation['subject'] !== $context['subject'] || $observation['scope'] !== $context['scope'] || $observation['candidate'] !== $context['candidate']
            || $observation['side_effect'] !== $expectedSideEffect || !is_string($observation['business_audit_ref']) || preg_match('/^[A-Za-z0-9._:-]{3,128}$/', $observation['business_audit_ref']) !== 1) throw new RuntimeException('business side-effect or audit observation is missing or mismatched: ' . $id);
        $expectedAuditRequests[] = ['request_id' => $probe['request_id'], ...$observation, 'acceptance_run_id' => $plan['acceptance_run_id']];
    }
    $auditProbe = is_array($auditTarget) ? ($probes[$auditTarget['id']] ?? null) : null; $auditObservation = is_array($auditProbe) ? ($auditProbe['audit_observation'] ?? null) : null;
    if (!is_array($auditObservation) || !$sameKeySet($auditObservation, array_fill_keys(['requests', 'records'], true)) || !is_array($auditObservation['requests']) || !is_array($auditObservation['records']) || !array_is_list($auditObservation['requests']) || !array_is_list($auditObservation['records']) || count($auditObservation['requests']) !== 3 || count($auditObservation['records']) !== 3 || $canonicalJson($auditObservation['requests']) !== $canonicalJson($expectedAuditRequests)) throw new RuntimeException('audit query does not carry this iteration\'s three business request correlations');
    $recordByRequest = [];
    foreach ($auditObservation['records'] as $auditRecord) {
        if (!is_array($auditRecord) || !$sameKeySet($auditRecord, array_fill_keys(['request_id', 'decision', 'subject', 'scope', 'candidate', 'acceptance_run_id', 'business_audit_ref', 'service_audit_ref', 'side_effect'], true)) || !is_string($auditRecord['request_id'] ?? null) || isset($recordByRequest[$auditRecord['request_id']]) || !is_string($auditRecord['service_audit_ref'] ?? null) || preg_match('/^[A-Za-z0-9._:-]{3,128}$/', $auditRecord['service_audit_ref']) !== 1 || isset($auditServiceReferences[$auditRecord['service_audit_ref']])) throw new RuntimeException('audit query contains duplicate or malformed evidence references');
        $recordByRequest[$auditRecord['request_id']] = $auditRecord; $auditServiceReferences[$auditRecord['service_audit_ref']] = true;
    }
    foreach ($expectedAuditRequests as $expectedAuditRequest) {
        $requestId = $expectedAuditRequest['request_id']; $actualAuditRecord = $recordByRequest[$requestId] ?? null;
        if (!is_array($actualAuditRecord) || $canonicalJson($actualAuditRecord) !== $canonicalJson(array_merge($expectedAuditRequest, ['service_audit_ref' => $actualAuditRecord['service_audit_ref'] ?? null])) || isset($auditedBusinessRequestIds[$requestId])) throw new RuntimeException('audit evidence is stale, missing, duplicate, wrong-round, or wrong-decision');
        $auditedBusinessRequestIds[$requestId] = true;
    }
    $metrics = $record['metrics'] ?? null;
    if (!is_array($metrics) || !$sameKeySet($metrics, $plan['metrics'])) throw new RuntimeException('sample metrics differ from plan');
    foreach ($metrics as $name => $value) in_array($name, $counterMetrics, true) || in_array($name, $zeroCounterMetrics, true) ? $counterString($value, $name) : $number($value, $name);
    foreach ($zeroCounterMetrics as $name) if ($counterString($metrics[$name], $name) !== '0') throw new RuntimeException($name . ' must be zero in every sample');
    $runtimeIdentity = $record['runtime_identity'] ?? null;
    if (!is_array($runtimeIdentity) || !$sameKeySet($runtimeIdentity, array_fill_keys($runtimeIdentityFields, true))) throw new RuntimeException('runtime identity field set is invalid');
    foreach (['boot_id', 'process_group_id'] as $name) if (!is_string($runtimeIdentity[$name]) || preg_match('/^[A-Za-z0-9._:-]{3,128}$/', $runtimeIdentity[$name]) !== 1) throw new RuntimeException('runtime identity is invalid: ' . $name);
    $counterString($runtimeIdentity['supervisor_restart_total'], 'supervisor_restart_total');
    $runtimeIdentityCanonical = $canonicalJson($runtimeIdentity);
    if ($metricBaseline === null) { $metricBaseline = $metrics; $runtimeIdentityBaseline = $runtimeIdentityCanonical; } else {
        foreach ($counterMetrics as $name) if (!$sameCounter($metrics[$name], $metricBaseline[$name], $name)) throw new RuntimeException($name . ' changed from its baseline');
        if ($runtimeIdentityCanonical !== $runtimeIdentityBaseline) throw new RuntimeException('runtime identity changed during endurance run');
    }
    $metricsSeries[] = ['elapsed_seconds' => $elapsed / 1000000, 'values' => $metrics];
    $elapsedSeries[] = $elapsed;
    $wallSeries[] = $wall->getTimestamp();
    $previousHash = $recordHash;
    ++$iteration;
}
if ($iteration < 2 || end($elapsedSeries) < $durationMicroseconds || end($elapsedSeries) - $durationMicroseconds > $maxGapMicroseconds || ((int) end($wallSeries) - $runStartedTimestamp) * 1000000 + $clockSkewMicroseconds < $durationMicroseconds) throw new RuntimeException('endurance evidence did not reach or cover the required duration');
$minimumSamples = $minimumSamplesFor($durationMicroseconds, $maxGapMicroseconds);
if ($iteration < $minimumSamples) throw new RuntimeException('endurance evidence has too few continuous samples');
$gaps = [];
for ($index = 1; $index < count($elapsedSeries); ++$index) {
    $gap = $elapsedSeries[$index] - $elapsedSeries[$index - 1];
    $gaps[] = $gap;
    if ($gap <= 0 || $gap > $maxGapMicroseconds) throw new RuntimeException('endurance sample gap exceeded threshold');
}

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
foreach ($counterMetrics as $zeroDeltaMetric) if (!$sameCounter(end($metricsSeries)['values'][$zeroDeltaMetric], $metricsSeries[0]['values'][$zeroDeltaMetric], $zeroDeltaMetric)) throw new RuntimeException($zeroDeltaMetric . ' delta must be zero');
if ($percentile($values('event_loop_lag_ms'), 0.99) > $thresholds['max_event_loop_lag_p99_ms'] || $percentile($values('pool_wait_ms'), 0.99) > $thresholds['max_pool_wait_p99_ms']) throw new RuntimeException('event-loop or pool-wait p99 failed');

$evidenceHash = hash('sha256', $evidenceBytes);
if (!is_string($evidenceHash) || ($summary['passed'] ?? null) !== true || ($summary['evidence']['sha256'] ?? null) !== $evidenceHash || ($summary['evidence']['final_record_sha256'] ?? null) !== $previousHash
    || ($summary['samples'] ?? null) !== $iteration || ($summary['checks']['failed'] ?? null) !== 0 || ($summary['checks']['total'] ?? null) !== $iteration * count($targetMap)
    || !is_int($summary['duration_microseconds'] ?? null) || $summary['duration_microseconds'] !== end($elapsedSeries)
    || ($summary['started_at'] ?? null) !== $runStartedAt || !is_array($summary['runtime_identity'] ?? null) || $canonicalJson($summary['runtime_identity']) !== $runtimeIdentityBaseline) {
    throw new RuntimeException('summary does not bind the verified endurance evidence');
}
echo 'SandIAM endurance evidence verified: samples=' . $iteration . ' duration_microseconds=' . end($elapsedSeries) . ' final_record_sha256=' . $previousHash . PHP_EOL;
