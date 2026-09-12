<?php

declare(strict_types=1);

/** Validate independently produced, candidate-bound protocol interoperability evidence. */

$options = getopt('', ['report:']);
$reportPath = $options['report'] ?? null;
if (!is_string($reportPath) || trim($reportPath) === '') throw new InvalidArgumentException('--report is required');
$reportPath = realpath($reportPath);
if (!is_string($reportPath) || !is_file($reportPath) || is_link($reportPath)) throw new RuntimeException('interop report must be an existing regular file');
$reportRoot = dirname($reportPath);
$report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($report) || ($report['schema'] ?? null) !== 'sand-iam.protocol-interop/v1') throw new RuntimeException('invalid interop report schema');

/** @param list<string> $required @param list<string> $allowed */
$expectKeys = static function (array $value, array $required, array $allowed, string $label): void {
    $missing = array_values(array_diff($required, array_keys($value)));
    $unknown = array_values(array_diff(array_keys($value), $allowed));
    if ($missing !== []) throw new RuntimeException($label . ' is missing keys: ' . implode(', ', $missing));
    if ($unknown !== []) throw new RuntimeException($label . ' has unknown keys: ' . implode(', ', $unknown));
};
$expectNonEmpty = static function (mixed $value, string $label): string {
    if (!is_string($value) || trim($value) === '') throw new RuntimeException($label . ' is required');
    return trim($value);
};
$parseUtcTimestamp = static function (mixed $value, string $label): DateTimeImmutable {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) throw new RuntimeException($label . ' must use exact UTC second precision');
    $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$timestamp instanceof DateTimeImmutable || $timestamp->format('Y-m-d\TH:i:s\Z') !== $value
        || (is_array($errors) && (($errors['warning_count'] ?? 0) !== 0 || ($errors['error_count'] ?? 0) !== 0))) {
        throw new RuntimeException($label . ' must be a valid UTC timestamp');
    }
    return $timestamp;
};

$expectKeys($report, ['schema', 'candidate', 'reviewer', 'environment', 'cases'], ['schema', 'candidate', 'reviewer', 'environment', 'cases'], 'report');
foreach (['candidate', 'reviewer', 'environment', 'cases'] as $section) if (!is_array($report[$section])) throw new RuntimeException($section . ' must be an object or array');
$candidate = $report['candidate'];
$expectKeys($candidate, ['version', 'archive_sha256', 'artifact_manifest_sha256'], ['version', 'archive_sha256', 'artifact_manifest_sha256'], 'candidate');
if (preg_match('/^\d+\.\d+\.\d+$/', (string) $candidate['version']) !== 1) throw new RuntimeException('candidate version must be semantic');
foreach (['archive_sha256', 'artifact_manifest_sha256'] as $field) if (preg_match('/^[0-9a-f]{64}$/', (string) $candidate[$field]) !== 1) throw new RuntimeException('candidate.' . $field . ' must be SHA-256');

$reviewer = $report['reviewer'];
$expectKeys($reviewer, ['id', 'independent', 'conflict_statement'], ['id', 'independent', 'conflict_statement'], 'reviewer');
$expectNonEmpty($reviewer['id'] ?? null, 'reviewer.id');
$expectNonEmpty($reviewer['conflict_statement'] ?? null, 'reviewer.conflict_statement');
if (($reviewer['independent'] ?? null) !== true) throw new RuntimeException('reviewer must be independent');

$environment = $report['environment'];
$expectKeys($environment, ['fingerprint', 'host', 'postgresql', 'network_profile'], ['fingerprint', 'host', 'postgresql', 'network_profile'], 'environment');
if (preg_match('/^[0-9a-f]{64}$/', (string) ($environment['fingerprint'] ?? '')) !== 1) throw new RuntimeException('environment fingerprint must be SHA-256');
foreach (['host', 'postgresql', 'network_profile'] as $field) $expectNonEmpty($environment[$field] ?? null, 'environment.' . $field);

$requiredAssertions = [
    'oidc' => ['discovery', 'authorization_code_pkce', 'userinfo', 'wrong_audience_rejected', 'refresh_replay_rejected', 'revocation_effective'],
    'saml' => ['signed_authn', 'attribute_mapping', 'wrong_audience_rejected', 'assertion_replay_rejected', 'disabled_binding_rejected'],
    'ldap' => ['tls_bind', 'incremental_sync', 'mapping_applied', 'invalid_bind_rejected', 'remote_disable_propagated'],
    'scim' => ['service_provider_discovery', 'user_group_lifecycle', 'filter_pagination', 'invalid_token_rejected', 'deactivation_effective'],
    'cas' => ['login_ticket_validate', 'cas10_validate', 'cas20_validate', 'cas30_attributes', 'wrong_service_rejected', 'ticket_replay_rejected'],
    'kerberos-spnego' => ['gssapi_negotiate', 'principal_mapping', 'wrong_spn_rejected', 'unmapped_principal_rejected', 'replay_rejected'],
    'radius' => ['access_accept', 'access_reject', 'wrong_secret_rejected', 'request_replay_rejected', 'accounting_recorded'],
];
$genericClientPattern = '/^(?:curl|browser|custom|internal|manual|mock|fake|fixture|test client)$/i';
$seenCases = [];
$seenEvidence = [];
$assertionsVerified = 0;
foreach ($report['cases'] as $index => $case) {
    if (!is_array($case)) throw new RuntimeException('case must be an object at index ' . $index);
    $caseKeys = ['id', 'client', 'counterpart', 'started_at', 'ended_at', 'assertions', 'completed', 'cleanup_verified', 'unresolved_failures', 'evidence'];
    $expectKeys($case, $caseKeys, $caseKeys, 'case');
    $id = $case['id'] ?? null;
    if (!is_string($id) || !isset($requiredAssertions[$id]) || isset($seenCases[$id])) throw new RuntimeException('unknown or duplicate protocol case id');
    $seenCases[$id] = true;
    $client = $case['client'];
    $counterpart = $case['counterpart'];
    if (!is_array($client) || !is_array($counterpart)) throw new RuntimeException($id . ' client/counterpart must be objects');
    $expectKeys($client, ['name', 'version', 'project_url', 'standard'], ['name', 'version', 'project_url', 'standard'], $id . ' client');
    $clientName = $expectNonEmpty($client['name'] ?? null, $id . ' client.name');
    $expectNonEmpty($client['version'] ?? null, $id . ' client.version');
    $clientUrl = $expectNonEmpty($client['project_url'] ?? null, $id . ' client.project_url');
    if (($client['standard'] ?? null) !== true || preg_match($genericClientPattern, $clientName) === 1 || filter_var($clientUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($clientUrl, 'https://')) {
        throw new RuntimeException($id . ' must identify a versioned standard client with an HTTPS project URL');
    }
    $expectKeys($counterpart, ['name', 'version', 'endpoint', 'real', 'controlled'], ['name', 'version', 'endpoint', 'real', 'controlled'], $id . ' counterpart');
    $expectNonEmpty($counterpart['name'] ?? null, $id . ' counterpart.name');
    $expectNonEmpty($counterpart['version'] ?? null, $id . ' counterpart.version');
    $endpoint = $expectNonEmpty($counterpart['endpoint'] ?? null, $id . ' counterpart.endpoint');
    $endpointScheme = parse_url($endpoint, PHP_URL_SCHEME);
    if (($counterpart['real'] ?? null) !== true || ($counterpart['controlled'] ?? null) !== true || !in_array($endpointScheme, ['https', 'ldap', 'ldaps', 'kerberos', 'radius'], true)) {
        throw new RuntimeException($id . ' must use a real controlled counterpart endpoint');
    }
    $startedAt = $parseUtcTimestamp($case['started_at'] ?? null, $id . ' started_at');
    $endedAt = $parseUtcTimestamp($case['ended_at'] ?? null, $id . ' ended_at');
    if ($endedAt <= $startedAt) throw new RuntimeException($id . ' ended_at must be after started_at');
    if (($case['completed'] ?? null) !== true || ($case['cleanup_verified'] ?? null) !== true || ($case['unresolved_failures'] ?? null) !== 0) throw new RuntimeException($id . ' did not complete cleanly');
    if (!is_array($case['assertions'])) throw new RuntimeException($id . ' assertions must be an object');
    $expectKeys($case['assertions'], $requiredAssertions[$id], $requiredAssertions[$id], $id . ' assertions');
    foreach ($requiredAssertions[$id] as $assertion) {
        if (($case['assertions'][$assertion] ?? null) !== true) throw new RuntimeException($id . ' did not prove ' . $assertion);
        $assertionsVerified++;
    }
    if (!is_array($case['evidence']) || $case['evidence'] === []) throw new RuntimeException($id . ' has no evidence');
    foreach ($case['evidence'] as $evidence) {
        if (!is_array($evidence)) throw new RuntimeException('evidence reference must be an object');
        $expectKeys($evidence, ['path', 'sha256'], ['path', 'sha256'], 'evidence');
        $relative = $evidence['path'] ?? null;
        if (!is_string($relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || preg_match('#(?:^|/)\.\.?(?:/|$)#', $relative) === 1) throw new RuntimeException('evidence path must be safe and relative');
        if (isset($seenEvidence[$relative])) throw new RuntimeException('evidence path is reused: ' . $relative);
        $seenEvidence[$relative] = true;
        $lexicalPath = $reportRoot;
        foreach (explode('/', $relative) as $component) {
            $lexicalPath .= '/' . $component;
            if (is_link($lexicalPath)) throw new RuntimeException('evidence path contains a symbolic link: ' . $relative);
        }
        $path = realpath($lexicalPath);
        if (!is_string($path) || !is_file($path) || !str_starts_with($path, $reportRoot . '/')) throw new RuntimeException('evidence file is missing or outside report root: ' . $relative);
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || !preg_match('/^[0-9a-f]{64}$/', (string) $evidence['sha256']) || !hash_equals((string) $evidence['sha256'], $hash)) throw new RuntimeException('evidence SHA-256 mismatch: ' . $relative);
        $bytes = file_get_contents($path);
        if (is_string($bytes) && preg_match('//u', $bytes) === 1 && preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|\bsiam_(?:at|wc|rt)_[A-Za-z0-9_-]{8,}\b/i', $bytes) === 1) throw new RuntimeException('evidence contains a high-confidence secret: ' . $relative);
    }
}
$missingCases = array_values(array_diff(array_keys($requiredAssertions), array_keys($seenCases)));
if ($missingCases !== []) throw new RuntimeException('interop report is missing cases: ' . implode(', ', $missingCases));

echo json_encode([
    'schema' => 'sand-iam.protocol-interop-validation/v1',
    'passed' => true,
    'cases_passed' => count($requiredAssertions),
    'assertions_verified' => $assertionsVerified,
    'evidence_files_verified' => count($seenEvidence),
    'reviewer_id' => $reviewer['id'],
    'environment_fingerprint' => $environment['fingerprint'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
