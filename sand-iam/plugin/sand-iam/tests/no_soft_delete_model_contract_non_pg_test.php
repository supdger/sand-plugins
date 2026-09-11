<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** Final lifecycle contract: only tables without delete_time may disable host soft deletion. */
function noSoftDeleteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "No-soft-delete model contract failed: {$message}\n");
        exit(1);
    }
}

$package = dirname(__DIR__);
$install = file_get_contents($package . '/install.sql');
noSoftDeleteAssert(is_string($install), 'generated install.sql is unavailable');

$expected = [
    'InitializationDraft' => 'sand_iam_initialization_draft',
    'InitializationDraftRevision' => 'sand_iam_initialization_draft_revision',
    'PolicyVersion' => 'sand_iam_policy_version',
    'SecurityOperation' => 'sand_iam_security_operation',
    'ServiceInvocationOperation' => 'sand_iam_service_invocation_operation',
    'ServiceQuotaBucket' => 'sand_iam_service_quota_bucket',
];
$tableHasDeleteTime = static function (string $table) use ($install): bool {
    $quoted = preg_quote($table, '/');
    preg_match('/CREATE TABLE IF NOT EXISTS ' . $quoted . '\s*\((.*?)\n\);/s', $install, $create);
    return (isset($create[1]) && str_contains($create[1], 'delete_time'))
        || preg_match('/ALTER TABLE ' . $quoted . '\b[^;]*\bdelete_time\b/s', $install) === 1;
};
$actual = [];
foreach (glob($package . '/app/model/*.php') ?: [] as $file) {
    $source = file_get_contents($file);
    if (!is_string($source)) {
        continue;
    }
    preg_match("/protected \\\$table = '([^']+)'/", $source, $table);
    if (!isset($table[1])) {
        continue;
    }
    $usesNoSoftDelete = str_contains($source, 'extends NoSoftDeleteSandIamModel');
    if (!$usesNoSoftDelete && str_contains($source, 'extends AbstractSandIamModel')) {
        noSoftDeleteAssert($tableHasDeleteTime($table[1]), basename($file) . ' inherits host soft deletion but its final table has no delete_time');
    }
    if (!$usesNoSoftDelete) {
        continue;
    }
    preg_match('/final class ([A-Za-z0-9_]+) extends NoSoftDeleteSandIamModel/', $source, $class);
    noSoftDeleteAssert(isset($class[1], $table[1]), basename($file) . ' has no parseable class/table contract');
    $actual[$class[1]] = $table[1];
}
ksort($actual);
ksort($expected);
noSoftDeleteAssert($actual === $expected, 'NoSoftDeleteSandIamModel assignments differ from final schema contract');

foreach ($actual as $class => $table) {
    noSoftDeleteAssert(!$tableHasDeleteTime($table), "{$class} disables soft deletion although {$table} has delete_time");
}

echo "No-soft-delete model contract checks passed\n";
