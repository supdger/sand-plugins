<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$validator = $root . '/tools/validate-protocol-interop.php';
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
$seed = tempnam('/private/tmp', 'sand-iam-interop-');
if ($seed === false || !unlink($seed) || !mkdir($seed, 0700) || !mkdir($seed . '/evidence', 0700)) throw new RuntimeException('cannot create interop fixture');

try {
    $assertions = [
        'oidc' => ['discovery', 'authorization_code_pkce', 'userinfo', 'wrong_audience_rejected', 'refresh_replay_rejected', 'revocation_effective'],
        'saml' => ['signed_authn', 'attribute_mapping', 'wrong_audience_rejected', 'assertion_replay_rejected', 'disabled_binding_rejected'],
        'ldap' => ['tls_bind', 'incremental_sync', 'mapping_applied', 'invalid_bind_rejected', 'remote_disable_propagated'],
        'scim' => ['service_provider_discovery', 'user_group_lifecycle', 'filter_pagination', 'invalid_token_rejected', 'deactivation_effective'],
        'cas' => ['login_ticket_validate', 'cas10_validate', 'cas20_validate', 'cas30_attributes', 'wrong_service_rejected', 'ticket_replay_rejected'],
        'kerberos-spnego' => ['gssapi_negotiate', 'principal_mapping', 'wrong_spn_rejected', 'unmapped_principal_rejected', 'replay_rejected'],
        'radius' => ['access_accept', 'access_reject', 'wrong_secret_rejected', 'request_replay_rejected', 'accounting_recorded'],
    ];
    $cases = [];
    foreach ($assertions as $id => $names) {
        $evidencePath = 'evidence/' . $id . '.json';
        $bytes = json_encode(['protocol' => $id, 'result' => 'redacted-pass'], JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($seed . '/' . $evidencePath, $bytes);
        $cases[] = [
            'id' => $id,
            'client' => ['name' => 'Standards Project ' . $id, 'version' => '1.2.3', 'project_url' => 'https://client.example.test/' . $id, 'standard' => true],
            'counterpart' => ['name' => 'Controlled ' . $id, 'version' => '2.0.0', 'endpoint' => $id === 'ldap' ? 'ldaps://directory.example.test' : 'https://interop.example.test/' . $id, 'real' => true, 'controlled' => true],
            'started_at' => '2026-09-12T00:00:00Z', 'ended_at' => '2026-09-12T00:05:00Z',
            'assertions' => array_fill_keys($names, true), 'completed' => true, 'cleanup_verified' => true,
            'unresolved_failures' => 0, 'evidence' => [['path' => $evidencePath, 'sha256' => hash('sha256', $bytes)]],
        ];
    }
    $valid = [
        'schema' => 'sand-iam.protocol-interop/v1',
        'candidate' => ['version' => '0.7.0', 'archive_sha256' => str_repeat('a', 64), 'artifact_manifest_sha256' => str_repeat('b', 64)],
        'reviewer' => ['id' => 'independent-interop-reviewer', 'independent' => true, 'conflict_statement' => 'I did not develop the protocol implementations.'],
        'environment' => ['fingerprint' => str_repeat('c', 64), 'host' => 'controlled-host', 'postgresql' => '18', 'network_profile' => 'isolated-controlled'],
        'cases' => $cases,
    ];
    $reportPath = $seed . '/report.json';
    $write = static fn (array $report): int|false => file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $write($valid);
    [$validStatus, $validOutput] = $run($reportPath);

    $tamperedEvidenceHash = $valid;
    $tamperedEvidenceHash['cases'][0]['evidence'][0]['sha256'] = str_repeat('f', 64);
    $write($tamperedEvidenceHash);
    [$tamperedEvidenceHashStatus, $tamperedEvidenceHashOutput] = $run($reportPath);

    $unclean = $valid;
    $unclean['cases'][0]['cleanup_verified'] = false;
    $write($unclean);
    [$uncleanStatus, $uncleanOutput] = $run($reportPath);

    $missingVersion = $valid;
    unset($missingVersion['cases'][0]['client']['version']);
    $write($missingVersion);
    [$missingVersionStatus, $missingVersionOutput] = $run($reportPath);

    $invalidVersion = $valid;
    $invalidVersion['cases'][0]['client']['version'] = '';
    $write($invalidVersion);
    [$invalidVersionStatus, $invalidVersionOutput] = $run($reportPath);

    $invalidClient = $valid;
    $invalidClient['cases'][0]['client']['name'] = 'curl';
    $write($invalidClient);
    [$clientStatus, $clientOutput] = $run($reportPath);

    $missingAssertion = $valid;
    $missingAssertion['cases'][4]['assertions']['ticket_replay_rejected'] = false;
    $write($missingAssertion);
    [$assertionStatus, $assertionOutput] = $run($reportPath);

    $tampered = $valid;
    file_put_contents($seed . '/evidence/radius.json', "tampered\n");
    $write($tampered);
    [$tamperedStatus, $tamperedOutput] = $run($reportPath);

    $secret = $valid;
    $secretBytes = "-----BEGIN PRIVATE KEY-----\nredacted-fixture\n-----END PRIVATE KEY-----\n";
    file_put_contents($seed . '/evidence/oidc.json', $secretBytes);
    $secret['cases'][0]['evidence'][0]['sha256'] = hash('sha256', $secretBytes);
    $write($secret);
    [$secretStatus, $secretOutput] = $run($reportPath);

    if (!symlink($seed . '/evidence', $seed . '/linked-evidence')) throw new RuntimeException('cannot create evidence symlink fixture');
    $linked = $valid;
    $linked['cases'][0]['evidence'][0]['path'] = 'linked-evidence/oidc.json';
    $linked['cases'][0]['evidence'][0]['sha256'] = hash('sha256', $secretBytes);
    $write($linked);
    [$linkedStatus, $linkedOutput] = $run($reportPath);

    $passed = $validStatus === 0 && str_contains($validOutput, '"cases_passed": 7') && str_contains($validOutput, '"assertions_verified": 37')
        && $tamperedEvidenceHashStatus !== 0 && str_contains($tamperedEvidenceHashOutput, 'evidence SHA-256 mismatch')
        && $uncleanStatus !== 0 && str_contains($uncleanOutput, 'did not complete cleanly')
        && $missingVersionStatus !== 0 && str_contains($missingVersionOutput, 'client is missing keys: version')
        && $invalidVersionStatus !== 0 && str_contains($invalidVersionOutput, 'client.version is required')
        && $clientStatus !== 0 && str_contains($clientOutput, 'versioned standard client')
        && $assertionStatus !== 0 && str_contains($assertionOutput, 'did not prove ticket_replay_rejected')
        && $tamperedStatus !== 0 && str_contains($tamperedOutput, 'evidence SHA-256 mismatch')
        && $secretStatus !== 0 && str_contains($secretOutput, 'evidence contains a high-confidence secret')
        && $linkedStatus !== 0 && str_contains($linkedOutput, 'path contains a symbolic link');
    if (!$passed) throw new RuntimeException('protocol interop validator did not enforce complete external evidence');
} finally {
    $removeTree($seed);
}

echo "SandIAM protocol interoperability evidence checks passed\n";
