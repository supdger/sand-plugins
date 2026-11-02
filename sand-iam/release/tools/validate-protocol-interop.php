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
if (!is_array($report) || ($report['schema'] ?? null) !== 'sand-iam.protocol-interop/v2') throw new RuntimeException('invalid interop report schema');

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
$seenRequestIds = [];
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
    $caseEvidenceByKind = ['structured' => [], 'client' => [], 'sandiam' => [], 'counterpart' => [], 'cleanup' => []];
    $caseEvidenceContents = [];
    foreach ($case['evidence'] as $evidence) {
        if (!is_array($evidence)) throw new RuntimeException('evidence reference must be an object');
        $expectKeys($evidence, ['kind', 'path', 'sha256'], ['kind', 'path', 'sha256'], 'evidence');
        $kind = $evidence['kind'] ?? null;
        if (!is_string($kind) || !array_key_exists($kind, $caseEvidenceByKind)) throw new RuntimeException($id . ' evidence kind is invalid');
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
        if (!is_string($bytes)) throw new RuntimeException('evidence cannot be read: ' . $relative);
        if ($kind !== 'structured' && $bytes === '') throw new RuntimeException('evidence file is empty: ' . $relative);
        $caseEvidenceByKind[$kind][] = $relative;
        $caseEvidenceContents[$relative] = $bytes;
    }
    foreach ($caseEvidenceByKind as $kind => $paths) {
        $expectedCount = $kind === 'structured' ? 1 : null;
        if ($paths === [] || ($expectedCount !== null && count($paths) !== $expectedCount)) {
            throw new RuntimeException($id . ' must include ' . ($expectedCount === 1 ? 'exactly one ' : 'at least one ') . $kind . ' evidence file');
        }
    }
    $structuredPath = $caseEvidenceByKind['structured'][0];
    if (!str_ends_with($structuredPath, '.json')) throw new RuntimeException($id . ' structured evidence must be JSON');
    try {
        $structured = json_decode($caseEvidenceContents[$structuredPath], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException($id . ' structured evidence is invalid JSON', 0, $exception);
    }
    if (!is_array($structured)) throw new RuntimeException($id . ' structured evidence must be an object');
    $structuredKeys = ['schema', 'protocol', 'candidate_archive_sha256', 'environment_fingerprint', 'client', 'counterpart', 'started_at', 'ended_at', 'assertions', 'cleanup'];
    $expectKeys($structured, $structuredKeys, $structuredKeys, $id . ' structured evidence');
    if (($structured['schema'] ?? null) !== 'sand-iam.protocol-interop-evidence/v1'
        || ($structured['protocol'] ?? null) !== $id
        || ($structured['candidate_archive_sha256'] ?? null) !== $candidate['archive_sha256']
        || ($structured['environment_fingerprint'] ?? null) !== $environment['fingerprint']) {
        throw new RuntimeException($id . ' structured evidence binding mismatch');
    }
    if (!is_array($structured['client'] ?? null) || !is_array($structured['counterpart'] ?? null)) {
        throw new RuntimeException($id . ' structured client/counterpart must be objects');
    }
    $expectKeys($structured['client'], ['name', 'version', 'project_url'], ['name', 'version', 'project_url'], $id . ' structured client');
    $expectKeys($structured['counterpart'], ['name', 'version', 'endpoint'], ['name', 'version', 'endpoint'], $id . ' structured counterpart');
    foreach (['name', 'version', 'project_url'] as $field) {
        if (($structured['client'][$field] ?? null) !== ($client[$field] ?? null)) throw new RuntimeException($id . ' structured client binding mismatch');
    }
    foreach (['name', 'version', 'endpoint'] as $field) {
        if (($structured['counterpart'][$field] ?? null) !== ($counterpart[$field] ?? null)) throw new RuntimeException($id . ' structured counterpart binding mismatch');
    }
    if (($structured['started_at'] ?? null) !== $case['started_at'] || ($structured['ended_at'] ?? null) !== $case['ended_at']) {
        throw new RuntimeException($id . ' structured time binding mismatch');
    }
    $parseUtcTimestamp($structured['started_at'] ?? null, $id . ' structured started_at');
    $parseUtcTimestamp($structured['ended_at'] ?? null, $id . ' structured ended_at');
    if (!is_array($structured['assertions'] ?? null)) throw new RuntimeException($id . ' structured assertions must be an object');
    $expectKeys($structured['assertions'], $requiredAssertions[$id], $requiredAssertions[$id], $id . ' structured assertions');
    foreach ($requiredAssertions[$id] as $assertion) {
        $detail = $structured['assertions'][$assertion] ?? null;
        if (!is_array($detail)) throw new RuntimeException($id . ' structured assertion must be an object: ' . $assertion);
        $expectKeys($detail, ['passed', 'request_id', 'artifact_paths'], ['passed', 'request_id', 'artifact_paths'], $id . ' structured assertion ' . $assertion);
        $requestId = $detail['request_id'] ?? null;
        if (($detail['passed'] ?? null) !== true
            || !is_string($requestId)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $requestId) !== 1
            || isset($seenRequestIds[$requestId])) {
            throw new RuntimeException($id . ' structured assertion request id or result is invalid: ' . $assertion);
        }
        $seenRequestIds[$requestId] = true;
        $artifactPaths = $detail['artifact_paths'] ?? null;
        if (!is_array($artifactPaths) || $artifactPaths === [] || count($artifactPaths) !== count(array_unique($artifactPaths))) {
            throw new RuntimeException($id . ' structured assertion artifact paths are invalid: ' . $assertion);
        }
        foreach ($artifactPaths as $artifactPath) {
            if (!is_string($artifactPath) || !isset($caseEvidenceContents[$artifactPath]) || $artifactPath === $structuredPath) {
                throw new RuntimeException($id . ' structured assertion references unknown evidence: ' . $assertion);
            }
        }
        foreach (['client', 'sandiam', 'counterpart'] as $requiredKind) {
            if (array_intersect($artifactPaths, $caseEvidenceByKind[$requiredKind]) === []) {
                throw new RuntimeException($id . ' structured assertion is missing ' . $requiredKind . ' evidence: ' . $assertion);
            }
        }
    }
    $cleanup = $structured['cleanup'] ?? null;
    if (!is_array($cleanup)) throw new RuntimeException($id . ' structured cleanup must be an object');
    $expectKeys($cleanup, ['verified', 'residual_count', 'artifact_paths'], ['verified', 'residual_count', 'artifact_paths'], $id . ' structured cleanup');
    $cleanupPaths = $cleanup['artifact_paths'] ?? null;
    if (($cleanup['verified'] ?? null) !== true || ($cleanup['residual_count'] ?? null) !== 0
        || !is_array($cleanupPaths) || $cleanupPaths === [] || count($cleanupPaths) !== count(array_unique($cleanupPaths))
        || array_intersect($cleanupPaths, $caseEvidenceByKind['cleanup']) === []) {
        throw new RuntimeException($id . ' structured cleanup is incomplete');
    }
    foreach ($cleanupPaths as $cleanupPath) {
        if (!is_string($cleanupPath) || !isset($caseEvidenceContents[$cleanupPath]) || $cleanupPath === $structuredPath) {
            throw new RuntimeException($id . ' structured cleanup references unknown evidence');
        }
    }
}
$missingCases = array_values(array_diff(array_keys($requiredAssertions), array_keys($seenCases)));
if ($missingCases !== []) throw new RuntimeException('interop report is missing cases: ' . implode(', ', $missingCases));

echo json_encode([
    'schema' => 'sand-iam.protocol-interop-validation/v2',
    'passed' => true,
    'cases_passed' => count($requiredAssertions),
    'assertions_verified' => $assertionsVerified,
    'evidence_files_verified' => count($seenEvidence),
    'reviewer_id' => $reviewer['id'],
    'environment_fingerprint' => $environment['fingerprint'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
