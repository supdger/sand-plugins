<?php

declare(strict_types=1);

/**
 * Run the final-candidate endurance probe without starting or changing services.
 * Secrets are referenced by environment-variable name and never written to evidence.
 */

$options = getopt('', ['plan:', 'output::', 'validate-only', 'self-test-io']);
$selfTestIo = array_key_exists('self-test-io', $options);
if (!$selfTestIo) {
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
if (!is_array($plan) || ($plan['schema'] ?? null) !== 'sand-iam.endurance-plan/v2') throw new RuntimeException('invalid endurance plan schema; v1 evidence cannot pass release verification');

/** @param list<string> $required @param list<string> $allowed */
$expectKeys = static function (array $value, array $required, array $allowed, string $label): void {
    $missing = array_values(array_diff($required, array_keys($value)));
    $unknown = array_values(array_diff(array_keys($value), $allowed));
    if ($missing !== []) throw new RuntimeException($label . ' is missing keys: ' . implode(', ', $missing));
    if ($unknown !== []) throw new RuntimeException($label . ' has unknown keys: ' . implode(', ', $unknown));
};

$expectKeys($plan, ['schema', 'candidate', 'acceptance_run_id', 'environment', 'collector', 'duration_seconds', 'interval_seconds', 'max_jitter_seconds', 'max_clock_skew_seconds', 'runtime_identity', 'request_timeout_ms', 'allow_loopback_http', 'approved_hosts', 'targets', 'metrics', 'thresholds'], ['schema', 'candidate', 'acceptance_run_id', 'environment', 'collector', 'duration_seconds', 'interval_seconds', 'max_jitter_seconds', 'max_clock_skew_seconds', 'runtime_identity', 'request_timeout_ms', 'allow_loopback_http', 'approved_hosts', 'targets', 'metrics', 'thresholds'], 'plan');
$candidate = $plan['candidate'];
if (!is_array($candidate)) throw new RuntimeException('candidate must be an object');
$expectKeys($candidate, ['version', 'archive_sha256', 'archive_bytes', 'artifact_manifest_sha256', 'source_revision'], ['version', 'archive_sha256', 'archive_bytes', 'artifact_manifest_sha256', 'source_revision'], 'candidate');
if (preg_match('/^\d+\.\d+\.\d+$/', (string) $candidate['version']) !== 1
    || preg_match('/^[0-9a-f]{64}$/', (string) $candidate['archive_sha256']) !== 1
    || preg_match('/^[0-9a-f]{64}$/', (string) $candidate['artifact_manifest_sha256']) !== 1
    || !is_int($candidate['archive_bytes']) || $candidate['archive_bytes'] < 1
    || !is_array($candidate['source_revision']) || !isset($candidate['source_revision']['commit'], $candidate['source_revision']['tree'])
    || preg_match('/^[0-9a-f]{40,64}$/', (string) $candidate['source_revision']['commit']) !== 1
    || preg_match('/^[0-9a-f]{40,64}$/', (string) $candidate['source_revision']['tree']) !== 1) {
    throw new RuntimeException('candidate identity is incomplete');
}
if (!is_string($plan['acceptance_run_id']) || preg_match('/^sand_iam_endurance_[a-f0-9]{16}$/', $plan['acceptance_run_id']) !== 1) throw new RuntimeException('acceptance_run_id is invalid');
foreach (['environment' => ['id'], 'collector' => ['id', 'version']] as $label => $required) {
    if (!is_array($plan[$label])) throw new RuntimeException($label . ' must be an object');
    $expectKeys($plan[$label], $required, $required, $label);
    foreach ($required as $field) if (!is_string($plan[$label][$field]) || preg_match('/^[A-Za-z0-9._:-]{3,128}$/', $plan[$label][$field]) !== 1) throw new RuntimeException($label . '.' . $field . ' is invalid');
}
if (!is_int($plan['duration_seconds']) || $plan['duration_seconds'] < 28800) throw new RuntimeException('endurance duration must be at least 28800 seconds');
if ($plan['duration_seconds'] > intdiv(PHP_INT_MAX, 1000000)) throw new RuntimeException('endurance duration exceeds exact microsecond range');
if (!is_int($plan['interval_seconds']) || $plan['interval_seconds'] < 10 || $plan['interval_seconds'] > 300) throw new RuntimeException('interval_seconds must be between 10 and 300');
if (!is_int($plan['max_jitter_seconds']) || $plan['max_jitter_seconds'] < 0 || $plan['max_jitter_seconds'] > 60) throw new RuntimeException('max_jitter_seconds must be an integer between 0 and 60');
if (!is_int($plan['max_clock_skew_seconds']) || $plan['max_clock_skew_seconds'] < 1 || $plan['max_clock_skew_seconds'] > 5) throw new RuntimeException('max_clock_skew_seconds must be an integer between 1 and 5');
if (!is_array($plan['runtime_identity'])) throw new RuntimeException('runtime_identity must be an object');
$runtimeIdentityFields = ['boot_id', 'process_group_id', 'supervisor_restart_total'];
$expectKeys($plan['runtime_identity'], $runtimeIdentityFields, $runtimeIdentityFields, 'runtime_identity');
foreach ($plan['runtime_identity'] as $name => $pointer) if (!is_string($pointer) || !str_starts_with($pointer, '/')) throw new RuntimeException('runtime_identity.' . $name . ' must be a JSON pointer');
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
    $keys = ['id', 'category', 'url', 'method', 'effect', 'authorization_env', 'body', 'expected_status', 'json_expect', 'max_p99_ms', 'audit_context', 'audit_response'];
    $expectKeys($target, ['id', 'category', 'url', 'method', 'effect', 'authorization_env', 'body', 'expected_status', 'json_expect', 'max_p99_ms', 'audit_context', 'audit_response'], $keys, 'target');
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
    $businessCategory = in_array($category, ['allow', 'deny', 'revoked'], true);
    if ($businessCategory) {
        if (!is_array($target['audit_context'] ?? null)) throw new RuntimeException($category . ' probe must declare audit_context');
        $expectKeys($target['audit_context'], ['decision', 'subject', 'scope', 'candidate', 'side_effect_pointer', 'business_audit_ref_pointer'], ['decision', 'subject', 'scope', 'candidate', 'side_effect_pointer', 'business_audit_ref_pointer'], 'audit_context');
        $expectedDecision = $category === 'allow' ? 'allow' : $category;
        if ($target['audit_context']['decision'] !== $expectedDecision || $target['audit_context']['candidate'] !== $candidate['archive_sha256']) throw new RuntimeException($category . ' audit context has an invalid decision or candidate');
        foreach (['subject', 'scope'] as $field) if (!is_string($target['audit_context'][$field]) || preg_match('/^[A-Za-z0-9._:-]{3,128}$/', $target['audit_context'][$field]) !== 1) throw new RuntimeException($category . ' audit context ' . $field . ' is invalid');
        foreach (['side_effect_pointer', 'business_audit_ref_pointer'] as $field) if (!is_string($target['audit_context'][$field]) || !str_starts_with($target['audit_context'][$field], '/')) throw new RuntimeException($category . ' audit context ' . $field . ' is invalid');
        if ($target['audit_response'] !== null) throw new RuntimeException($category . ' cannot declare audit_response');
    } elseif ($target['audit_context'] !== null) throw new RuntimeException($category . ' cannot declare audit_context');
    if ($category === 'audit') {
        if (!is_array($target['audit_response'] ?? null)) throw new RuntimeException('audit probe must declare audit_response');
        $auditFields = ['records_pointer', 'request_id_pointer', 'decision_pointer', 'subject_pointer', 'scope_pointer', 'candidate_pointer', 'acceptance_run_id_pointer', 'business_audit_ref_pointer', 'service_audit_ref_pointer', 'side_effect_pointer'];
        $expectKeys($target['audit_response'], $auditFields, $auditFields, 'audit_response');
        foreach ($target['audit_response'] as $field => $pointer) if (!is_string($pointer) || !str_starts_with($pointer, '/')) throw new RuntimeException('audit_response.' . $field . ' is invalid');
    } elseif ($target['audit_response'] !== null) throw new RuntimeException($category . ' cannot declare audit_response');
    if (!is_int($target['max_p99_ms']) || $target['max_p99_ms'] < 1) throw new RuntimeException($id . ' max_p99_ms is invalid');
}
$missingCategories = array_values(array_diff($requiredCategories, array_keys($seenCategories)));
if ($missingCategories !== [] || count($seenCategories) !== count($requiredCategories)) throw new RuntimeException('targets must contain each required category exactly once: ' . implode(', ', $missingCategories));

$requiredMetrics = ['rss_bytes', 'fd_count', 'queue_depth', 'unrecoverable_backlog', 'unrecoverable_backlog_total', 'security_operation_retention_backlog', 'auth_rate_limit_retention_backlog', 'worker_exit_total', 'worker_restart_total', 'unauthorized_allow_total', 'data_corruption_total', 'event_loop_lag_ms', 'pool_wait_ms'];
if (!is_array($plan['metrics'])) throw new RuntimeException('metrics must be an object');
$expectKeys($plan['metrics'], $requiredMetrics, $requiredMetrics, 'metrics');
foreach ($plan['metrics'] as $name => $pointer) {
    if (!is_string($pointer) || !str_starts_with($pointer, '/')) throw new RuntimeException('metrics.' . $name . ' must be a JSON pointer');
}
$requiredThresholds = ['max_error_rate', 'max_gap_seconds', 'max_rss_bytes', 'max_rss_slope_bytes_per_hour', 'max_fd_count', 'max_fd_slope_per_hour', 'max_queue_depth', 'max_unrecoverable_backlog', 'max_unrecoverable_backlog_delta', 'max_security_operation_retention_backlog', 'max_auth_rate_limit_retention_backlog', 'max_worker_exit_delta', 'max_worker_restart_delta', 'max_unauthorized_allow_delta', 'max_data_corruption_delta', 'max_event_loop_lag_p99_ms', 'max_pool_wait_p99_ms'];
if (!is_array($plan['thresholds'])) throw new RuntimeException('thresholds must be an object');
$expectKeys($plan['thresholds'], $requiredThresholds, $requiredThresholds, 'thresholds');
foreach ($plan['thresholds'] as $name => $value) {
    if (!(is_int($value) || is_float($value)) || !is_finite((float) $value) || $value < 0) throw new RuntimeException('thresholds.' . $name . ' must be finite and non-negative');
}
if ((float) $plan['thresholds']['max_gap_seconds'] !== (float) ($plan['interval_seconds'] + $plan['max_jitter_seconds'])) throw new RuntimeException('max_gap_seconds must equal interval_seconds plus max_jitter_seconds');
if (!is_int($plan['thresholds']['max_gap_seconds'])) throw new RuntimeException('max_gap_seconds must be an integer');
if ($plan['thresholds']['max_error_rate'] != 0) throw new RuntimeException('release endurance max_error_rate must be zero');
if ($plan['thresholds']['max_unrecoverable_backlog'] != 0 || $plan['thresholds']['max_unrecoverable_backlog_delta'] != 0 || $plan['thresholds']['max_security_operation_retention_backlog'] != 0 || $plan['thresholds']['max_auth_rate_limit_retention_backlog'] != 0 || $plan['thresholds']['max_worker_exit_delta'] != 0
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
$canonicalJson = static fn (mixed $value): string => json_encode($canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
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
$identity = [
    'plan_sha256' => hash('sha256', $planBytes), 'acceptance_run_id' => $plan['acceptance_run_id'], 'candidate' => $candidate,
    'environment' => $plan['environment'], 'collector' => $plan['collector'],
    'thresholds_sha256' => hash('sha256', $canonicalJson($plan['thresholds'])), 'endpoints_sha256' => hash('sha256', $canonicalJson($plan['targets'])), 'runtime_identity_sha256' => hash('sha256', $canonicalJson($plan['runtime_identity'])),
];
$valueKind = static function (mixed $value): string {
    return $value === null ? 'null' : (is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : (is_float($value) ? 'float' : (is_string($value) ? 'string' : (array_is_list($value) ? 'array' : 'object')))));
};
$assertionResult = static function (mixed $document, string $pointer, mixed $expected) use ($jsonPointer, $canonicalJson, $valueKind): array {
    $expectedKind = $valueKind($expected); $expectedHash = hash('sha256', $canonicalJson($expected));
    $unavailableHash = hash('sha256', $canonicalJson(['unavailable' => true]));
    if (!is_array($document)) return ['pointer' => $pointer, 'expected_kind' => $expectedKind, 'expected_sha256' => $expectedHash, 'actual_kind' => 'unavailable', 'actual' => null, 'actual_sha256' => $unavailableHash, 'result' => 'response_not_object_or_array'];
    try { $actual = $jsonPointer($document, $pointer); } catch (RuntimeException) {
        return ['pointer' => $pointer, 'expected_kind' => $expectedKind, 'expected_sha256' => $expectedHash, 'actual_kind' => 'unavailable', 'actual' => null, 'actual_sha256' => $unavailableHash, 'result' => 'pointer_missing'];
    }
    $safe = $actual === null || is_bool($actual) || is_int($actual) || is_float($actual);
    $sensitive = preg_match('/(?:password|client_secret|secret|token|otp|code)/i', $pointer) === 1;
    return ['pointer' => $pointer, 'expected_kind' => $expectedKind, 'expected_sha256' => $expectedHash, 'actual_kind' => $valueKind($actual), 'actual' => $safe && !$sensitive ? $actual : null, 'actual_sha256' => hash('sha256', $canonicalJson($actual)), 'result' => $actual === $expected ? 'matched' : 'mismatch'];
};
}

/** @param resource $handle */
$writeFully = static function ($handle, string $bytes, string $label, ?callable $writer = null): void {
    $offset = 0; $length = strlen($bytes);
    while ($offset < $length) {
        $written = $writer === null ? @fwrite($handle, substr($bytes, $offset)) : $writer($handle, substr($bytes, $offset));
        if (!is_int($written) || $written <= 0) throw new RuntimeException($label . ' write failed or returned zero bytes');
        $offset += $written;
    }
};
/** @param resource $handle */
$flushDurably = static function ($handle, string $label, ?callable $flush = null, ?callable $sync = null): void {
    if (!(($flush ?? static fn ($stream): bool => @fflush($stream))($handle))) throw new RuntimeException($label . ' flush failed');
    if (function_exists('fsync') && !(($sync ?? static fn ($stream): bool => @fsync($stream))($handle))) throw new RuntimeException($label . ' fsync failed');
};
$assertAbsentRegularTarget = static function (string $path, string $label): void {
    $stat = @lstat($path);
    if ($stat === false) return;
    if (is_link($path)) throw new RuntimeException($label . ' must not be a symlink');
    if (($stat['mode'] & 0170000) !== 0100000) throw new RuntimeException($label . ' must be a regular file when present');
    throw new RuntimeException('refusing to overwrite ' . $label);
};
$sameFile = static function (array $expected, array|false $actual): bool {
    return is_array($actual) && $actual['dev'] === $expected['dev'] && $actual['ino'] === $expected['ino'];
};
$syncDirectory = static function (string $directory, string $label): void {
    if (!function_exists('fsync')) throw new RuntimeException($label . ' directory fsync is unavailable');
    $handle = @fopen($directory, 'r');
    if (!is_resource($handle)) throw new RuntimeException($label . ' directory cannot be opened for fsync');
    try { if (!@fsync($handle)) throw new RuntimeException($label . ' directory fsync failed'); } finally { fclose($handle); }
};
$removeOwnedFile = static function (string $path, array $owned, string $label) use ($sameFile, $syncDirectory): bool {
    if (!$sameFile($owned, @lstat($path)) || !@unlink($path)) return false;
    try { $syncDirectory(dirname($path), $label . ' cleanup'); } catch (Throwable) { }
    return true;
};
$publishSummary = static function (string $summaryPath, string $summaryBytes, ?callable $writer = null, ?callable $flush = null, ?callable $sync = null, ?callable $linker = null, ?callable $directorySync = null) use ($assertAbsentRegularTarget, $writeFully, $flushDurably, $sameFile, $syncDirectory, $removeOwnedFile): array {
    $assertAbsentRegularTarget($summaryPath, 'endurance summary');
    $summaryTemp = null; $summaryHandle = null; $ownedTemp = null; $published = false;
    try {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $candidate = dirname($summaryPath) . '/.' . basename($summaryPath) . '.tmp-' . bin2hex(random_bytes(12));
            $handle = @fopen($candidate, 'xb');
            if (is_resource($handle)) { $summaryTemp = $candidate; $summaryHandle = $handle; $ownedTemp = fstat($handle); break; }
        }
        if (!is_resource($summaryHandle) || !is_array($ownedTemp) || !chmod($summaryTemp, 0600)) throw new RuntimeException('cannot create endurance summary temporary file');
        $writeFully($summaryHandle, $summaryBytes, 'endurance summary', $writer);
        $flushDurably($summaryHandle, 'endurance summary', $flush, $sync);
        if (!$sameFile($ownedTemp, fstat($summaryHandle)) || !chmod($summaryTemp, 0444)) throw new RuntimeException('endurance summary temporary file changed');
        if (!(($linker ?? static fn (string $from, string $to): bool => @link($from, $to))($summaryTemp, $summaryPath))) throw new RuntimeException('cannot atomically publish endurance summary without replacement');
        $published = true;
        if (!$sameFile($ownedTemp, @lstat($summaryPath)) || !@unlink($summaryTemp)) throw new RuntimeException('endurance summary publish ownership check failed');
        $summaryTemp = null;
        ($directorySync ?? $syncDirectory)(dirname($summaryPath), 'endurance summary');
        return $ownedTemp;
    } catch (Throwable $exception) {
        if ($published && is_array($ownedTemp)) $removeOwnedFile($summaryPath, $ownedTemp, 'endurance summary');
        throw new RuntimeException('endurance summary publication failed');
    } finally {
        if (is_resource($summaryHandle)) fclose($summaryHandle);
        if (is_string($summaryTemp) && is_array($ownedTemp) && $sameFile($ownedTemp, @lstat($summaryTemp))) @unlink($summaryTemp);
    }
};
$finalizeEvidence = static function ($evidenceHandle, string $evidencePath, string $summaryPath, string $summaryBytes, ?callable $writer = null, ?callable $flush = null, ?callable $sync = null, ?callable $linker = null, ?callable $directorySync = null, ?callable $chmodder = null, ?callable $cleanup = null) use ($publishSummary, $flushDurably, $removeOwnedFile): void {
    $chmodder ??= static fn (string $path, int $mode): bool => @chmod($path, $mode);
    $cleanup ??= $removeOwnedFile;
    $published = null;
    $restoreWritable = static function () use ($evidencePath, $evidenceHandle, $flushDurably, $flush, $sync, $chmodder): bool {
        if (!$chmodder($evidencePath, 0600)) return false;
        try { $flushDurably($evidenceHandle, 'endurance evidence rollback', $flush, $sync); } catch (Throwable) { return false; }
        return true;
    };
    try {
        // The JSONL stays writable and exclusively locked until the summary is durable.
        $published = $publishSummary($summaryPath, $summaryBytes, $writer, $flush, $sync, $linker, $directorySync);
        if (!$chmodder($evidencePath, 0444)) {
            $restoreWritable();
            $cleanup($summaryPath, $published, 'endurance summary');
            throw new RuntimeException('cannot seal endurance evidence');
        }
        try {
            $flushDurably($evidenceHandle, 'endurance evidence', $flush, $sync);
        } catch (Throwable) {
            // A summary may remain only if the visible evidence is durably writable.
            if (!$restoreWritable()) $cleanup($summaryPath, $published, 'endurance summary');
            throw new RuntimeException('cannot finalize endurance evidence');
        }
    } catch (Throwable $exception) {
        if (is_array($published)) {
            // Before final JSONL fsync, a failure must not leave a sealed pair behind.
            $stat = @lstat($evidencePath);
            if (is_array($stat) && (($stat['mode'] & 0222) === 0)) {
                if (!$restoreWritable()) $cleanup($summaryPath, $published, 'endurance summary');
            }
        }
        throw new RuntimeException('endurance evidence finalization failed');
    }
};
$normalizeTransportError = static function (int $code): ?array {
    if ($code === 0) return null;
    $codes = [6 => ['resolve', 'curl_6'], 7 => ['connect', 'curl_7'], 23 => ['write', 'curl_23'], 28 => ['timeout', 'curl_28'], 35 => ['tls', 'curl_35'], 52 => ['empty_response', 'curl_52'], 56 => ['receive', 'curl_56']];
    [$category, $stableCode] = $codes[$code] ?? ['other', 'curl_other'];
    return ['category' => $category, 'code' => $stableCode];
};
if ($selfTestIo) {
    $directory = sys_get_temp_dir() . '/sand-iam-endurance-io-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700)) throw new RuntimeException('cannot create I/O self-test directory');
    $path = $directory . '/summary.json';
    try {
        $writerPath = $directory . '/writer.jsonl'; $handle = fopen($writerPath, 'xb'); if (!is_resource($handle) || !flock($handle, LOCK_EX)) throw new RuntimeException('cannot exclusively lock writer self-test');
        $writeFully($handle, 'abcdef', 'self-test', static fn ($stream, string $bytes): int => @fwrite($stream, substr($bytes, 0, 2)));
        foreach ([static fn (): int => 0, static fn (): bool => false] as $failure) { $failed = false; try { $writeFully($handle, 'x', 'self-test', $failure); } catch (RuntimeException) { $failed = true; } if (!$failed) throw new RuntimeException('write failure self-test did not fail'); }
        foreach ([static fn (): bool => false, static fn (): bool => true] as $flush) { $failed = false; try { $flushDurably($handle, 'self-test', $flush, static fn (): bool => false); } catch (RuntimeException) { $failed = true; } if (!$failed) throw new RuntimeException('flush/fsync self-test did not fail'); }
        flock($handle, LOCK_UN); fclose($handle);
        if (file_get_contents($writerPath) !== 'abcdef') throw new RuntimeException('partial write self-test lost bytes');
        if ($normalizeTransportError(28) !== ['category' => 'timeout', 'code' => 'curl_28'] || $normalizeTransportError(999) !== ['category' => 'other', 'code' => 'curl_other'] || str_contains(json_encode($normalizeTransportError(28), JSON_THROW_ON_ERROR), 'secret')) throw new RuntimeException('transport error redaction self-test failed');
        $publishSummary($path, "{}\n");
        foreach ([$path, $directory . '/symlink.json'] as $existing) { if ($existing !== $path) symlink($path, $existing); $failed = false; try { $publishSummary($existing, "{}\n"); } catch (RuntimeException) { $failed = true; } if (!$failed) throw new RuntimeException('existing/symlink publish self-test did not fail'); }
        $sealedEvidence = $directory . '/sealed.jsonl'; $sealedSummary = $sealedEvidence . '.summary.json'; $sealedHandle = fopen($sealedEvidence, 'xb');
        if (!is_resource($sealedHandle) || !chmod($sealedEvidence, 0600) || !flock($sealedHandle, LOCK_EX)) throw new RuntimeException('cannot create sealed finalization self-test evidence');
        try {
            $writeFully($sealedHandle, "{}\n", 'sealed finalization self-test');
            $finalizeEvidence($sealedHandle, $sealedEvidence, $sealedSummary, "{}\n");
            clearstatcache(true, $sealedEvidence); clearstatcache(true, $sealedSummary);
            if ((fileperms($sealedEvidence) & 0222) !== 0 || (fileperms($sealedSummary) & 0222) !== 0) throw new RuntimeException('successful finalization self-test did not seal both files');
        } finally { @flock($sealedHandle, LOCK_UN); fclose($sealedHandle); }
        $runFinalizationFailure = static function (string $label, ?callable $sync = null, ?callable $linker = null, ?callable $directorySync = null, ?callable $chmodder = null, ?callable $cleanup = null) use ($directory, $finalizeEvidence, $writeFully): void {
            $evidence = $directory . '/' . $label . '.jsonl'; $summary = $evidence . '.summary.json';
            $handle = fopen($evidence, 'xb');
            if (!is_resource($handle) || !chmod($evidence, 0600) || !flock($handle, LOCK_EX)) throw new RuntimeException('cannot create finalization self-test evidence');
            try {
                $writeFully($handle, "{}\n", 'finalization self-test');
                $failed = false;
                try { $finalizeEvidence($handle, $evidence, $summary, "{}\n", null, null, $sync, $linker, $directorySync, $chmodder, $cleanup); } catch (RuntimeException) { $failed = true; }
                if (!$failed) throw new RuntimeException($label . ' finalization self-test did not fail');
                clearstatcache(true, $evidence); clearstatcache(true, $summary);
                $writable = is_file($evidence) && ((fileperms($evidence) & 0222) !== 0);
                if (!$writable && is_file($summary) && !is_link($summary)) throw new RuntimeException($label . ' left a verifier-acceptable sealed pair after failure');
            } finally {
                @flock($handle, LOCK_UN); fclose($handle);
                foreach ([$evidence, $summary] as $fixture) if (is_file($fixture) && !is_link($fixture)) { @chmod($fixture, 0600); @unlink($fixture); }
            }
        };
        $runFinalizationFailure('summary-link', null, static fn (string $from, string $to): bool => false);
        $runFinalizationFailure('summary-directory-sync', null, null, static function (): void { throw new RuntimeException('injected directory fsync failure'); });
        $runFinalizationFailure('evidence-chmod', null, null, null, static fn (string $file, int $mode): bool => $mode !== 0444 && @chmod($file, $mode));
        $syncCalls = 0;
        $runFinalizationFailure('evidence-final-fsync', static function ($stream) use (&$syncCalls): bool { ++$syncCalls; return $syncCalls !== 2 && @fsync($stream); });
        $rollbackSyncCalls = 0;
        $runFinalizationFailure('rollback-chmod', static function ($stream) use (&$rollbackSyncCalls): bool { ++$rollbackSyncCalls; return $rollbackSyncCalls !== 2 && @fsync($stream); }, null, null, static fn (string $file, int $mode): bool => $mode !== 0600 && @chmod($file, $mode));
        $runFinalizationFailure('cleanup-failure', null, null, null, static fn (string $file, int $mode): bool => $mode !== 0444 && @chmod($file, $mode), static fn (): bool => false);
        echo "SandIAM endurance I/O self-test passed\n";
    } finally { foreach (glob($directory . '/*') ?: [] as $fixture) @unlink($fixture); @rmdir($directory); }
    exit(0);
}
$sideEffectByDecision = ['allow' => 'applied', 'deny' => 'rejected', 'revoked' => 'revoked'];
$serviceAuditReferences = [];

$evidenceHandle = fopen($output, 'xb');
if (!is_resource($evidenceHandle) || !chmod($output, 0600) || !@flock($evidenceHandle, LOCK_EX)) throw new RuntimeException('cannot create and exclusively lock endurance evidence');
$startedWall = time();
$startedMono = hrtime(true);
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
$runStartedAt = gmdate('Y-m-d\TH:i:s\Z', $startedWall);
$previousHash = str_repeat('0', 64);
$iteration = 0;
$latencies = [];
$metricsSeries = [];
$failedChecks = 0;
$totalChecks = 0;
$sampleTimes = [];
$sampleWallTimes = [];
$metricBaseline = null;
$runtimeIdentityBaseline = null;
$runtimeIdentityBaselineCanonical = null;
$acceptanceRunId = $plan['acceptance_run_id'];
$auditTarget = null;
foreach ($plan['targets'] as $target) if ($target['category'] === 'audit') $auditTarget = $target;
if (!is_array($auditTarget)) throw new RuntimeException('audit target is unavailable after plan validation');

try {
    do {
        $iterationStarted = hrtime(true);
        $multi = curl_multi_init();
        $handles = [];
        $bodies = [];
        $requestIds = [];
        foreach ($plan['targets'] as $target) {
            $id = $target['id'];
            if ($target['category'] === 'audit') continue;
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
        $iterationRuntimeIdentity = null;
        foreach ($handles as $id => [$curl, $target]) {
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $elapsedMs = (float) curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000;
            $transportError = $normalizeTransportError((int) curl_errno($curl));
            $passed = $transportError === null && $status === $target['expected_status'];
            $decoded = null;
            try { $decoded = json_decode($bodies[$id], true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { $passed = false; }
            $assertions = [];
            foreach ($target['json_expect'] as $pointer => $expected) {
                $assertion = $assertionResult($decoded, $pointer, $expected);
                $assertions[$pointer] = $assertion;
                if ($assertion['result'] !== 'matched') $passed = false;
            }
            if (is_array($decoded)) {
                if ($target['category'] === 'metrics') {
                    $iterationMetrics = [];
                    foreach ($plan['metrics'] as $name => $pointer) {
                        try { $value = $jsonPointer($decoded, $pointer); } catch (RuntimeException) { $value = null; }
                        try { in_array($name, $counterMetrics, true) || in_array($name, $zeroCounterMetrics, true) ? $counterString($value, $name) : $number($value, $name); } catch (RuntimeException) { $passed = false; }
                        $iterationMetrics[$name] = $value;
                    }
                    $iterationRuntimeIdentity = [];
                    foreach ($plan['runtime_identity'] as $name => $pointer) {
                        try { $value = $jsonPointer($decoded, $pointer); } catch (RuntimeException) { $value = null; }
                        $iterationRuntimeIdentity[$name] = $value;
                    }
                }
            }
            $businessObservation = null;
            if (is_array($target['audit_context'])) {
                try {
                    $businessObservation = [
                        'decision' => $target['audit_context']['decision'], 'subject' => $target['audit_context']['subject'], 'scope' => $target['audit_context']['scope'], 'candidate' => $target['audit_context']['candidate'],
                        'side_effect' => $jsonPointer($decoded, $target['audit_context']['side_effect_pointer']),
                        'business_audit_ref' => $jsonPointer($decoded, $target['audit_context']['business_audit_ref_pointer']),
                    ];
                    if ($businessObservation['side_effect'] !== $sideEffectByDecision[$businessObservation['decision']] || !is_string($businessObservation['business_audit_ref']) || preg_match('/^[A-Za-z0-9._:-]{3,128}$/', $businessObservation['business_audit_ref']) !== 1) $passed = false;
                } catch (RuntimeException) { $passed = false; }
            }
            $probeResults[$id] = array_filter(['category' => $target['category'], 'request_id' => $requestIds[$id], 'status' => $status, 'elapsed_ms' => round($elapsedMs, 3), 'response_sha256' => hash('sha256', $bodies[$id]), 'assertions' => $assertions, 'passed' => $passed, 'transport_error' => $transportError, 'business_observation' => $businessObservation], static fn (mixed $value): bool => $value !== null);
            $latencies[$id][] = $elapsedMs;
            ++$totalChecks;
            if (!$passed) ++$failedChecks;
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
        }
        curl_multi_close($multi);
        $auditRequests = [];
        foreach ($plan['targets'] as $target) if (is_array($target['audit_context'])) {
            $observation = $probeResults[$target['id']]['business_observation'] ?? null;
            if (!is_array($observation)) throw new RuntimeException('business observation is unavailable before audit query');
            $auditRequests[] = ['request_id' => $probeResults[$target['id']]['request_id'], ...$observation, 'acceptance_run_id' => $acceptanceRunId];
        }
        $auditId = $auditTarget['id']; $requestIds[$auditId] = 'sand-iam-endurance-' . bin2hex(random_bytes(12));
        $auditBody = array_merge($auditTarget['body'] ?? [], ['acceptance_run_id' => $acceptanceRunId, 'requests' => $auditRequests]);
        $auditCurl = curl_init($auditTarget['url']);
        if ($auditCurl === false) throw new RuntimeException('cannot initialize curl for audit');
        $auditHeaders = ['Accept: application/json', 'Content-Type: application/json', 'X-Request-Id: ' . $requestIds[$auditId], 'X-Acceptance-Run-Id: ' . $acceptanceRunId];
        if (is_string($auditTarget['authorization_env'])) { $authorization = getenv($auditTarget['authorization_env']); if (!is_string($authorization) || trim($authorization) === '') throw new RuntimeException('missing authorization environment variable for audit'); $auditHeaders[] = 'Authorization: ' . trim($authorization); }
        $auditBodyBytes = json_encode($auditBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); $auditResponse = '';
        curl_setopt_array($auditCurl, [CURLOPT_CUSTOMREQUEST => $auditTarget['method'], CURLOPT_HTTPHEADER => $auditHeaders, CURLOPT_CONNECTTIMEOUT_MS => min(3000, $plan['request_timeout_ms']), CURLOPT_TIMEOUT_MS => $plan['request_timeout_ms'], CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_POSTFIELDS => $auditBodyBytes, CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$auditResponse): int { if (strlen($auditResponse) + strlen($chunk) > 1048576) return 0; $auditResponse .= $chunk; return strlen($chunk); }]);
        curl_exec($auditCurl); $auditStatus = (int) curl_getinfo($auditCurl, CURLINFO_RESPONSE_CODE); $auditElapsed = (float) curl_getinfo($auditCurl, CURLINFO_TOTAL_TIME) * 1000; $auditTransportError = $normalizeTransportError((int) curl_errno($auditCurl)); $auditPassed = $auditTransportError === null && $auditStatus === $auditTarget['expected_status'];
        try { $auditDecoded = json_decode($auditResponse, true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { $auditDecoded = null; $auditPassed = false; }
        $auditAssertions = []; foreach ($auditTarget['json_expect'] as $pointer => $expected) { $assertion = $assertionResult($auditDecoded, $pointer, $expected); $auditAssertions[$pointer] = $assertion; if ($assertion['result'] !== 'matched') $auditPassed = false; }
        $auditRecords = [];
        try {
            $responseContract = $auditTarget['audit_response']; $records = $jsonPointer($auditDecoded, $responseContract['records_pointer']);
            if (!is_array($records) || !array_is_list($records) || count($records) !== count($auditRequests)) throw new RuntimeException('audit response records are incomplete');
            foreach ($auditRequests as $request) {
                $matches = [];
                foreach ($records as $raw) if (is_array($raw)) {
                    $observed = []; foreach (['request_id', 'decision', 'subject', 'scope', 'candidate', 'acceptance_run_id', 'business_audit_ref', 'service_audit_ref', 'side_effect'] as $field) $observed[$field] = $jsonPointer($raw, $responseContract[$field . '_pointer']);
                    if ($observed['request_id'] === $request['request_id'] && $observed['decision'] === $request['decision'] && $observed['subject'] === $request['subject'] && $observed['scope'] === $request['scope'] && $observed['candidate'] === $request['candidate'] && $observed['acceptance_run_id'] === $acceptanceRunId && $observed['business_audit_ref'] === $request['business_audit_ref'] && $observed['side_effect'] === $sideEffectByDecision[$observed['decision']] && is_string($observed['service_audit_ref']) && preg_match('/^[A-Za-z0-9._:-]{3,128}$/', $observed['service_audit_ref']) === 1 && !isset($serviceAuditReferences[$observed['service_audit_ref']])) $matches[] = $observed;
                }
                if (count($matches) !== 1) throw new RuntimeException('audit response has missing, stale, duplicate, or mismatched business correlation');
                $serviceAuditReferences[$matches[0]['service_audit_ref']] = true; $auditRecords[] = $matches[0];
            }
        } catch (RuntimeException) { $auditPassed = false; }
        $probeResults[$auditId] = ['category' => 'audit', 'request_id' => $requestIds[$auditId], 'status' => $auditStatus, 'elapsed_ms' => round($auditElapsed, 3), 'response_sha256' => hash('sha256', $auditResponse), 'assertions' => $auditAssertions, 'passed' => $auditPassed, 'transport_error' => $auditTransportError, 'audit_observation' => ['requests' => $auditRequests, 'records' => $auditRecords]];
        $latencies[$auditId][] = $auditElapsed; ++$totalChecks; if (!$auditPassed) ++$failedChecks; curl_close($auditCurl);
        $elapsedMicroseconds = intdiv(hrtime(true) - $startedMono, 1000);
        $wallTimestamp = time(); $wallTime = gmdate('Y-m-d\TH:i:s\Z', $wallTimestamp);
        if ($elapsedMicroseconds < 0 || !is_array($iterationMetrics) || !is_array($iterationRuntimeIdentity)) throw new RuntimeException('metrics or sample time is unavailable');
        foreach ($iterationMetrics as $name => $value) in_array($name, $counterMetrics, true) || in_array($name, $zeroCounterMetrics, true) ? $counterString($value, $name) : $number($value, $name);
        foreach ($zeroCounterMetrics as $zeroCounter) if ($counterString($iterationMetrics[$zeroCounter], $zeroCounter) !== '0') throw new RuntimeException($zeroCounter . ' must be zero in every sample');
        foreach (['boot_id', 'process_group_id'] as $field) if (!is_string($iterationRuntimeIdentity[$field]) || preg_match('/^[A-Za-z0-9._:-]{3,128}$/', $iterationRuntimeIdentity[$field]) !== 1) throw new RuntimeException('runtime identity is invalid: ' . $field);
        $counterString($iterationRuntimeIdentity['supervisor_restart_total'], 'supervisor_restart_total');
        $runtimeIdentityCanonical = $canonicalJson($iterationRuntimeIdentity);
        if ($metricBaseline === null) { $metricBaseline = $iterationMetrics; $runtimeIdentityBaseline = $iterationRuntimeIdentity; $runtimeIdentityBaselineCanonical = $runtimeIdentityCanonical; } else {
            foreach ($counterMetrics as $counter) if (!$sameCounter($iterationMetrics[$counter], $metricBaseline[$counter], $counter)) throw new RuntimeException($counter . ' changed from its baseline');
            if ($runtimeIdentityCanonical !== $runtimeIdentityBaselineCanonical) throw new RuntimeException('runtime identity changed during endurance run');
        }
        $previousElapsed = $sampleTimes === [] ? 0 : (int) end($sampleTimes);
        $wallElapsed = ($wallTimestamp - $startedWall) * 1000000;
        $previousWallElapsed = $sampleWallTimes === [] ? 0 : ((int) end($sampleWallTimes) - $startedWall) * 1000000;
        if ($elapsedMicroseconds <= $previousElapsed || $elapsedMicroseconds - $previousElapsed > $maxGapMicroseconds || $wallElapsed < $previousWallElapsed || abs($wallElapsed - $elapsedMicroseconds) > $clockSkewMicroseconds) throw new RuntimeException('sample continuity exceeded the frozen time budget');
        $metricsSeries[] = ['elapsed_seconds' => $elapsedMicroseconds / 1000000, 'values' => $iterationMetrics];
        $record = ['schema' => 'sand-iam.endurance-sample/v2', 'identity' => $identity, 'iteration' => $iteration, 'run_started_at' => $runStartedAt, 'runtime_identity' => $iterationRuntimeIdentity, 'wall_time' => $wallTime, 'elapsed_microseconds' => $elapsedMicroseconds, 'previous_sha256' => $previousHash, 'probes' => $probeResults, 'metrics' => $iterationMetrics];
        $recordHash = hash('sha256', $canonicalJson($record));
        $record['record_sha256'] = $recordHash;
        $writeFully($evidenceHandle, $canonicalJson($record) . "\n", 'endurance evidence');
        $flushDurably($evidenceHandle, 'endurance evidence');
        $previousHash = $recordHash;
        $sampleTimes[] = $elapsedMicroseconds;
        $sampleWallTimes[] = $wallTimestamp;
        ++$iteration;
        $remaining = ($durationMicroseconds - $elapsedMicroseconds) / 1000000;
        if ($remaining <= 0) break;
        if ($remaining > 0) {
            $iterationRuntime = (hrtime(true) - $iterationStarted) / 1e9;
            $sleepSeconds = min($remaining, max(0.0, $plan['interval_seconds'] - $iterationRuntime));
            if ($sleepSeconds > 0) usleep((int) min(PHP_INT_MAX, round($sleepSeconds * 1e6)));
        }
    } while (true);
} catch (Throwable $exception) {
    @flock($evidenceHandle, LOCK_UN); fclose($evidenceHandle); throw $exception;
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
    'worker_exit_delta' => 0, 'worker_restart_delta' => 0, 'unrecoverable_backlog_delta' => 0,
    'unauthorized_allow_delta' => 0, 'data_corruption_delta' => 0,
    'event_loop_lag_p99_ms' => $percentile($metricValues('event_loop_lag_ms'), 0.99), 'pool_wait_p99_ms' => $percentile($metricValues('pool_wait_ms'), 0.99),
] : array_fill_keys([
    'max_rss_bytes', 'rss_slope_bytes_per_hour', 'max_fd_count', 'fd_slope_per_hour', 'max_queue_depth',
    'max_unrecoverable_backlog', 'max_security_operation_retention_backlog', 'max_auth_rate_limit_retention_backlog', 'worker_exit_delta', 'worker_restart_delta', 'unrecoverable_backlog_delta', 'unauthorized_allow_delta',
    'data_corruption_delta', 'event_loop_lag_p99_ms', 'pool_wait_p99_ms',
], null);
$summary = [
    'schema' => 'sand-iam.endurance-report/v2', 'identity' => $identity, 'candidate' => $candidate, 'acceptance_run_id' => $acceptanceRunId,
    'started_at' => $runStartedAt, 'ended_at' => gmdate('Y-m-d\TH:i:s\Z'), 'runtime_identity' => $runtimeIdentityBaseline,
    'duration_seconds' => round((hrtime(true) - $startedMono) / 1e9, 3), 'duration_microseconds' => (int) end($sampleTimes), 'samples' => $iteration,
    'checks' => ['passed' => $totalChecks - $failedChecks, 'total' => $totalChecks, 'failed' => $failedChecks, 'error_rate' => $totalChecks === 0 ? 1 : $failedChecks / $totalChecks],
    'max_gap_seconds' => $gaps === [] ? INF : max($gaps) / 1000000, 'latency' => $latencySummary,
    'resources' => $resourceSummary,
    'evidence' => ['jsonl' => basename($output), 'sha256' => hash_file('sha256', $output), 'final_record_sha256' => $previousHash],
    'production_baseline_rules_observed' => [1, 3, 5, 10, 11, 12],
];
$thresholds = $plan['thresholds'];
$minimumSamples = $minimumSamplesFor($durationMicroseconds, $maxGapMicroseconds);
$lastWallMicroseconds = ((int) end($sampleWallTimes) - $startedWall) * 1000000;
$timelineComplete = $iteration >= $minimumSamples && (int) end($sampleTimes) >= $durationMicroseconds && (int) end($sampleTimes) - $durationMicroseconds <= $maxGapMicroseconds && $lastWallMicroseconds + $clockSkewMicroseconds >= $durationMicroseconds;
$passed = $metricsComplete && $timelineComplete
    && $summary['checks']['error_rate'] <= $thresholds['max_error_rate']
    && $summary['max_gap_seconds'] <= $thresholds['max_gap_seconds']
    && $summary['resources']['max_rss_bytes'] <= $thresholds['max_rss_bytes']
    && $summary['resources']['rss_slope_bytes_per_hour'] <= $thresholds['max_rss_slope_bytes_per_hour']
    && $summary['resources']['max_fd_count'] <= $thresholds['max_fd_count']
    && $summary['resources']['fd_slope_per_hour'] <= $thresholds['max_fd_slope_per_hour']
    && $summary['resources']['max_queue_depth'] <= $thresholds['max_queue_depth']
    && $summary['resources']['max_unrecoverable_backlog'] <= $thresholds['max_unrecoverable_backlog']
    && $summary['resources']['unrecoverable_backlog_delta'] <= $thresholds['max_unrecoverable_backlog_delta']
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
try {
    $finalizeEvidence($evidenceHandle, $output, $summaryPath, $summaryBytes);
} catch (Throwable $exception) {
    @flock($evidenceHandle, LOCK_UN);
    fclose($evidenceHandle);
    throw $exception;
}
@flock($evidenceHandle, LOCK_UN);
fclose($evidenceHandle);
echo 'SandIAM endurance acceptance ' . ($passed ? 'passed' : 'failed') . ': samples=' . $iteration . ' evidence=' . $output . PHP_EOL;
exit($passed ? 0 : 1);
