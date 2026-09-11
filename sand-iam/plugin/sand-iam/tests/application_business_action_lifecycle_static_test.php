<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

// This is intentionally a source-package lifecycle check, not a PostgreSQL
// execution. It verifies the generated install/uninstall payloads
// include the repeatable migration and preserve root/package parity.
$plugin = dirname(__DIR__);
$root = dirname(__DIR__, 3);

function t28Lifecycle(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

foreach (['migrations/028_application_business_action.pgsql', 'install.sql', 'uninstall.sql'] as $relative) {
    $source = $root . '/' . $relative;
    $copy = $plugin . '/' . $relative;
    t28Lifecycle(is_file($source) && is_file($copy), "missing source/package lifecycle artifact {$relative}");
    t28Lifecycle(hash_file('sha256', $source) === hash_file('sha256', $copy), "source/package lifecycle artifact differs: {$relative}");
}
$migration = (string) file_get_contents($root . '/migrations/028_application_business_action.pgsql');
t28Lifecycle(str_contains($migration, 'CREATE TABLE IF NOT EXISTS sand_iam_application_business_action'), '028 does not create application business action table repeatably');
t28Lifecycle(str_contains($migration, "conrelid = 'sand_iam_application_business_action'::regclass"), '028 constraint guard is not scoped to its own table');
t28Lifecycle(!str_contains($migration, 'INSERT INTO sand_iam_application_business_action'), '028 must not auto-claim historical free-string actions');

$install = (string) file_get_contents($root . '/install.sql');
$uninstall = (string) file_get_contents($root . '/uninstall.sql');
t28Lifecycle(str_contains($install, '-- lifecycle source: migrations/028_application_business_action.pgsql'), 'generated install lifecycle omits 028 source marker');
t28Lifecycle(str_contains($install, 'CREATE TABLE IF NOT EXISTS sand_iam_application_business_action'), 'generated install lifecycle omits 028 table');
$drop = strpos($uninstall, 'DROP TABLE IF EXISTS sand_iam_application_business_action;');
$applicationDrop = strpos($uninstall, 'DROP TABLE IF EXISTS sand_iam_application;');
t28Lifecycle($drop !== false && $applicationDrop !== false && $drop < $applicationDrop, 'uninstall drops application before application business actions');

echo 'application business action lifecycle static checks passed' . PHP_EOL;
