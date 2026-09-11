<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
$package = $root . '/plugin/sand-iam';

function lifecycleIdempotencyAssert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$generator = file_get_contents($root . '/tools/build-lifecycle.php');
$sourceMigration = $root . '/migrations/027_security_operation_idempotency.pgsql';
$packageMigration = $package . '/migrations/027_security_operation_idempotency.pgsql';
lifecycleIdempotencyAssert(is_string($generator) && str_contains($generator, "'027_security_operation_idempotency.pgsql'"), 'lifecycle generator does not include migration 027');
lifecycleIdempotencyAssert(is_file($sourceMigration) && is_file($packageMigration) && hash_file('sha256', $sourceMigration) === hash_file('sha256', $packageMigration), 'root and package migration 027 differ');
$migration = file_get_contents($sourceMigration);
foreach (['CREATE TABLE IF NOT EXISTS sand_iam_security_operation', 'ADD COLUMN IF NOT EXISTS', 'uk_sand_iam_security_operation_actor_request', 'CREATE INDEX IF NOT EXISTS idx_sand_iam_security_operation_resource'] as $fragment) {
    lifecycleIdempotencyAssert(is_string($migration) && str_contains($migration, $fragment), "migration 027 is not repeatable: {$fragment}");
}
foreach (['install.sql', 'update.sql', 'uninstall.sql'] as $name) {
    $rootFile = $root . '/' . $name;
    $packageFile = $package . '/' . $name;
    lifecycleIdempotencyAssert(is_file($rootFile) && is_file($packageFile) && hash_file('sha256', $rootFile) === hash_file('sha256', $packageFile), "root and package {$name} differ");
}
$install = file_get_contents($root . '/install.sql');
$uninstall = file_get_contents($root . '/uninstall.sql');
lifecycleIdempotencyAssert(is_string($install) && str_contains($install, 'migrations/027_security_operation_idempotency.pgsql'), 'install payload omits migration 027');
lifecycleIdempotencyAssert(is_string($uninstall) && str_contains($uninstall, 'DROP TABLE IF EXISTS sand_iam_security_operation'), 'uninstall payload omits security operation cleanup');

echo 'lifecycle idempotency contract checks passed' . PHP_EOL;
