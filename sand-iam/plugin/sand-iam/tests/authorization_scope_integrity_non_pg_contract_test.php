<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
$migrationPath = $root . '/migrations/041_authorization_scope_integrity.pgsql';
$packageMigrationPath = $root . '/plugin/sand-iam/migrations/041_authorization_scope_integrity.pgsql';
$migration = (string) file_get_contents($migrationPath);
$packageMigration = (string) file_get_contents($packageMigrationPath);
$roleController = (string) file_get_contents($root . '/plugin/sand-iam/app/admin/controller/IdentityRoleController.php');
$typeController = (string) file_get_contents($root . '/plugin/sand-iam/app/admin/controller/IdentityUserTypeController.php');
$phpSdk = (string) file_get_contents($root . '/sdk/php/src/SandIamClient.php');
$phpSdkTest = (string) file_get_contents($root . '/sdk/php/tests/SandIamClientTest.php');
$typescriptSdk = (string) file_get_contents($root . '/sdk/typescript/src/index.ts');
$typescriptSdkTest = (string) file_get_contents($root . '/sdk/typescript/tests/client.test.mjs');
$dartSdk = (string) file_get_contents($root . '/sdk/dart/lib/src/client.dart');
$dartSdkTest = (string) file_get_contents($root . '/sdk/dart/test/client_test.dart');

$checks = [
    'root and package migration copies match' => $migration !== '' && hash('sha256', $migration) === hash('sha256', $packageMigration),
    'migration owns one explicit transaction and fixed lock order' =>
        str_contains($migration, "BEGIN;\n")
        && str_contains($migration, 'IN SHARE ROW EXCLUSIVE MODE;')
        && str_ends_with($migration, "COMMIT;\n"),
    'identity grants persist and constrain application ownership' =>
        str_contains($roleController, "'application_id' => (int) \$identity->application_id")
        && str_contains($typeController, "'application_id' => (int) \$identity->application_id")
        && str_contains($migration, 'fk_sand_iam_identity_role_identity_application')
        && str_contains($migration, 'fk_sand_iam_identity_role_role_application')
        && str_contains($migration, 'fk_sand_iam_identity_user_type_identity_application')
        && str_contains($migration, 'fk_sand_iam_identity_user_type_type_application'),
    'policy targets share the policy application' =>
        str_contains($migration, 'fk_sand_iam_policy_resource_application')
        && str_contains($migration, 'fk_sand_iam_policy_role_application')
        && str_contains($migration, 'fk_sand_iam_policy_identity_application'),
    'published and rollback pointers stay within one policy and application' =>
        str_contains($migration, 'fk_sand_iam_policy_version_policy_application')
        && str_contains($migration, 'fk_sand_iam_policy_version_rollback_owner')
        && str_contains($migration, 'fk_sand_iam_policy_published_version_owner'),
    'policy actions use the public 96-character boundary' =>
        str_contains($migration, 'ALTER TABLE sand_iam_policy ALTER COLUMN action TYPE varchar(96)'),
    'all SDKs enforce and test the same 96-character action boundary' =>
        str_contains($phpSdk, '[a-z0-9._-]{1,95}')
        && str_contains($typescriptSdk, '[a-z0-9._-]{1,95}')
        && str_contains($dartSdk, '[a-z0-9._-]{1,95}')
        && str_contains($phpSdkTest, 'workload-action-96')
        && str_contains($phpSdkTest, 'workload-action-97')
        && str_contains($typescriptSdkTest, 'workload-action-96')
        && str_contains($typescriptSdkTest, 'workload-action-97')
        && str_contains($dartSdkTest, 'workload-action-96')
        && str_contains($dartSdkTest, 'workload-action-97'),
    'policy-version shape checks are installed' =>
        str_contains($migration, 'CHECK (version_no > 0)')
        && str_contains($migration, "CHECK (jsonb_typeof(snapshot) = 'object')")
        && str_contains($migration, "CHECK (snapshot_hash ~ '^[0-9a-f]{64}$')"),
    'migration is self-checksummed and closes ledger 42' => static function () use ($migration): bool {
        if (preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $migration, $match) !== 1) {
            return false;
        }
        return hash('sha256', str_replace($match[1], '__SELF_SHA256__', $migration)) === $match[1]
            && str_contains($migration, "SELECT '041_authorization_scope_integrity.pgsql', 41")
            && str_contains($migration, '(SELECT count(*) FROM sand_iam_schema_migration) <> 42');
    },
];

$passed = 0;
foreach ($checks as $label => $check) {
    $ok = is_callable($check) ? $check() : $check;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $passed += $ok ? 1 : 0;
}
$total = count($checks);
echo "authorization scope integrity non-PG contract: passed={$passed}/{$total}; failed=" . ($total - $passed) . PHP_EOL;
exit($passed === $total ? 0 : 1);
