<?php

declare(strict_types=1);

/** Create a candidate-bound, deliberately incomplete external acceptance plan. */

require_once __DIR__ . '/release-bundle-attestation.php';

$options = getopt('', ['kind:', 'artifact-manifest:', 'output:']);
foreach (['kind', 'artifact-manifest', 'output'] as $required) {
    if (!is_string($options[$required] ?? null) || trim($options[$required]) === '') throw new InvalidArgumentException('--' . $required . ' is required');
}
$kind = $options['kind'];
if (!in_array($kind, ['endurance', 'casdoor', 'interop', 'recovery', 'independent-delivery'], true)) throw new InvalidArgumentException('--kind must be endurance, casdoor, interop, recovery, or independent-delivery');
$packageRoot = dirname(__DIR__);
$manifestPath = sandIamAssertExternalPath($options['artifact-manifest'], $packageRoot, 'artifact manifest');
$manifest = sandIamReadArtifactManifest($manifestPath);
$package = $manifest['package'];
if (!is_string($package['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $package['sha256']) !== 1) throw new RuntimeException('artifact manifest package SHA-256 is invalid');
$outputParent = sandIamAssertDirectoryPath(dirname($options['output']), 'output directory');
$rootPath = realpath($packageRoot);
if (!is_string($rootPath) || $outputParent === $rootPath || str_starts_with($outputParent, $rootPath . '/')) throw new RuntimeException('acceptance template output must remain outside sand-iam/');
$output = $outputParent . '/' . basename($options['output']);
if (file_exists($output) || is_link($output)) throw new RuntimeException('refusing to overwrite acceptance template');
$candidate = [
    'version' => $package['version'],
    'archive_sha256' => $package['sha256'],
    'artifact_manifest_sha256' => hash_file('sha256', $manifestPath),
];

if ($kind === 'endurance') {
    $candidate['source_revision'] = $manifest['source_revision']['commit'];
    $runId = 'sand_iam_endurance_' . bin2hex(random_bytes(8));
    $targetDefinitions = [
        'candidate' => ['method' => 'GET', 'effect' => 'read_only', 'authorization_env' => null, 'json_expect' => ['/archive_sha256' => $candidate['archive_sha256'], '/artifact_manifest_sha256' => $candidate['artifact_manifest_sha256']]],
        'health' => ['method' => 'GET', 'effect' => 'read_only', 'authorization_env' => null, 'json_expect' => ['/ok' => true]],
        'allow' => ['method' => 'POST', 'effect' => 'audit_only', 'authorization_env' => 'SAND_IAM_ENDURANCE_ALLOWED', 'json_expect' => ['/allowed' => true]],
        'deny' => ['method' => 'POST', 'effect' => 'audit_only', 'authorization_env' => 'SAND_IAM_ENDURANCE_DENIED', 'json_expect' => ['/allowed' => false]],
        'revoked' => ['method' => 'POST', 'effect' => 'audit_only', 'authorization_env' => 'SAND_IAM_ENDURANCE_REVOKED', 'json_expect' => ['/allowed' => false]],
        'audit' => ['method' => 'GET', 'effect' => 'read_only', 'authorization_env' => 'SAND_IAM_ENDURANCE_ADMIN', 'json_expect' => ['/acceptance_run_id' => $runId]],
        'metrics' => ['method' => 'GET', 'effect' => 'read_only', 'authorization_env' => 'SAND_IAM_ENDURANCE_METRICS', 'json_expect' => ['/ok' => true]],
    ];
    $targets = [];
    foreach ($targetDefinitions as $category => $definition) {
        $targets[] = [
            'id' => $category . '-probe', 'category' => $category,
            'url' => 'https://__REQUIRED_APPROVED_HOST__/' . $category,
            'method' => $definition['method'], 'effect' => $definition['effect'],
            'authorization_env' => $definition['authorization_env'],
            'body' => $definition['method'] === 'POST' ? ['acceptance_run_id' => $runId] : null,
            'expected_status' => 200, 'json_expect' => $definition['json_expect'], 'max_p99_ms' => -1,
        ];
    }
    $metricNames = ['rss_bytes', 'fd_count', 'queue_depth', 'unrecoverable_backlog', 'security_operation_retention_backlog', 'auth_rate_limit_retention_backlog', 'worker_exit_total', 'worker_restart_total', 'unauthorized_allow_total', 'data_corruption_total', 'event_loop_lag_ms', 'pool_wait_ms'];
    $metrics = [];
    foreach ($metricNames as $metricName) $metrics[$metricName] = '/metrics/' . $metricName;
    $document = [
        'schema' => 'sand-iam.endurance-plan/v1', 'candidate' => $candidate,
        'acceptance_run_id' => $runId, 'duration_seconds' => 86400, 'interval_seconds' => 60,
        'request_timeout_ms' => 5000, 'allow_loopback_http' => false,
        'approved_hosts' => ['__REQUIRED_APPROVED_HOST__'], 'targets' => $targets, 'metrics' => $metrics,
        'thresholds' => [
            'max_error_rate' => 0, 'max_gap_seconds' => -1, 'max_rss_bytes' => -1,
            'max_rss_slope_bytes_per_hour' => -1, 'max_fd_count' => -1, 'max_fd_slope_per_hour' => -1,
            'max_queue_depth' => -1, 'max_unrecoverable_backlog' => 0,
            'max_security_operation_retention_backlog' => 0, 'max_auth_rate_limit_retention_backlog' => 0,
            'max_worker_exit_delta' => 0,
            'max_worker_restart_delta' => 0, 'max_unauthorized_allow_delta' => 0, 'max_data_corruption_delta' => 0,
            'max_event_loop_lag_p99_ms' => -1, 'max_pool_wait_p99_ms' => -1,
        ],
    ];
} elseif ($kind === 'casdoor') {
    $journeyIds = ['webman-api-governance', 'human-auth-mfa-business-api', 'machine-service-action'];
    $journeys = [];
    foreach ($journeyIds as $journeyId) {
        $runs = [];
        foreach (['sandiam', 'casdoor'] as $system) {
            foreach ([1, 2] as $round) {
                $runs[] = [
                    'system' => $system, 'round' => $round,
                    'started_at' => '__REQUIRED_UTC_START__', 'ended_at' => '__REQUIRED_UTC_END__',
                    'duration_seconds' => -1, 'manual_operations' => -1, 'commands' => -1,
                    'recovery_attempts' => -1, 'unresolved_failures' => -1,
                    'business_code_change_points' => -1, 'completed' => false,
                    'result_equivalent' => false, 'security_equivalent' => false, 'cleanup_verified' => false,
                    'evidence' => [[
                        'path' => 'evidence/' . $journeyId . '-' . $system . '-' . $round . '.json',
                        'sha256' => '__REQUIRED_EVIDENCE_SHA256__',
                    ]],
                ];
            }
        }
        $journeys[] = ['id' => $journeyId, 'runs' => $runs];
    }
    $document = [
        'schema' => 'sand-iam.casdoor-comparison/v1', 'candidate' => $candidate,
        'reviewer' => ['id' => '__REQUIRED_REVIEWER_ID__', 'independent' => false, 'webman_experience' => false, 'conflict_statement' => '__REQUIRED_CONFLICT_STATEMENT__'],
        'environment' => ['fingerprint' => '__REQUIRED_ENVIRONMENT_SHA256__', 'host' => '__REQUIRED_HOST__', 'browser' => '__REQUIRED_BROWSER__', 'php' => '__REQUIRED_PHP__', 'postgresql' => '__REQUIRED_POSTGRESQL__', 'network_profile' => '__REQUIRED_NETWORK_PROFILE__'],
        'journeys' => $journeys,
    ];
} elseif ($kind === 'interop') {
    $requiredAssertions = [
        'oidc' => ['discovery', 'authorization_code_pkce', 'userinfo', 'wrong_audience_rejected', 'refresh_replay_rejected', 'revocation_effective'],
        'saml' => ['signed_authn', 'attribute_mapping', 'wrong_audience_rejected', 'assertion_replay_rejected', 'disabled_binding_rejected'],
        'ldap' => ['tls_bind', 'incremental_sync', 'mapping_applied', 'invalid_bind_rejected', 'remote_disable_propagated'],
        'scim' => ['service_provider_discovery', 'user_group_lifecycle', 'filter_pagination', 'invalid_token_rejected', 'deactivation_effective'],
        'cas' => ['login_ticket_validate', 'cas10_validate', 'cas20_validate', 'cas30_attributes', 'wrong_service_rejected', 'ticket_replay_rejected'],
        'kerberos-spnego' => ['gssapi_negotiate', 'principal_mapping', 'wrong_spn_rejected', 'unmapped_principal_rejected', 'replay_rejected'],
        'radius' => ['access_accept', 'access_reject', 'wrong_secret_rejected', 'request_replay_rejected', 'accounting_recorded'],
    ];
    $cases = [];
    foreach ($requiredAssertions as $id => $assertionNames) {
        $cases[] = [
            'id' => $id,
            'client' => ['name' => '__REQUIRED_STANDARD_CLIENT__', 'version' => '__REQUIRED_CLIENT_VERSION__', 'project_url' => 'https://__REQUIRED_CLIENT_PROJECT__', 'standard' => false],
            'counterpart' => ['name' => '__REQUIRED_REAL_COUNTERPART__', 'version' => '__REQUIRED_COUNTERPART_VERSION__', 'endpoint' => 'https://__REQUIRED_CONTROLLED_ENDPOINT__', 'real' => false, 'controlled' => false],
            'started_at' => '__REQUIRED_UTC_START__', 'ended_at' => '__REQUIRED_UTC_END__',
            'assertions' => array_fill_keys($assertionNames, false),
            'completed' => false, 'cleanup_verified' => false, 'unresolved_failures' => -1,
            'evidence' => [['path' => 'evidence/' . $id . '.json', 'sha256' => '__REQUIRED_EVIDENCE_SHA256__']],
        ];
    }
    $document = [
        'schema' => 'sand-iam.protocol-interop/v1', 'candidate' => $candidate,
        'reviewer' => ['id' => '__REQUIRED_REVIEWER_ID__', 'independent' => false, 'conflict_statement' => '__REQUIRED_CONFLICT_STATEMENT__'],
        'environment' => ['fingerprint' => '__REQUIRED_ENVIRONMENT_SHA256__', 'host' => '__REQUIRED_HOST__', 'postgresql' => '__REQUIRED_POSTGRESQL__', 'network_profile' => '__REQUIRED_NETWORK_PROFILE__'],
        'cases' => $cases,
    ];
} elseif ($kind === 'recovery') {
    $countKeys = ['sand_iam_tables', 'migration_rows', 'organizations', 'applications', 'environments', 'identities', 'active_credentials', 'revoked_credentials', 'revoked_sessions', 'audit_rows'];
    $state = ['logical_state_sha256' => '__REQUIRED_LOGICAL_STATE_SHA256__', 'audit_chain_sha256' => '__REQUIRED_AUDIT_CHAIN_SHA256__'];
    foreach ($countKeys as $key) $state[$key] = -1;
    $checks = [];
    foreach (['archive_list_complete', 'restore_single_transaction', 'migration_ledger_equal', 'authorization_allow_equal', 'authorization_deny_equal', 'revoked_access_denied', 'revoked_sessions_not_resurrected', 'audit_chain_equal', 'signature_verification_equal', 'post_restore_audit_append', 'host_objects_unchanged', 'other_plugins_unchanged', 'cleanup_verified'] as $check) $checks[$check] = false;
    $evidence = [];
    foreach (['backup-command', 'archive-list', 'restore-command', 'source-state', 'restored-state', 'business-probes', 'cleanup'] as $type) {
        $evidence[] = ['type' => $type, 'path' => 'evidence/' . $type . '.json', 'sha256' => '__REQUIRED_EVIDENCE_SHA256__'];
    }
    $document = [
        'schema' => 'sand-iam.backup-recovery/v1', 'candidate' => $candidate,
        'reviewer' => ['id' => '__REQUIRED_REVIEWER_ID__', 'independent' => false, 'conflict_statement' => '__REQUIRED_CONFLICT_STATEMENT__'],
        'environment' => ['fingerprint' => '__REQUIRED_ENVIRONMENT_SHA256__', 'host' => '__REQUIRED_HOST__', 'postgresql' => '__REQUIRED_POSTGRESQL__', 'source_dsn_sha256' => '__REQUIRED_SOURCE_DSN_SHA256__', 'restore_dsn_sha256' => '__REQUIRED_RESTORE_DSN_SHA256__', 'restore_target_precreated' => false, 'production_target' => true],
        'backup' => ['format' => 'custom', 'archive_sha256' => '__REQUIRED_ARCHIVE_SHA256__', 'archive_list_sha256' => '__REQUIRED_ARCHIVE_LIST_SHA256__', 'pg_dump_version' => '__REQUIRED_PG_DUMP_VERSION__', 'pg_restore_version' => '__REQUIRED_PG_RESTORE_VERSION__', 'started_at' => '__REQUIRED_UTC_START__', 'completed_at' => '__REQUIRED_UTC_END__', 'restore_flags' => ['--exit-on-error', '--single-transaction', '--no-owner', '--no-acl']],
        'state' => ['source' => $state, 'restored' => $state], 'checks' => $checks,
        'unresolved_failures' => -1, 'evidence' => $evidence,
    ];
} else {
    $stepIds = ['package-verification', 'runtime-preflight', 'fresh-install', 'initial-configuration', 'human-business-app', 'machine-service', 'failure-recovery', 'uninstall-cleanup'];
    $steps = [];
    foreach ($stepIds as $id) {
        $steps[] = ['id' => $id, 'started_at' => '__REQUIRED_UTC_START__', 'ended_at' => '__REQUIRED_UTC_END__', 'duration_seconds' => -1, 'manual_operations' => -1, 'recovery_attempts' => -1, 'completed' => false, 'evidence' => [['path' => 'evidence/' . $id . '.json', 'sha256' => '__REQUIRED_EVIDENCE_SHA256__']]];
    }
    $assertions = [];
    foreach (['public_docs_only', 'no_internal_material', 'no_undocumented_commands', 'package_signature_verified', 'fresh_install_succeeded', 'configuration_preflight_succeeded', 'human_business_side_effect_verified', 'machine_business_side_effect_verified', 'allow_deny_revocation_verified', 'dual_audit_verified', 'error_recovery_succeeded', 'host_other_plugins_unchanged', 'no_secret_retained', 'cleanup_verified'] as $assertion) $assertions[$assertion] = false;
    $document = [
        'schema' => 'sand-iam.independent-delivery/v1', 'candidate' => $candidate,
        'participant' => ['id' => '__REQUIRED_PARTICIPANT_ID__', 'independent' => false, 'contributed_code' => true, 'prior_sandiam_experience' => true, 'developer_assistance_requests' => -1, 'conflict_statement' => '__REQUIRED_CONFLICT_STATEMENT__'],
        'environment' => ['fingerprint' => '__REQUIRED_ENVIRONMENT_SHA256__', 'host' => '__REQUIRED_FRESH_HOST__', 'fresh_host' => false, 'sandadmin_revision' => '__REQUIRED_SANDADMIN_REVISION__', 'sandpackage_version' => '__REQUIRED_SANDPACKAGE_VERSION__', 'php' => '__REQUIRED_PHP__', 'postgresql' => '__REQUIRED_POSTGRESQL__'],
        'public_materials' => ['README.md', 'CONTRIBUTING.md', 'SECURITY.md', 'docs/user-guide/installation-and-upgrade.md', 'docs/user-guide/configuration-reference.md', 'docs/user-guide/application-integration.md', 'docs/user-guide/application-user-guide.md', 'docs/user-guide/sand-iam-operator-guide.md', 'docs/user-guide/troubleshooting.md', 'docs/user-guide/security-hardening.md', 'docs/user-guide/backup-and-restore.md', 'docs/user-guide/release-package-verification.md'],
        'steps' => $steps, 'assertions' => $assertions, 'unresolved_failures' => -1,
    ];
}

$encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($output, $encoded, LOCK_EX) !== strlen($encoded) || !chmod($output, 0600)) throw new RuntimeException('cannot write acceptance template');
echo 'SandIAM ' . $kind . ' acceptance template written: ' . $output . ' candidate_archive_sha256=' . $candidate['archive_sha256'] . PHP_EOL;
