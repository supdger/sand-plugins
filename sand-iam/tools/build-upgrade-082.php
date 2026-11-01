<?php

declare(strict_types=1);

require_once __DIR__ . '/lifecycle-sql.php';

/**
 * Build-only SQL for standard 0.8.2 upgrades.
 *
 * The reviewed fixture is the exact six-group PDO capture made by the public
 * SandPackage 0.1.10-rc.1 PostgresHostCatalogFingerprint on PostgreSQL 18.6.
 * It contains catalog structure only. tools/ is excluded from runtime ZIPs.
 * The SQL compares all typed rows as jsonb, preserving array order and nulls.
 * No provider API, schema mutation, or loose SQL normalization is introduced.
 */
function sandIamBuild082Update(string $root): string
{
    $catalogPath = $root . '/tools/resources/schema-043-catalog.json';
    $rawCatalog = file_get_contents($catalogPath);
    if (!is_string($rawCatalog)
        || hash('sha256', $rawCatalog) !== '4adf9cd8200fab6f95bc11542d3a5f973aebf78107f31d55a235faa1dc34ecb7') {
        throw new RuntimeException('0.8.2 requires the exact reviewed revision043 catalog capture');
    }
    $catalog = json_decode($rawCatalog, true, 32, JSON_THROW_ON_ERROR);
    if (array_keys($catalog) !== ['relations', 'columns', 'constraints', 'indexes', 'triggers', 'policies']
        || array_map('count', $catalog) !== [
            'relations' => 259, 'columns' => 1521, 'constraints' => 1437,
            'indexes' => 310, 'triggers' => 0, 'policies' => 0,
        ]) {
        throw new RuntimeException('0.8.2 reviewed catalog groups are incomplete');
    }
    $oldAdmission = file_get_contents($root . '/lifecycle/update-073-or-075-or-076-to-080-preflight.pgsql');
    $migration = file_get_contents($root . '/migrations/043_scope_audit_event_key.pgsql');
    if (!is_string($oldAdmission) || !is_string($migration)
        || hash('sha256', $oldAdmission) !== '4c4e867f013f33571129ea0ad62a00952e4a75fd4177ef68caf8fdb997e2c3f2'
        || hash('sha256', $migration) !== '25b37adbb3cf28aebaf997fd25322b6c4798a6ce734d59d81896d32f0174be12') {
        throw new RuntimeException('0.8.2 requires the original immutable revision043 source');
    }
    $admissionStart = strpos($oldAdmission, 'DO $$');
    $admissionEnd = strrpos($oldAdmission, 'END $$;');
    $structureStart = strpos($oldAdmission, "BEGIN\n");
    $structureEnd = strpos($oldAdmission, '    WITH expected(');
    $valuesStart = strpos($oldAdmission, "        VALUES\n");
    $valuesEnd = strpos($oldAdmission, "\n    ), difference AS");
    if ($admissionStart === false || $admissionEnd === false
        || $structureStart === false || $structureEnd === false
        || $valuesStart === false || $valuesEnd === false) {
        throw new RuntimeException('Cannot reuse the frozen published43 admission');
    }
    $oldAdmissionBody = substr($oldAdmission, $admissionStart, $admissionEnd + strlen('END $$;') - $admissionStart);
    $ledgerStructure = substr($oldAdmission, $structureStart + strlen("BEGIN\n"), $structureEnd - ($structureStart + strlen("BEGIN\n")));
    $values = substr($oldAdmission, $valuesStart + strlen("        VALUES\n"), $valuesEnd - ($valuesStart + strlen("        VALUES\n")));
    preg_match_all("/\\('([0-9]{3}_[^']+\\.pgsql)', (\\d+), '([a-f0-9]{64})', '([^']+)'\\)/", $values, $rows);
    if (count($rows[0]) !== 43) throw new RuntimeException('Published43 ledger cannot be reused exactly');
    $values .= ",\n            ('043_scope_audit_event_key.pgsql', 43, '937a58b5cd1ceb3ada3ae0e0cd2a34166d53db87b60a1c096a5a55dd5c549994', '0.8.0')";

    // Query columns and ordering are copied exactly from the captured public
    // provider (sha256 b99477b515ae65c9d66a210ffbb4c937305e7e82242a85a3c271efc43f61a37b).
    $match = "left(c.relname, char_length('sand_iam')) = 'sand_iam'";
    $queries = [
        'relations' => "SELECT c.relname, c.relkind::text, c.relpersistence::text, c.relrowsecurity, c.relforcerowsecurity,
            CASE WHEN c.relkind IN ('v','m') THEN pg_get_viewdef(c.oid, true) ELSE NULL END AS view_definition
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND $match ORDER BY c.relname, c.relkind",
        'columns' => "SELECT c.relname, a.attnum, a.attname, a.atttypid::text, a.atttypmod, a.attnotnull,
            a.attidentity, a.attgenerated, pg_get_expr(d.adbin,d.adrelid) AS default_expression
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_attribute a ON a.attrelid=c.oid
            LEFT JOIN pg_attrdef d ON d.adrelid=c.oid AND d.adnum=a.attnum
            WHERE n.nspname='public' AND $match AND a.attnum>0 AND NOT a.attisdropped
            ORDER BY c.relname,a.attnum",
        'constraints' => "SELECT c.relname, con.conname, pg_get_constraintdef(con.oid, true) AS definition
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_constraint con ON con.conrelid=c.oid
            WHERE n.nspname='public' AND $match ORDER BY c.relname,con.conname",
        'indexes' => "SELECT c.relname, i.relname AS index_name, pg_get_indexdef(i.oid) AS definition
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_index x ON x.indrelid=c.oid
            JOIN pg_class i ON i.oid=x.indexrelid
            WHERE n.nspname='public' AND $match ORDER BY c.relname,i.relname",
        'triggers' => "SELECT c.relname, t.tgname, pg_get_triggerdef(t.oid, true) AS definition
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_trigger t ON t.tgrelid=c.oid
            WHERE n.nspname='public' AND $match AND NOT t.tgisinternal
            ORDER BY c.relname,t.tgname",
        'policies' => "SELECT c.relname, p.polname, p.polcmd, p.polpermissive,
            pg_get_expr(p.polqual,p.polrelid) AS using_expression,
            pg_get_expr(p.polwithcheck,p.polrelid) AS check_expression
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_policy p ON p.polrelid=c.oid
            WHERE n.nspname='public' AND $match ORDER BY c.relname,p.polname",
    ];
    $catalogChecks = '';
    foreach ($queries as $group => $query) {
        $expected = json_encode($catalog[$group], JSON_THROW_ON_ERROR);
        $tag = '$iam082_' . $group . '$';
        if (str_contains($expected, $tag)) throw new RuntimeException('Catalog dollar-quote collision');
        $catalogChecks .= "    SELECT COALESCE(jsonb_agg(to_jsonb(captured)), '[]'::jsonb) INTO actual_catalog\n"
            . "    FROM ({$query}) captured;\n"
            . "    IF actual_catalog IS DISTINCT FROM {$tag}{$expected}{$tag}::jsonb THEN\n"
            . "        RAISE EXCEPTION 'SandIAM 0.8.2 revision043 {$group} catalog differs from the exact reviewed structure';\n"
            . "    END IF;\n";
    }
    $migrationBody = withoutOuterTransaction($migration, '043_scope_audit_event_key.pgsql');
    foreach (['$iam082_prior$', '$iam082_migration$'] as $tag) {
        if (str_contains($oldAdmissionBody . $migrationBody, $tag)) {
            throw new RuntimeException('Migration dollar-quote collision');
        }
    }
    $upgrade = <<<'SQL'
DO $sand_iam_upgrade_082$
DECLARE
    ledger_pk text;
    mismatch_count integer;
    source_rows integer;
    actual_catalog jsonb;
BEGIN
    IF current_schema() IS DISTINCT FROM 'public' THEN
        RAISE EXCEPTION 'SandIAM 0.8.2 requires the public schema';
    END IF;
    IF EXISTS (
        SELECT 1 FROM pg_index x JOIN pg_class c ON c.oid=x.indrelid
        JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND left(c.relname, char_length('sand_iam'))='sand_iam'
          AND (NOT x.indisvalid OR NOT x.indisready OR NOT x.indislive)
    ) THEN
        RAISE EXCEPTION 'SandIAM 0.8.2 refuses invalid, unfinished or retired plugin indexes';
    END IF;
SQL;
    $upgrade .= "\n" . $ledgerStructure
        . "    SELECT count(*) INTO source_rows FROM public.sand_iam_schema_migration;\n"
        . "    IF source_rows = 43 THEN\n"
        . "        IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public'\n"
        . "            AND table_name='sand_iam_audit_log' AND column_name='event_key') THEN\n"
        . "            RAISE EXCEPTION 'SandIAM 0.8.2 refuses revision043 structure with a missing or substituted ledger row';\n"
        . "        END IF;\n"
        . "        EXECUTE \$iam082_prior\${$oldAdmissionBody}\$iam082_prior\$;\n"
        . "        EXECUTE \$iam082_migration\${$migrationBody}\$iam082_migration\$;\n"
        . "    ELSIF source_rows <> 44 THEN\n"
        . "        RAISE EXCEPTION 'SandIAM 0.8.2 requires the exact published43 or revision04344 ledger';\n"
        . "    END IF;\n"
        . "    WITH expected(migration_file, revision, checksum, package_version) AS (VALUES\n"
        . $values . "\n"
        . "    ), difference AS (\n"
        . "        SELECT e.migration_file FROM expected e FULL OUTER JOIN public.sand_iam_schema_migration a\n"
        . "          ON a.migration_file = e.migration_file\n"
        . "        WHERE e.migration_file IS NULL OR a.migration_file IS NULL\n"
        . "           OR a.revision IS DISTINCT FROM e.revision OR a.checksum::text IS DISTINCT FROM e.checksum\n"
        . "           OR a.package_version IS DISTINCT FROM e.package_version\n"
        . "    ) SELECT count(*) INTO mismatch_count FROM difference;\n"
        . "    IF mismatch_count <> 0 OR (SELECT count(*) FROM public.sand_iam_schema_migration) <> 44 THEN\n"
        . "        RAISE EXCEPTION 'SandIAM 0.8.2 requires the exact complete revision043 ledger';\n"
        . "    END IF;\n"
        . $catalogChecks
        . "END \$sand_iam_upgrade_082\$;\n";

    // The public PostgreSQL executor understands multiline dollar-quoted DO
    // statements. Preserve the complete embedded SQL and its comment newlines;
    // folding lines could turn a leading "--" into a comment on every command.
    return "-- SandIAM 0.8.2 standard upgrade: strict43 -> immutable043, strict44 -> no DDL.\n"
        . "-- Catalog input: public SandPackage 0.1.10-rc.1 / PostgreSQL 18.6 exact six-group capture.\n"
        . "BEGIN ISOLATION LEVEL REPEATABLE READ;\n" . $upgrade . "\nCOMMIT;\n";
}
