<?php

declare(strict_types=1);

/** Create a candidate-bound, deliberately incomplete external acceptance plan. */

require_once __DIR__ . '/release-bundle-attestation.php';

$options = getopt('', ['kind:', 'artifact-manifest:', 'output:', 'plan-output:']);
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
$outputName = basename($options['output']);
try {
    sandIamCanonicalPayloadPath($outputName);
} catch (RuntimeException) {
    throw new RuntimeException('acceptance template output filename is invalid');
}
$output = $outputParent . '/' . $outputName;
sandIamAssertOutputAbsent($output);
$planOutput = null;
if ($kind === 'recovery') {
    if (!is_string($options['plan-output'] ?? null) || trim($options['plan-output']) === '') throw new InvalidArgumentException('--plan-output is required for recovery');
    $planParent = sandIamAssertDirectoryPath(dirname($options['plan-output']), 'recovery plan output directory');
    if ($planParent === $rootPath || str_starts_with($planParent, $rootPath . '/')) throw new RuntimeException('recovery plan output must remain outside sand-iam/');
    $planName = basename($options['plan-output']);
    try {
        sandIamCanonicalPayloadPath($planName);
    } catch (RuntimeException) {
        throw new RuntimeException('recovery plan output filename is invalid');
    }
    $planOutput = $planParent . '/' . $planName;
    if ($planOutput === $output) throw new RuntimeException('recovery report and plan outputs must differ');
    sandIamAssertOutputAbsent($planOutput);
}
$candidate = [
    'version' => $package['version'],
    'archive_sha256' => $package['sha256'],
    'artifact_manifest_sha256' => hash_file('sha256', $manifestPath),
];

if ($kind === 'endurance') {
    $candidate['archive_bytes'] = $package['bytes'];
    $candidate['source_revision'] = ['commit' => $manifest['source_revision']['commit'], 'tree' => $manifest['source_revision']['tree']];
    $runId = 'sand_iam_endurance_' . bin2hex(random_bytes(8));
    $targetDefinitions = [
        'candidate' => ['method' => 'GET', 'effect' => 'read_only', 'authorization_env' => null, 'json_expect' => ['/archive_sha256' => $candidate['archive_sha256'], '/artifact_manifest_sha256' => $candidate['artifact_manifest_sha256']]],
        'health' => ['method' => 'GET', 'effect' => 'read_only', 'authorization_env' => null, 'json_expect' => ['/ok' => true]],
        'allow' => ['method' => 'POST', 'effect' => 'audit_only', 'authorization_env' => 'SAND_IAM_ENDURANCE_ALLOWED', 'json_expect' => ['/allowed' => true]],
        'deny' => ['method' => 'POST', 'effect' => 'audit_only', 'authorization_env' => 'SAND_IAM_ENDURANCE_DENIED', 'json_expect' => ['/allowed' => false]],
        'revoked' => ['method' => 'POST', 'effect' => 'audit_only', 'authorization_env' => 'SAND_IAM_ENDURANCE_REVOKED', 'json_expect' => ['/allowed' => false]],
        'audit' => ['method' => 'POST', 'effect' => 'audit_only', 'authorization_env' => 'SAND_IAM_ENDURANCE_ADMIN', 'json_expect' => ['/acceptance_run_id' => $runId]],
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
            'audit_context' => in_array($category, ['allow', 'deny', 'revoked'], true) ? ['decision' => $category === 'allow' ? 'allow' : $category, 'subject' => '__REQUIRED_AUDIT_SUBJECT__', 'scope' => '__REQUIRED_AUDIT_SCOPE__', 'candidate' => $candidate['archive_sha256'], 'side_effect_pointer' => '/side_effect', 'business_audit_ref_pointer' => '/business_audit_ref'] : null,
            'audit_response' => $category === 'audit' ? ['records_pointer' => '/records', 'request_id_pointer' => '/request_id', 'decision_pointer' => '/decision', 'subject_pointer' => '/subject', 'scope_pointer' => '/scope', 'candidate_pointer' => '/candidate', 'acceptance_run_id_pointer' => '/acceptance_run_id', 'business_audit_ref_pointer' => '/business_audit_ref', 'service_audit_ref_pointer' => '/service_audit_ref', 'side_effect_pointer' => '/side_effect'] : null,
        ];
    }
    $metricNames = ['rss_bytes', 'fd_count', 'queue_depth', 'unrecoverable_backlog', 'unrecoverable_backlog_total', 'security_operation_retention_backlog', 'auth_rate_limit_retention_backlog', 'worker_exit_total', 'worker_restart_total', 'unauthorized_allow_total', 'data_corruption_total', 'event_loop_lag_ms', 'pool_wait_ms'];
    $metrics = [];
    foreach ($metricNames as $metricName) $metrics[$metricName] = '/metrics/' . $metricName;
    $document = [
        'schema' => 'sand-iam.endurance-plan/v2', 'candidate' => $candidate,
        'acceptance_run_id' => $runId, 'environment' => ['id' => '__REQUIRED_ENVIRONMENT_ID__'], 'collector' => ['id' => '__REQUIRED_COLLECTOR_ID__', 'version' => '__REQUIRED_COLLECTOR_VERSION__'], 'duration_seconds' => 86400, 'interval_seconds' => 60, 'max_jitter_seconds' => 30, 'max_clock_skew_seconds' => 2,
        'runtime_identity' => ['boot_id' => '/runtime/boot_id', 'process_group_id' => '/runtime/process_group_id', 'supervisor_restart_total' => '/runtime/supervisor_restart_total'],
        'request_timeout_ms' => 5000, 'allow_loopback_http' => false,
        'approved_hosts' => ['__REQUIRED_APPROVED_HOST__'], 'targets' => $targets, 'metrics' => $metrics,
        'thresholds' => [
            'max_error_rate' => 0, 'max_gap_seconds' => 90, 'max_rss_bytes' => -1,
            'max_rss_slope_bytes_per_hour' => -1, 'max_fd_count' => -1, 'max_fd_slope_per_hour' => -1,
            'max_queue_depth' => -1, 'max_unrecoverable_backlog' => 0, 'max_unrecoverable_backlog_delta' => 0,
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
    $candidate['archive_bytes'] = $package['bytes'];
    $candidate['source_revision'] = ['commit' => $manifest['source_revision']['commit'], 'tree' => $manifest['source_revision']['tree']];
    $runId = 'sand_iam_recovery_' . bin2hex(random_bytes(8));
    $identity = ['system_identifier' => '__REQUIRED_SYSTEM_IDENTIFIER__', 'database_oid' => '__REQUIRED_DATABASE_OID__', 'database_name_hex' => '__REQUIRED_DATABASE_NAME_HEX__'];
    $environment = ['id' => '__REQUIRED_ENVIRONMENT_ID__', 'collector' => ['id' => '__REQUIRED_COLLECTOR_ID__', 'version' => '__REQUIRED_COLLECTOR_VERSION__'], 'source' => $identity, 'target' => $identity];
    $plan = ['schema' => 'sand-iam.backup-recovery-plan/v2', 'run_id' => $runId, 'candidate' => $candidate, 'environment' => $environment, 'collector' => $environment['collector'], 'thresholds' => ['rpo_limit_seconds' => -1, 'rto_limit_seconds' => -1, 'minimum_consistency_lsn' => '__REQUIRED_MINIMUM_POSTGRESQL_LSN__']];
    $planHash = hash('sha256', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    $entityKeys = ['organizations', 'applications', 'environments', 'users', 'groups', 'roles', 'resources', 'policies', 'data_scopes', 'service_identities', 'credentials', 'grants', 'audit', 'outbox', 'sync_cursors', 'idempotency_keys', 'key_versions'];
    $ref = static fn(string $type): array => ['type' => $type, 'id' => '__REQUIRED_' . strtoupper($type) . '_ID__'];
    $org = $ref('organizations'); $app = $ref('applications'); $env = $ref('environments'); $scope = ['organization_ref' => $org, 'application_ref' => $app, 'environment_ref' => $env];
    $record = static fn(array $recordScope): array => ['id' => '__REQUIRED_PSEUDONYMOUS_ID__', 'status' => '__REQUIRED_STATUS__', 'version' => '__REQUIRED_VERSION__', 'scope' => $recordScope];
    $entityLists = [];
    foreach ($entityKeys as $entityKey) $entityLists[$entityKey] = [$record($scope)];
    $entityLists['organizations'] = [$record(['organization_ref' => $org, 'application_ref' => null, 'environment_ref' => null])];
    $entityLists['applications'] = [$record(['organization_ref' => $org, 'application_ref' => $app, 'environment_ref' => null])];
    $entityLists['environments'] = [$record($scope)];
    $entityLists['credentials'] = [[
        'id' => '__REQUIRED_CREDENTIAL_ID__', 'status' => '__REQUIRED_STATUS__', 'version' => '__REQUIRED_VERSION__', 'scope' => $scope,
        'owner_ref' => $ref('service_identities'), 'key_version_ref' => $ref('key_versions'),
    ]];
    $entityLists['key_versions'] = [[
        'id' => '__REQUIRED_KEY_VERSION_ID__', 'status' => '__REQUIRED_STATUS__', 'version' => '__REQUIRED_VERSION__', 'scope' => $scope,
        'credential_ref' => $ref('credentials'),
    ]];
    $state = ['entities' => $entityLists];
    $timeline = [];
    foreach (['source_snapshot', 'consistency_point', 'backup_start', 'backup_end', 'security_changes_end', 'restore_start', 'restore_end', 'reconcile_start', 'reconcile_end', 'probe', 'cleanup'] as $stage) $timeline[] = ['stage' => $stage, 'at' => '__REQUIRED_UTC_MICROSECOND_TIMESTAMP__', 'monotonic_us' => -1];
    $probeKinds = ['allow' => ['allow', 'applied'], 'deny' => ['deny', 'rejected'], 'revoked' => ['deny', 'rejected'], 'cross_tenant' => ['deny', 'rejected'], 'cross_app' => ['deny', 'rejected'], 'cross_env' => ['deny', 'rejected'], 'wrong_audience' => ['deny', 'rejected'], 'replay_or_expired' => ['deny', 'rejected'], 'rotation_old' => ['deny', 'rejected'], 'rotation_new' => ['allow', 'applied']];
    $probes = [];
    foreach ($probeKinds as $type => [$decision, $outcome]) $probes[] = ['type' => $type, 'decision' => $decision, 'outcome' => $outcome, 'actor_ref' => $ref('service_identities'), 'target_actor_ref' => $ref('service_identities'), 'scope_ref' => $env, 'target_scope_ref' => $env, 'credential_ref' => $ref('credentials'), 'credential_owner_ref' => $ref('service_identities'), 'credential_version' => '__REQUIRED_CREDENTIAL_VERSION__', 'security_event_id' => in_array($type, ['revoked', 'rotation_old', 'rotation_new'], true) ? '__REQUIRED_SECURITY_EVENT_ID__' : null, 'candidate' => $candidate, 'run_id' => $runId, 'request_id' => '__REQUIRED_UNIQUE_REQUEST_ID__', 'source_audit_ref' => '__REQUIRED_SOURCE_AUDIT_REF__', 'target_audit_ref' => '__REQUIRED_TARGET_AUDIT_REF__', 'business_side_effect' => $outcome];
    $evidence = [];
    foreach (['timeline', 'state', 'reconcile', 'probes', 'queue', 'encryption', 'cleanup', 'backup'] as $type) $evidence[] = ['type' => $type, 'path' => 'evidence/' . $type . '.json', 'sha256' => '__REQUIRED_EVIDENCE_SHA256__'];
    $delta = static fn(): array => ['entity_ref' => $ref('credentials'), 'status' => '__REQUIRED_STATUS__', 'version' => '__REQUIRED_VERSION__'];
    $event = static fn(string $kind): array => array_filter([
        'event_id' => '__REQUIRED_EVENT_ID__', 'sequence' => -1, 'kind' => $kind, 'entity_ref' => $ref('credentials'), 'before' => ['status' => '__REQUIRED_STATUS__', 'version' => '__REQUIRED_VERSION__'], 'after' => ['status' => '__REQUIRED_STATUS__', 'version' => '__REQUIRED_VERSION__'],
        'new_credential_ref' => $kind === 'credential_rotation' ? $ref('credentials') : null, 'new_before' => $kind === 'credential_rotation' ? ['status' => '__REQUIRED_STATUS__', 'version' => '__REQUIRED_VERSION__'] : null, 'new_after' => $kind === 'credential_rotation' ? ['status' => '__REQUIRED_STATUS__', 'version' => '__REQUIRED_VERSION__'] : null, 'key_version_ref' => $kind === 'credential_rotation' ? $ref('key_versions') : null,
        'source_at' => '__REQUIRED_UTC_MICROSECOND_TIMESTAMP__', 'source_monotonic_us' => -1, 'source_lsn' => '__REQUIRED_POSTGRESQL_LSN__', 'source_audit_ref' => '__REQUIRED_SOURCE_AUDIT_REF__',
    ], static fn(mixed $value): bool => $value !== null);
    $document = [
        'schema' => 'sand-iam.backup-recovery/v3', 'run_id' => $runId, 'candidate' => $candidate,
        'reviewer' => ['id' => '__REQUIRED_REVIEWER_ID__', 'independent' => false, 'conflict_statement' => '__REQUIRED_CONFLICT_STATEMENT__'],
        'environment' => $environment, 'plan_sha256' => $planHash, 'timeline' => $timeline, 'timing' => ['snapshot_us' => -1, 'backup_us' => -1, 'restore_us' => -1, 'reconcile_us' => -1, 'probe_us' => -1, 'cleanup_us' => -1, 'rpo_us' => -1, 'rto_us' => -1],
        'backup' => ['format' => 'custom', 'backup_id' => '__REQUIRED_BACKUP_ID__', 'consistency_lsn' => '__REQUIRED_LSN__', 'raw_dump' => ['path' => '__REQUIRED_RAW_POSTGRES_CUSTOM_DUMP_PATH__', 'sha256' => '__REQUIRED_RAW_DUMP_SHA256__', 'bytes' => -1], 'archive_list' => ['path' => '__REQUIRED_PG_RESTORE_LIST_PATH__', 'sha256' => '__REQUIRED_PG_RESTORE_LIST_SHA256__', 'bytes' => -1], 'rpo_seconds' => -1, 'rpo_limit_seconds' => -1, 'rto_seconds' => -1, 'rto_limit_seconds' => -1],
        'state' => ['source_at_snapshot' => $state, 'restored' => $state],
        'reconcile' => ['source_after_changes' => ['records' => [$delta()]], 'target_after_reconcile' => ['records' => [$delta()]], 'events' => [$event('revocation'), $event('credential_rotation')], 'applications' => [['event_id' => '__REQUIRED_EVENT_ID__', 'entity_ref' => $ref('credentials'), 'target_at' => '__REQUIRED_UTC_MICROSECOND_TIMESTAMP__', 'target_monotonic_us' => -1, 'target_audit_ref' => '__REQUIRED_TARGET_AUDIT_REF__', 'resulting_state' => ['status' => '__REQUIRED_STATUS__', 'version' => '__REQUIRED_VERSION__']]]],
        'probes' => ['cases' => $probes],
        'queue' => ['pending' => -1, 'delivered' => -1, 'dead_letter' => -1, 'idempotency_keys' => -1, 'duplicate_side_effects' => -1, 'business_audit_refs' => [], 'service_audit_refs' => []],
        'encryption' => ['algorithm' => '__REQUIRED_APPROVED_AEAD__', 'format' => '__REQUIRED_ENVELOPE_FORMAT__', 'envelope' => ['path' => '__REQUIRED_ENCRYPTED_ENVELOPE_PATH__', 'sha256' => '__REQUIRED_ENVELOPE_SHA256__', 'bytes' => -1], 'external_key_id' => '__REQUIRED_EXTERNAL_KEY_ID__', 'key_version' => '__REQUIRED_KEY_VERSION__', 'key_fingerprint' => '__REQUIRED_KEY_FINGERPRINT__', 'kms_custody_ref' => '__REQUIRED_KMS_CUSTODY_AUDIT_REF__', 'access_audit_ref' => '__REQUIRED_KEY_ACCESS_AUDIT_REF__', 'retention_until' => '__REQUIRED_RETENTION_TIMESTAMP__', 'restore_key_access_audit_ref' => '__REQUIRED_RESTORE_KEY_ACCESS_AUDIT_REF__'],
        'cleanup' => ['ordered_steps' => [], 'residual_entities' => -1], 'evidence' => $evidence,
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
if ($kind === 'recovery') sandIamPublishNewAttestation(dirname($planOutput), $planOutput, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
sandIamPublishNewAttestation($outputParent, $output, $encoded);
echo 'SandIAM ' . $kind . ' acceptance template written: ' . $output . ' candidate_archive_sha256=' . $candidate['archive_sha256'] . PHP_EOL;
