<?php

declare(strict_types=1);

/**
 * Validate external, independently produced SandIAM/Casdoor journey evidence.
 * This tool is read-only and never connects to either product.
 */

$options = getopt('', ['report:']);
$reportPath = $options['report'] ?? null;
if (!is_string($reportPath) || trim($reportPath) === '') throw new InvalidArgumentException('--report is required');
$reportPath = realpath($reportPath);
if (!is_string($reportPath) || !is_file($reportPath) || is_link($reportPath)) throw new RuntimeException('comparison report must be an existing regular file');
$reportRoot = dirname($reportPath);
$report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($report) || ($report['schema'] ?? null) !== 'sand-iam.casdoor-comparison/v1') throw new RuntimeException('invalid comparison report schema');

/** @param list<string> $required @param list<string> $allowed */
$expectKeys = static function (array $value, array $required, array $allowed, string $label): void {
    $missing = array_values(array_diff($required, array_keys($value)));
    $unknown = array_values(array_diff(array_keys($value), $allowed));
    if ($missing !== []) throw new RuntimeException($label . ' is missing keys: ' . implode(', ', $missing));
    if ($unknown !== []) throw new RuntimeException($label . ' has unknown keys: ' . implode(', ', $unknown));
};

$expectKeys($report, ['schema', 'candidate', 'reviewer', 'environment', 'journeys'], ['schema', 'candidate', 'reviewer', 'environment', 'journeys'], 'report');
$candidate = $report['candidate'];
$reviewer = $report['reviewer'];
$environment = $report['environment'];
if (!is_array($candidate) || !is_array($reviewer) || !is_array($environment) || !is_array($report['journeys'])) throw new RuntimeException('comparison report sections must be objects or arrays');
$expectKeys($candidate, ['version', 'archive_sha256', 'artifact_manifest_sha256'], ['version', 'archive_sha256', 'artifact_manifest_sha256'], 'candidate');
if (preg_match('/^\d+\.\d+\.\d+$/', (string) $candidate['version']) !== 1) throw new RuntimeException('candidate version must be semantic');
foreach (['archive_sha256', 'artifact_manifest_sha256'] as $field) {
    if (preg_match('/^[0-9a-f]{64}$/', (string) $candidate[$field]) !== 1) throw new RuntimeException('candidate.' . $field . ' must be SHA-256');
}
$expectKeys($reviewer, ['id', 'independent', 'webman_experience', 'conflict_statement'], ['id', 'independent', 'webman_experience', 'conflict_statement'], 'reviewer');
if (!is_string($reviewer['id']) || trim($reviewer['id']) === '' || ($reviewer['independent'] ?? null) !== true
    || ($reviewer['webman_experience'] ?? null) !== true || !is_string($reviewer['conflict_statement']) || trim($reviewer['conflict_statement']) === '') {
    throw new RuntimeException('reviewer must be identified, independent, experienced, and provide a conflict statement');
}
$expectKeys($environment, ['fingerprint', 'host', 'browser', 'php', 'postgresql', 'network_profile'], ['fingerprint', 'host', 'browser', 'php', 'postgresql', 'network_profile'], 'environment');
if (preg_match('/^[0-9a-f]{64}$/', (string) $environment['fingerprint']) !== 1) throw new RuntimeException('environment fingerprint must be SHA-256');
foreach (['host', 'browser', 'php', 'postgresql', 'network_profile'] as $field) {
    if (!is_string($environment[$field]) || trim($environment[$field]) === '') throw new RuntimeException('environment.' . $field . ' is required');
}

$journeyTargets = [
    'webman-api-governance' => ['max_duration_seconds' => 1200, 'max_manual_operations' => 8],
    'human-auth-mfa-business-api' => ['max_duration_seconds' => 600, 'max_manual_operations' => 9],
    'machine-service-action' => ['max_duration_seconds' => 900, 'max_manual_operations' => 8],
];
$seenJourneys = [];
$seenEvidence = [];
$summaries = [];
$parseUtcTimestamp = static function (mixed $value, string $label): DateTimeImmutable {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
        throw new RuntimeException($label . ' must use exact UTC second precision');
    }
    $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$timestamp instanceof DateTimeImmutable || $timestamp->format('Y-m-d\TH:i:s\Z') !== $value
        || (is_array($errors) && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))) {
        throw new RuntimeException($label . ' must be a valid UTC timestamp');
    }
    return $timestamp;
};

foreach ($report['journeys'] as $journeyIndex => $journey) {
    if (!is_array($journey)) throw new RuntimeException('journey must be an object at index ' . $journeyIndex);
    $expectKeys($journey, ['id', 'runs'], ['id', 'runs'], 'journey');
    $journeyId = $journey['id'] ?? null;
    if (!is_string($journeyId) || !isset($journeyTargets[$journeyId]) || isset($seenJourneys[$journeyId])) throw new RuntimeException('unknown or duplicate journey id');
    if (!is_array($journey['runs']) || count($journey['runs']) !== 4) throw new RuntimeException($journeyId . ' must contain exactly four runs');
    $seenJourneys[$journeyId] = true;
    $matrix = [];
    $measurements = ['sandiam' => ['duration' => [], 'operations' => []], 'casdoor' => ['duration' => [], 'operations' => []]];
    foreach ($journey['runs'] as $runIndex => $run) {
        if (!is_array($run)) throw new RuntimeException($journeyId . ' run must be an object');
        $allowedRunKeys = ['system', 'round', 'started_at', 'ended_at', 'duration_seconds', 'manual_operations', 'commands', 'recovery_attempts', 'unresolved_failures', 'business_code_change_points', 'completed', 'result_equivalent', 'security_equivalent', 'cleanup_verified', 'evidence'];
        $expectKeys($run, $allowedRunKeys, $allowedRunKeys, $journeyId . ' run');
        $system = $run['system'] ?? null;
        $round = $run['round'] ?? null;
        if (!in_array($system, ['sandiam', 'casdoor'], true) || !is_int($round) || !in_array($round, [1, 2], true)) throw new RuntimeException($journeyId . ' has invalid system/round');
        $matrixKey = $system . ':' . $round;
        if (isset($matrix[$matrixKey])) throw new RuntimeException($journeyId . ' has duplicate run ' . $matrixKey);
        $matrix[$matrixKey] = true;
        foreach (['duration_seconds', 'manual_operations', 'commands', 'recovery_attempts', 'unresolved_failures', 'business_code_change_points'] as $field) {
            if (!is_int($run[$field] ?? null) || $run[$field] < 0) throw new RuntimeException($journeyId . ' ' . $matrixKey . ' has invalid ' . $field);
        }
        foreach (['completed', 'result_equivalent', 'security_equivalent', 'cleanup_verified'] as $field) {
            if (($run[$field] ?? null) !== true) throw new RuntimeException($journeyId . ' ' . $matrixKey . ' did not prove ' . $field);
        }
        if ($run['unresolved_failures'] !== 0) throw new RuntimeException($journeyId . ' ' . $matrixKey . ' has unresolved failures');
        $startedAt = $parseUtcTimestamp($run['started_at'] ?? null, $journeyId . ' ' . $matrixKey . ' started_at');
        $endedAt = $parseUtcTimestamp($run['ended_at'] ?? null, $journeyId . ' ' . $matrixKey . ' ended_at');
        if ($endedAt->getTimestamp() - $startedAt->getTimestamp() !== $run['duration_seconds']) {
            throw new RuntimeException($journeyId . ' ' . $matrixKey . ' timestamps do not match duration');
        }
        if ($system === 'sandiam' && ($run['duration_seconds'] > $journeyTargets[$journeyId]['max_duration_seconds'] || $run['manual_operations'] > $journeyTargets[$journeyId]['max_manual_operations'])) {
            throw new RuntimeException($journeyId . ' SandIAM run exceeded its duration or operation target');
        }
        if (!is_array($run['evidence']) || $run['evidence'] === []) throw new RuntimeException($journeyId . ' ' . $matrixKey . ' has no evidence');
        foreach ($run['evidence'] as $evidence) {
            if (!is_array($evidence)) throw new RuntimeException('evidence reference must be an object');
            $expectKeys($evidence, ['path', 'sha256'], ['path', 'sha256'], 'evidence');
            $relative = $evidence['path'] ?? null;
            if (!is_string($relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || preg_match('#(?:^|/)\.\.?(?:/|$)#', $relative) === 1) throw new RuntimeException('evidence path must be safe and relative');
            if (isset($seenEvidence[$relative])) throw new RuntimeException('evidence path is reused across runs: ' . $relative);
            $seenEvidence[$relative] = true;
            $lexicalPath = $reportRoot;
            foreach (explode('/', $relative) as $component) {
                $lexicalPath .= '/' . $component;
                if (is_link($lexicalPath)) throw new RuntimeException('evidence path contains a symbolic link: ' . $relative);
            }
            $path = realpath($lexicalPath);
            if (!is_string($path) || !is_file($path) || !str_starts_with($path, $reportRoot . '/')) throw new RuntimeException('evidence file is missing or outside report root: ' . $relative);
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals((string) $evidence['sha256'], $hash)) throw new RuntimeException('evidence SHA-256 mismatch: ' . $relative);
            $bytes = file_get_contents($path);
            if (is_string($bytes) && preg_match('//u', $bytes) === 1 && preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|\bsiam_(?:at|wc|rt)_[A-Za-z0-9_-]{8,}\b/i', $bytes) === 1) {
                throw new RuntimeException('evidence contains a high-confidence secret: ' . $relative);
            }
        }
        $measurements[$system]['duration'][] = $run['duration_seconds'];
        $measurements[$system]['operations'][] = $run['manual_operations'];
    }
    foreach (['sandiam:1', 'sandiam:2', 'casdoor:1', 'casdoor:2'] as $requiredRun) {
        if (!isset($matrix[$requiredRun])) throw new RuntimeException($journeyId . ' is missing ' . $requiredRun);
    }
    $mean = static fn (array $values): float => array_sum($values) / count($values);
    $sandiamDuration = $mean($measurements['sandiam']['duration']);
    $casdoorDuration = $mean($measurements['casdoor']['duration']);
    $sandiamOperations = $mean($measurements['sandiam']['operations']);
    $casdoorOperations = $mean($measurements['casdoor']['operations']);
    if (!(($sandiamDuration < $casdoorDuration && $sandiamOperations <= $casdoorOperations)
        || ($sandiamOperations < $casdoorOperations && $sandiamDuration <= $casdoorDuration))) {
        throw new RuntimeException($journeyId . ' did not prove one better dimension while the other did not regress');
    }
    $summaries[$journeyId] = [
        'sandiam_mean_duration_seconds' => $sandiamDuration,
        'casdoor_mean_duration_seconds' => $casdoorDuration,
        'sandiam_mean_manual_operations' => $sandiamOperations,
        'casdoor_mean_manual_operations' => $casdoorOperations,
    ];
}

if (count($seenJourneys) !== count($journeyTargets)) {
    $missing = array_values(array_diff(array_keys($journeyTargets), array_keys($seenJourneys)));
    throw new RuntimeException('comparison is missing journeys: ' . implode(', ', $missing));
}

echo json_encode([
    'schema' => 'sand-iam.casdoor-comparison-validation/v1',
    'passed' => true,
    'journeys_passed' => 3,
    'runs_verified' => 12,
    'reviewer_id' => $reviewer['id'],
    'environment_fingerprint' => $environment['fingerprint'],
    'summary' => $summaries,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
