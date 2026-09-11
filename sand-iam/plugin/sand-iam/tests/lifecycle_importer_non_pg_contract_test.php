<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

function lifecycleFail(string $message): never
{
    fwrite(STDERR, "SandIAM lifecycle importer contract failed: {$message}\n");
    exit(1);
}

function lifecycleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        lifecycleFail($message);
    }
}

/** @return list<string> */
function importedStatements(string $sql, string $name): array
{
    $statements = [];
    $pending = '';
    foreach (preg_split('/(?<=\n)/', $sql) ?: [] as $line) {
        // Keep this behavior aligned with Saithink\Saipackage\service\Server::importSql.
        if (str_starts_with($line, '--') || $line === '' || str_starts_with($line, '/*')) {
            continue;
        }
        $pending .= $line;
        if (str_ends_with(trim($line), ';')) {
            lifecycleAssert(!str_starts_with(ltrim($pending), '\\'), "{$name} contains a psql-only command");
            $statements[] = $pending;
            $pending = '';
        }
    }
    lifecycleAssert(trim($pending) === '', "{$name} leaves an unterminated importer buffer");

    return $statements;
}

$root = dirname(__DIR__, 3);
$package = $root . '/plugin/sand-iam';

foreach (['install.sql', 'update.sql', 'uninstall.sql'] as $name) {
    $rootSql = file_get_contents($root . '/' . $name);
    $packageSql = file_get_contents($package . '/' . $name);
    lifecycleAssert(is_string($rootSql) && $rootSql !== '', "root {$name} is missing");
    lifecycleAssert($rootSql === $packageSql, "root and package {$name} differ");
    lifecycleAssert(!str_contains($rootSql, '\\ir') && !str_contains($rootSql, '\\i '), "{$name} contains a psql include");

    $statements = importedStatements($rootSql, $name);
    lifecycleAssert(count($statements) > 10, "{$name} produced too few importer statements");
    foreach (preg_split('/\R/u', $rootSql) ?: [] as $line) {
        if (str_starts_with(ltrim($line), 'DO $$')) {
            lifecycleAssert(str_ends_with(trim($line), '$$;'), "{$name} contains a multi-line DO block");
        }
    }
}

$rootMigrations = glob($root . '/migrations/*.pgsql') ?: [];
$packageMigrations = glob($package . '/migrations/*.pgsql') ?: [];
$byName = static function (array $files): array {
    $result = [];
    foreach ($files as $file) {
        $result[basename($file)] = hash_file('sha256', $file);
    }
    ksort($result);

    return $result;
};
lifecycleAssert($byName($rootMigrations) === $byName($packageMigrations), 'root and package migration inventories differ');

$runner = file_get_contents($root . '/tools/run-sandpackage-lifecycle.php');
lifecycleAssert(is_string($runner) && $runner !== '', 'guarded SandPackage lifecycle runner is missing');
foreach ([
    "^sand_iam_acceptance_[a-z0-9_]{1,48}$",
    "['install.sql', 'update.sql', 'uninstall.sql']",
    "SELECT current_database() AS database_name",
    "setAsGlobal()",
    '$actualDatabase !== $databaseName',
] as $requiredRunnerContract) {
    lifecycleAssert(str_contains($runner, $requiredRunnerContract), "lifecycle runner is missing {$requiredRunnerContract}");
}
lifecycleAssert(!str_contains($runner, "getenv('DB_NAME')"), 'lifecycle runner trusts the process DB_NAME value');

fwrite(STDOUT, "SandIAM lifecycle importer contract passed\n");
