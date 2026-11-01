<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
$files = [
    'version model' => $root . '/plugin/sand-iam/app/model/PolicyVersion.php',
    'version service' => $root . '/plugin/sand-iam/app/service/PolicyVersionService.php',
    'authorizer' => $root . '/plugin/sand-iam/app/runtime/PolicyAuthorizer.php',
    'controller' => $root . '/plugin/sand-iam/app/admin/controller/PolicyController.php',
    'routes' => $root . '/plugin/sand-iam/config/route.php',
    'migration' => $root . '/migrations/029_policy_versioning.pgsql',
    'plugin migration' => $root . '/plugin/sand-iam/migrations/029_policy_versioning.pgsql',
];
$read = static fn (string $name): string => (string) file_get_contents($files[$name]);
require_once $files['authorizer'];

$checks = [
    'immutable version table has version and request uniqueness' => str_contains($read('migration'), 'uk_sand_iam_policy_version_no') && str_contains($read('migration'), 'uk_sand_iam_policy_version_request'),
    'root and plugin migration payloads match' => hash_file('sha256', $files['migration']) === hash_file('sha256', $files['plugin migration']),
    'publisher locks policy and creates new rollback version' => str_contains($read('version service'), 'lock(true)') && str_contains($read('version service'), 'rollback_of_version_id') && str_contains($read('version service'), "'published_version_id'"),
    'publisher fingerprints publish or rollback semantics and conflicts safely' => str_contains($read('version service'), 'request_fingerprint') && str_contains($read('version service'), "'operation' => \$operation") && str_contains($read('version service'), 'SAND_IAM_IDEMPOTENCY_CONFLICT'),
    'runtime delegates policy decisions to unique Casbin engine' => str_contains($read('authorizer'), 'new CasbinPolicyEngine') && !str_contains($read('authorizer'), 'selectLowestPriorityRules') && !str_contains($read('authorizer'), 'belongsToSubject'),
    'runtime reads published snapshots and audits version ids' => str_contains($read('authorizer'), 'snapshotLoader->load') && str_contains($read('authorizer'), 'published_version_ids'),
    'simulation is read-only and redacts explanation' => static function () use ($read, $files): bool { $source = $read('authorizer'); $start = strpos($source, 'public function simulate'); $end = strpos($source, 'public function assertScope'); return $start !== false && $end !== false && !str_contains(substr($source, $start, $end - $start), 'auditWriter->write') && str_contains($source, 'missing_context') && str_contains((string) file_get_contents(dirname($files['authorizer']) . '/CasbinPolicyEngine.php'), '[已脱敏]'); },
    'management endpoints keep policy application immutable and permission protected' => str_contains($read('controller'), 'SAND_IAM_POLICY_APPLICATION_IMMUTABLE') && str_contains($read('controller'), "'sand_iam:policy:read'") && str_contains($read('controller'), "'sand_iam:policy:publish'") && str_contains($read('controller'), "Cache-Control', 'no-store'") && str_contains($read('routes'), '/policy/simulate') && str_contains($read('routes'), '/policy/rollback'),
    'lifecycle manifest and uninstall include migration 029' => str_contains((string) file_get_contents($root . '/tools/build-lifecycle.php'), '029_policy_versioning.pgsql') && str_contains((string) file_get_contents($root . '/lifecycle/remove.pgsql'), 'sand_iam_policy_version'),
];
$passed = 0;
foreach ($checks as $label => $check) { $ok = is_callable($check) ? $check() : $check; echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL; $passed += $ok ? 1 : 0; }
$total = count($checks); echo "Policy versioning non-PG contract: passed={$passed}/{$total}; failed=" . ($total - $passed) . PHP_EOL; exit($passed === $total ? 0 : 1);
