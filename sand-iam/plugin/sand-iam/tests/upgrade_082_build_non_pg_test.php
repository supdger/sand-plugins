<?php

declare(strict_types=1);

// behavior-test-gate: static-rule
// Build/declaration checks only. Real PostgreSQL acceptance is a separate gate.
$root = dirname(__DIR__, 3);
require_once $root . '/tools/build-upgrade-082.php';
require_once $root . '/tools/package-payload-policy.php';
$sql = sandIamBuild082Update($root);
if ($sql !== file_get_contents($root . '/update.sql')
    || $sql !== file_get_contents($root . '/plugin/sand-iam/update.sql')) {
    throw new RuntimeException('Packaged 082 update differs from its reviewed inputs');
}
if (!str_contains($sql, "\nBEGIN ISOLATION LEVEL REPEATABLE READ;\nDO \$sand_iam_upgrade_082\$\n")
    || !str_ends_with($sql, "END \$sand_iam_upgrade_082\$;\n\nCOMMIT;\n")) {
    throw new RuntimeException('Atomic multiline update framing changed');
}
echo "[PASS] update retains outer transaction and real multiline DO framing\n";
$tag = '$iam082_migration$';
$start = strpos($sql, $tag);
$end = $start === false ? false : strpos($sql, $tag, $start + strlen($tag));
$embedded = $start === false || $end === false ? null : substr($sql, $start + strlen($tag), $end - $start - strlen($tag));
$original = withoutOuterTransaction((string) file_get_contents($root . '/migrations/043_scope_audit_event_key.pgsql'), '043_scope_audit_event_key.pgsql');
if ($embedded !== $original || !str_contains($embedded, "\nLOCK TABLE")
    || !str_contains($embedded, "\nINSERT INTO sand_iam_schema_migration")
    || !str_contains($embedded, "\nDO $$\n")) {
    throw new RuntimeException('Embedded043 lost executable commands, comments, newlines or string/DO semantics');
}
echo "[PASS] actual EXECUTE migration string equals original043 body byte-for-byte, including comment boundaries and executable LOCK/DO/INSERT\n";
$executorFile = $argv[1] ?? null;
if (is_string($executorFile) && $executorFile !== '') {
    require_once $executorFile;
    $statements = \plugin\sandpackage\app\service\PostgresLifecycleSqlExecutor::split($sql);
    if (count($statements) !== 3 || !str_contains($statements[1], $original)) {
        throw new RuntimeException('Public executor split changed the embedded043 multiline body');
    }
    echo "[PASS] supplied public PostgreSQL executor returns exactly BEGIN, full multiline DO, COMMIT\n";
    $migrationStatements = \plugin\sandpackage\app\service\PostgresLifecycleSqlExecutor::split($embedded);
    if (count($migrationStatements) !== 4
        || !str_contains($migrationStatements[0], 'LOCK TABLE')
        || !str_contains($migrationStatements[1], 'ALTER TABLE sand_iam_audit_log')
        || !str_contains($migrationStatements[2], 'INSERT INTO sand_iam_schema_migration')
        || !str_contains($migrationStatements[3], 'did not close the exact 001-043 ledger')) {
        throw new RuntimeException('Actual EXECUTE string no longer contains all four original executable statements');
    }
    echo "[PASS] actual EXECUTE string contains executable LOCK, guarded ALTER, ledger INSERT and closure DO under the public lexer\n";
}
$fixture = json_decode((string) file_get_contents($root . '/tools/resources/schema-043-catalog.json'), true, 32, JSON_THROW_ON_ERROR);
foreach ($fixture as $group => $rows) {
    $tag = '$iam082_' . $group . '$';
    $start = strpos($sql, $tag);
    $end = $start === false ? false : strpos($sql, $tag, $start + strlen($tag));
    if ($start === false || $end === false
        || json_decode(substr($sql, $start + strlen($tag), $end - $start - strlen($tag)), true, 32, JSON_THROW_ON_ERROR) !== $rows) {
        throw new RuntimeException('Typed catalog group changed while entering SQL: ' . $group);
    }
    echo "[PASS] complete {$group} capture retains array order, boolean, number, string and null types\n";
}
if (in_array('tools/resources/schema-043-catalog.json', sandIamPayloadFiles($root, true), true)) {
    throw new RuntimeException('Build-only catalog fixture was shipped as runtime payload');
}
echo "[PASS] catalog capture is a build-only input, absent from runtime payload\n";
$branchStart = strpos($sql, 'IF source_rows = 43 THEN');
$branchEnd = strpos($sql, 'ELSIF source_rows <> 44 THEN', $branchStart === false ? 0 : $branchStart);
if ($branchStart === false || $branchEnd === false
    || substr_count($sql, 'EXECUTE ') !== 2
    || substr_count(substr($sql, $branchStart, $branchEnd - $branchStart), 'EXECUTE ') !== 2
    || !str_contains($sql, 'FULL OUTER JOIN public.sand_iam_schema_migration')
    || !str_contains($sql, "actual_catalog IS DISTINCT FROM \$iam082_policies\$[]\$iam082_policies\$::jsonb")) {
    throw new RuntimeException('43 mutation / 44 validation boundary changed');
}
echo "[PASS] only the 43 branch contains prior admission and original043 execution; complete44 compares all ledger rows and catalog groups\n";
$temp = sys_get_temp_dir() . '/iam082-build-contract-' . bin2hex(random_bytes(5));
$files = ['tools/resources/schema-043-catalog.json',
    'lifecycle/update-073-or-075-or-076-to-080-preflight.pgsql',
    'migrations/043_scope_audit_event_key.pgsql'];
$restore = [];
try {
    foreach ($files as $relative) {
        $target = $temp . '/' . $relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
        $restore[$relative] = (string) file_get_contents($root . '/' . $relative);
        file_put_contents($target, $restore[$relative]);
    }
    if (sandIamBuild082Update($temp) !== $sql) throw new RuntimeException('Isolated compiler reproduction differs');
    echo "[PASS] independent input copy reproduces exact SQL bytes\n";
    foreach ($fixture as $group => $rows) {
        $mutated = $fixture;
        if ($rows === []) $mutated[$group][] = ['unexpected' => true];
        else array_pop($mutated[$group]);
        file_put_contents($temp . '/' . $files[0], json_encode($mutated, JSON_THROW_ON_ERROR));
        $rejected = false;
        try { sandIamBuild082Update($temp); } catch (RuntimeException) { $rejected = true; }
        if (!$rejected) throw new RuntimeException('Unreviewed catalog accepted: ' . $group);
        file_put_contents($temp . '/' . $files[0], $restore[$files[0]]);
        echo "[PASS] modified {$group} build input rejected before output\n";
    }
    foreach (array_slice($files, 1) as $relative) {
        file_put_contents($temp . '/' . $relative, $restore[$relative] . "\n");
        $rejected = false;
        try { sandIamBuild082Update($temp); } catch (RuntimeException) { $rejected = true; }
        if (!$rejected) throw new RuntimeException('Historical immutable input accepted after modification: ' . $relative);
        file_put_contents($temp . '/' . $relative, $restore[$relative]);
        echo "[PASS] immutable historical admission/migration mutation rejected\n";
    }
} finally {
    foreach ($files as $relative) if (is_file($temp . '/' . $relative)) unlink($temp . '/' . $relative);
    foreach (['tools/resources', 'tools', 'lifecycle', 'migrations', ''] as $directory) {
        if (is_dir($temp . ($directory === '' ? '' : '/' . $directory))) rmdir($temp . ($directory === '' ? '' : '/' . $directory));
    }
}
echo "082 build contract passed; no PostgreSQL SQL was executed\n";
