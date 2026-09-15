<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
$package = $root . '/plugin/sand-iam';
$migrations = $root . '/migrations';
$packageMigrations = $package . '/migrations';

/** @param bool $ok */
function release070Assert(string $label, bool $ok): void
{
    static $passed = 0;
    static $total = 0;
    ++$total;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        throw new RuntimeException("release 0.7.0 contract failed: {$label}");
    }
    ++$passed;
}

function release070NormalizeCheckDefinition(string $definition): string
{
    $normalized = strtolower($definition);
    $normalized = preg_replace('/::[a-z_][a-z0-9_]*(?:\s+varying)?(?:\[\])?/', '', $normalized);
    $normalized = is_string($normalized) ? preg_replace('/\s+/', '', $normalized) : null;
    $normalized = is_string($normalized) ? preg_replace('/[()]/', '', $normalized) : null;
    if (!is_string($normalized)) {
        throw new RuntimeException('could not normalize CHECK definition fixture');
    }
    return $normalized;
}

/** @return list<string> */
function release070PayloadSources(string $sql): array
{
    preg_match_all('/^-- lifecycle source: migrations\/([^\r\n]+)$/m', $sql, $matches);
    return $matches[1] ?? [];
}

$published021 = 'f263ec1450bbd15d883c1d5b0f3a0fcfd15db602df9d120b1049a0c4cc428db9';
$migration021 = $migrations . '/021_admin_permission_catalog.pgsql';
$package021 = $packageMigrations . '/021_admin_permission_catalog.pgsql';
release070Assert('published 0.6.0 migration 021 hash is immutable in root and package',
    hash_file('sha256', $migration021) === $published021 && hash_file('sha256', $package021) === $published021);

$migration033 = (string) file_get_contents($migrations . '/033_identity_group_role.pgsql');
$package033 = (string) file_get_contents($packageMigrations . '/033_identity_group_role.pgsql');
release070Assert('033 is byte-identical and owns one explicit transaction before schema mutation',
    hash('sha256', $migration033) === hash('sha256', $package033)
    && substr_count($migration033, 'BEGIN;') === 1
    && substr_count($migration033, 'COMMIT;') === 1
    && strpos($migration033, 'BEGIN;') < strpos($migration033, 'CREATE UNIQUE INDEX'));

$migration034 = (string) file_get_contents($migrations . '/034_identity_group_role_permission_catalog.pgsql');
$package034 = (string) file_get_contents($packageMigrations . '/034_identity_group_role_permission_catalog.pgsql');
$permissionCodes = [
    'sand_iam:identity_group_role:index',
    'sand_iam:identity_group_role:grant',
    'sand_iam:identity_group_role:revoke',
];
release070Assert('034 is byte-identical in root and package', hash('sha256', $migration034) === hash('sha256', $package034));
release070Assert('034 is transactional idempotent and fails explicitly without its parent menu',
    str_contains($migration034, 'BEGIN;')
    && str_contains($migration034, 'COMMIT;')
    && str_contains($migration034, 'RAISE EXCEPTION')
    && str_contains($migration034, 'SandIAMPeopleAccess is missing')
    && str_contains($migration034, 'WHERE NOT EXISTS ('));
release070Assert('034 creates only the three group-role permission nodes and never assigns them to roles',
    array_reduce($permissionCodes, static fn (bool $ok, string $code): bool => $ok && substr_count($migration034, $code) >= 1, true)
    && substr_count($migration034, "('sand_iam:identity_group_role:") === 3
    && !str_contains($migration034, 'sand_system_role_menu'));

$migration036 = (string) file_get_contents($migrations . '/036_acceptance_fixture_support.pgsql');
$package036 = (string) file_get_contents($packageMigrations . '/036_acceptance_fixture_support.pgsql');
$acceptancePermissions = [
    'sand_iam:acceptance_fixture:cleanup',
    'sand_iam:acceptance_fixture:read',
];
preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $migration036, $migration036Checksum);
$canonical036 = isset($migration036Checksum[1]) ? str_replace($migration036Checksum[1], '__SELF_SHA256__', $migration036) : null;
release070Assert('036 is byte-identical, transactional and has a stable canonical self-checksum',
    hash('sha256', $migration036) === hash('sha256', $package036)
    && isset($migration036Checksum[1])
    && is_string($canonical036)
    && hash('sha256', $canonical036) === $migration036Checksum[1]
    && str_contains($migration036, 'BEGIN;')
    && str_contains($migration036, 'COMMIT;'));
release070Assert('036 exposes only two hidden developer permissions and never grants a role',
    array_reduce($acceptancePermissions, static fn (bool $ok, string $code): bool => $ok && substr_count($migration036, $code) >= 1, true)
    && str_contains($migration036, "code = 'SandIAMDeveloperDocs'")
    && str_contains($migration036, "code = 'SandIAM'")
    && str_contains($migration036, 'root menu SandIAM must be unique')
    && str_contains($migration036, 'menu SandIAMDeveloperDocs is duplicated')
    && str_contains($migration036, 'menu SandIAMDeveloperDocs has incompatible ownership')
    && str_contains($migration036, "(root_menu_id, '开发者接入', 'SandIAMDeveloperDocs'")
    && str_contains($migration036, 'matched_permissions <> 2')
    && !str_contains($migration036, 'sand_system_role_menu'));
release070Assert('036 changes only the two nullable audit ownership links to SET NULL and registers itself last',
    str_contains($migration036, "column_name IN ('organization_id', 'application_id')")
    && substr_count($migration036, 'ON DELETE SET NULL') >= 4
    && str_contains($migration036, '036_acceptance_fixture_support.pgsql')
    && str_contains($migration036, '(SELECT count(*) FROM sand_iam_schema_migration) NOT IN (37, 38)'));

$ledger = (string) file_get_contents($migrations . '/035_schema_migration_ledger.pgsql');
$packageLedger = (string) file_get_contents($packageMigrations . '/035_schema_migration_ledger.pgsql');
preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $ledger, $selfChecksum);
$canonicalLedger = preg_replace("/(WITH self_checksum\\(checksum\\) AS \\(VALUES \\(')[^']+/", '${1}__SELF_SHA256__', $ledger, 1);
release070Assert('035 ledger is byte-identical and has a stable canonical self-checksum',
    hash('sha256', $ledger) === hash('sha256', $packageLedger)
    && isset($selfChecksum[1])
    && is_string($canonicalLedger)
    && hash('sha256', $canonicalLedger) === $selfChecksum[1]);
release070Assert('035 records the required migration identity fields and guards 0.6 baseline adoption',
    str_contains($ledger, 'migration_file varchar(160) PRIMARY KEY')
    && str_contains($ledger, 'revision smallint NOT NULL')
    && str_contains($ledger, 'checksum char(64) NOT NULL')
    && str_contains($ledger, 'package_version varchar(32) NOT NULL')
    && str_contains($ledger, 'executed_time timestamp(0) without time zone NOT NULL')
    && str_contains($ledger, 'exact legacy or ledger-backed relation fingerprint is incompatible')
    && str_contains($ledger, 'migration ledger checksum or package-version conflict; refusing to continue')
    && str_contains($ledger, 'migration ledger is partial; refusing to adopt or overwrite missing baseline records')
    && str_contains($ledger, 'migration ledger contains an unknown migration filename; refusing to continue')
    && str_contains($ledger, "WHERE revision <= 35")
    && str_contains($ledger, 'recorded_rows = expected_rows - 1')
    && str_contains($ledger, 'recorded_rows = expected_rows - 2')
    && str_contains($ledger, 'recorded_rows = expected_rows - 3')
    && str_contains($ledger, "('036_acceptance_fixture_support.pgsql', 36,"));
$sourceMigrationNames = array_values(array_filter(
    array_map('basename', glob($migrations . '/*.pgsql') ?: []),
    static fn (string $name): bool => substr($name, 0, 3) <= '038',
));
sort($sourceMigrationNames, SORT_STRING);
preg_match_all("/^\\s*\\('([0-9]{3}_[^']+\\.pgsql)',\\s*\\d+,\\s*'[0-9a-f]{64}'/m", $ledger, $ledgerMigrationMatches);
$ledgerMigrationNames = $ledgerMigrationMatches[1] ?? [];
$ledgerMigrationNames[] = '035_schema_migration_ledger.pgsql';
sort($ledgerMigrationNames, SORT_STRING);
release070Assert('035 catalogs every migration filename that can already exist when update.sql is replayed', $ledgerMigrationNames === $sourceMigrationNames);
$draftMigration = (string) file_get_contents($migrations . '/037_initialization_draft.pgsql');
$packageDraftMigration = (string) file_get_contents($packageMigrations . '/037_initialization_draft.pgsql');
preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $draftMigration, $draftChecksum);
release070Assert('037 is root/package-identical, self-checksummed, append-only in the ledger, and declares draft ownership',
    hash('sha256', $draftMigration) === hash('sha256', $packageDraftMigration)
    && isset($draftChecksum[1])
    && hash('sha256', str_replace($draftChecksum[1], '__SELF_SHA256__', $draftMigration)) === $draftChecksum[1]
    && str_contains($draftMigration, "SELECT '037_initialization_draft.pgsql', 37")
    && str_contains($draftMigration, '(SELECT count(*) FROM sand_iam_schema_migration) <> 38')
    && str_contains($draftMigration, 'sand_iam_initialization_draft')
    && str_contains($draftMigration, 'sand_iam_initialization_draft_revision')
    && str_contains($draftMigration, 'FOREIGN KEY (application_id, organization_id)')
    && str_contains($draftMigration, "constraint_row.conname = 'uk_sand_iam_application_id_organization'")
    && str_contains($draftMigration, "application_ownership_key IS DISTINCT FROM 'UNIQUE (id, organization_id)'")
    && !str_contains($draftMigration, 'ADD CONSTRAINT uk_sand_iam_application_id_organization')
    && str_contains($draftMigration, 'sand_iam:initialization:save')
    && str_contains($draftMigration, 'sand_iam:initialization:update')
    && str_contains($draftMigration, 'sand_iam:initialization:disable')
    && str_contains($draftMigration, 'parent menu SandIAMConnection is missing')
    && str_contains($draftMigration, 'initialization-draft permission fingerprint is incompatible')
    && !str_contains($draftMigration, 'sand_system_role_menu'));
$retentionMigration = (string) file_get_contents($migrations . '/038_auth_rate_limit_retention.pgsql');
$packageRetentionMigration = (string) file_get_contents($packageMigrations . '/038_auth_rate_limit_retention.pgsql');
preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $retentionMigration, $retentionChecksum);
release070Assert('038 is root/package-identical, self-checksummed, indexed and append-only in the ledger',
    hash('sha256', $retentionMigration) === hash('sha256', $packageRetentionMigration)
    && isset($retentionChecksum[1])
    && hash('sha256', str_replace($retentionChecksum[1], '__SELF_SHA256__', $retentionMigration)) === $retentionChecksum[1]
    && str_contains($retentionMigration, "SELECT '038_auth_rate_limit_retention.pgsql', 38")
    && str_contains($retentionMigration, '(SELECT count(*) FROM sand_iam_schema_migration) <> 39')
    && str_contains($retentionMigration, 'idx_sand_iam_auth_rate_limit_retention')
    && str_contains($retentionMigration, "pg_get_indexdef(actual_index.indexrelid, 1, true) = 'window_start'")
    && str_contains($retentionMigration, "pg_get_indexdef(actual_index.indexrelid, 2, true) = 'id'"));
preg_match('/base_tables text\[\] := ARRAY\[([^;]+)\];/', $ledger, $baseTableMatch);
preg_match_all("/'(sand_iam_[a-z0-9_]+)'/", $baseTableMatch[1] ?? '', $baseTableMatches);
$ledgerBaseTables = array_values(array_unique($baseTableMatches[1] ?? []));
sort($ledgerBaseTables, SORT_STRING);
$baselineSchema = (string) file_get_contents($root . '/lifecycle/base.pgsql');
foreach (glob($migrations . '/*.pgsql') ?: [] as $migration) {
    if (substr(basename($migration), 0, 3) <= '032') {
        $baselineSchema .= "\n" . (string) file_get_contents($migration);
    }
}
preg_match_all('/\bCREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(sand_iam_[a-z0-9_]+)/i', $baselineSchema, $baselineTableMatches);
$sourceBaselineTables = array_values(array_unique($baselineTableMatches[1] ?? []));
sort($sourceBaselineTables, SORT_STRING);
release070Assert('035 freezes the complete programmatic 0.6 82-table relation baseline and the post-033 83-table set',
    count($ledgerBaseTables) === 82
    && $ledgerBaseTables === $sourceBaselineTables
    && str_contains($ledger, '<> 83')
    && str_contains($ledger, "UNION SELECT 'sand_iam_identity_group_role'"));
release070Assert('035 scopes constraint and index checks by schema/table OID, type, definition and valid unique key order',
    str_contains($ledger, 'actual_table.oid = actual_constraint.conrelid')
    && str_contains($ledger, 'actual_constraint.contype = expected.constraint_type')
    && str_contains($ledger, 'pg_get_constraintdef(actual_constraint.oid, true)')
    && str_contains($ledger, 'indexed_table.oid = baseline_index.indrelid')
    && str_contains($ledger, 'baseline_index.indisunique AND baseline_index.indisvalid AND baseline_index.indisready')
    && str_contains($ledger, "pg_get_indexdef(baseline_index.indexrelid, 1, true) = 'id'")
    && str_contains($ledger, "pg_get_indexdef(baseline_index.indexrelid, 2, true) = 'application_id'"));
release070Assert('035 accepts the known pre-036 NOT VALID checks only at their frozen validation state',
    str_contains($ledger, "ck_sand_iam_service_grant_data_class', 'ck_sand_iam_service_grant_quota_object') THEN false")
    && str_contains($ledger, 'actual_constraint.convalidated = CASE')
    && str_contains($ledger, 'ledger_revision.convalidated')
    && str_contains($ledger, 'ledger_checksum.convalidated'));
release070Assert('035 locks all twelve critical CHECK expressions after stable PostgreSQL definition normalization',
    str_contains($ledger, "expected.constraint_type = 'c' AND regexp_replace(")
    && str_contains($ledger, "'checksource_state=anyarray[''active'',''disabled'',''deleted'']'")
    && str_contains($ledger, "'checkprovider_type=anyarray[''local'',''oidc'',''oauth2'',''saml'',''ldap'',''scim'',''kerberos'']'")
    && str_contains($ledger, "'checkscope_type=''application''andapplication_idisnotnullorscope_type=''organization''andapplication_idisnull'")
    && str_contains($ledger, "'checkauth_method=''local_password''andidentity_binding_idisnullorauth_method=''federation''andidentity_binding_idisnotnull'")
    && str_contains($ledger, "'checkrequest_fingerprint~''^[0-9a-f]{64}$'''")
    && str_contains($ledger, "'checkobject_type=anyarray[''application'',''role''"));
release070Assert('CHECK definition normalization removes scalar and array casts without a database',
    release070NormalizeCheckDefinition("CHECK ((value::text = ANY (ARRAY['a'::text, 'b'::text]::text[])))") === "checkvalue=anyarray['a','b']"
    && release070NormalizeCheckDefinition("CHECK ((provider_type::character varying = ANY (ARRAY['oidc'::character varying, 'saml'::character varying]::character varying[])))") === "checkprovider_type=anyarray['oidc','saml']"
    && release070NormalizeCheckDefinition("CHECK (((scope_type = 'application') AND (application_id IS NOT NULL)) OR ((scope_type = 'organization') AND (application_id IS NULL)))") === "checkscope_type='application'andapplication_idisnotnullorscope_type='organization'andapplication_idisnull");
release070Assert('035 keeps independent field, 021 permission, 034 catalog and ledger-schema adoption fingerprints',
    str_contains($ledger, 'required_baseline_column')
    && str_contains($ledger, "('sand_iam_identity_provider', 'application_id', 'bigint', 'YES', false)")
    && str_contains($ledger, "('sand_iam_sync_run', 'delete_time'")
    && str_contains($ledger, 'frozen_permission_group')
    && str_contains($ledger, 'immutable 021 permission code set is absent or not unique')
    && str_contains($ledger, "'sand_iam:identity_group_role:revoke'")
    && str_contains($ledger, 'GROUP BY required.code')
    && str_contains($ledger, 'count(permission_menu.id) <> 1')
    && str_contains($ledger, 'required_ledger_column'));
release070Assert('035 validates ledger columns, default and primary-key definition before adoption',
    str_contains($ledger, 'required_ledger_column')
    && str_contains($ledger, 'column_info.character_maximum_length IS NOT DISTINCT FROM expected.character_maximum_length')
    && str_contains($ledger, 'column_info.column_default IS NOT NULL')
    && str_contains($ledger, "pg_get_constraintdef(ledger_pk.oid, true) = 'PRIMARY KEY (migration_file)'")
    && str_contains($ledger, 'ck_sand_iam_schema_migration_revision')
    && str_contains($ledger, 'checkrevision>=1andrevision<=999')
    && str_contains($ledger, 'ck_sand_iam_schema_migration_checksum')
    && str_contains($ledger, "checkchecksum~''^[0-9a-f]{64}$''")
    && str_contains($ledger, 'SandIAM migration ledger table definition is incompatible'));

$install = (string) file_get_contents($root . '/install.sql');
$packageInstall = (string) file_get_contents($package . '/install.sql');
$update = (string) file_get_contents($root . '/update.sql');
$packageUpdate = (string) file_get_contents($package . '/update.sql');
$uninstall = (string) file_get_contents($root . '/uninstall.sql');
$packageUninstall = (string) file_get_contents($package . '/uninstall.sql');
$expectedUpdate = ['039_service_grant_nullable_data_class.pgsql'];
$updatePreflight = (string) file_get_contents($root . '/lifecycle/update-070-to-071-preflight.pgsql');
preg_match_all("/\\('(sand_iam_[a-z0-9_]+)'\\)/", $updatePreflight, $preflightTableMatches);
$preflightTables = array_values(array_unique($preflightTableMatches[1] ?? []));
sort($preflightTables, SORT_STRING);
$sourceTables = $tables = [];
$sourceSchema = (string) file_get_contents($root . '/lifecycle/base.pgsql');
foreach (glob($migrations . '/*.pgsql') ?: [] as $migration) {
    $sourceSchema .= "\n" . (string) file_get_contents($migration);
}
preg_match_all('/\\bCREATE\\s+TABLE(?:\\s+IF\\s+NOT\\s+EXISTS)?\\s+(sand_iam_[a-z0-9_]+)/i', $sourceSchema, $sourceTableMatches);
$sourceTables = array_values(array_unique($sourceTableMatches[1] ?? []));
sort($sourceTables, SORT_STRING);
$sameCountRename = $sourceTables;
array_pop($sameCountRename);
$sameCountRename[] = 'sand_iam_renamed_fixture';
sort($sameCountRename, SORT_STRING);
$sameCountExtra = $sourceTables;
array_pop($sameCountExtra);
$sameCountExtra[] = 'sand_iam_extra_fixture';
sort($sameCountExtra, SORT_STRING);
release070Assert('0.7.1 preflight freezes the exact 86-table relation set and rejects same-count rename or extra substitutions',
    count($preflightTables) === 86
    && $preflightTables === $sourceTables
    && count($sameCountRename) === 86
    && count($sameCountExtra) === 86
    && ($sameCountRename !== $preflightTables || $sameCountExtra !== $preflightTables)
    && str_contains($updatePreflight, 'EXCEPT SELECT table_name FROM sand_iam_071_expected_table')
    && str_contains($updatePreflight, 'SELECT table_name FROM sand_iam_071_expected_table')
    && str_contains($updatePreflight, 'requires the exact completed 86-table 0.7.0 schema'));
preg_match_all("/\\('([^']+\\.pgsql)',\\s*(\\d+),\\s*'([0-9a-f]{64})',\\s*'([^']+)'\\)/", $ledger, $ledgerRows, PREG_SET_ORDER);
$expectedLedgerRows = array_map(
    static fn (array $row): string => implode('|', [$row[1], $row[2], $row[3], $row[4]]),
    array_values(array_filter($ledgerRows, static fn (array $row): bool => (int) $row[2] <= 37)),
);
preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $ledger, $ledgerSelfChecksum);
if (isset($ledgerSelfChecksum[1])) {
    $expectedLedgerRows[] = implode('|', ['035_schema_migration_ledger.pgsql', '35', $ledgerSelfChecksum[1], '0.7.0']);
}
sort($expectedLedgerRows, SORT_STRING);
preg_match_all("/\\('([^']+\\.pgsql)',\\s*(\\d+),\\s*'([0-9a-f]{64})',\\s*'([^']+)'\\)/", $updatePreflight, $preflightRows, PREG_SET_ORDER);
$actualLedgerRows = array_map(static fn (array $row): string => implode('|', [$row[1], $row[2], $row[3], $row[4]]), $preflightRows);
sort($actualLedgerRows, SORT_STRING);
release070Assert('0.7.1 preflight reproduces the exact immutable 001-037 ledger identities before 038',
    count($expectedLedgerRows) === 38
    && $actualLedgerRows === $expectedLedgerRows);
release070Assert('fresh-install lifecycle is root/package identical and contains the 035-038 release migrations',
    hash('sha256', $install) === hash('sha256', $packageInstall)
    && str_contains($install, '-- lifecycle source: migrations/035_schema_migration_ledger.pgsql')
    && str_contains($install, '-- lifecycle source: migrations/036_acceptance_fixture_support.pgsql')
    && str_contains($install, '-- lifecycle source: migrations/037_initialization_draft.pgsql')
    && str_contains($install, '-- lifecycle source: migrations/038_auth_rate_limit_retention.pgsql'));
release070Assert('0.7.1 to 0.7.2 update lifecycle is root/package identical, gates exact 001-038, then contains only immutable 039',
    hash('sha256', $update) === hash('sha256', $packageUpdate)
    && release070PayloadSources($update) === $expectedUpdate
    && str_contains($update, '-- lifecycle source: lifecycle/update-071-to-072-preflight.pgsql')
    && strpos($update, '-- lifecycle source: lifecycle/update-071-to-072-preflight.pgsql') < strpos($update, '-- lifecycle source: migrations/039_service_grant_nullable_data_class.pgsql')
    && str_contains($update, 'requires exact 001-038 ledger identities before executing 039')
    && str_contains($update, 'requires the exact non-null service-grant data-class column')
    && !str_contains($update, '-- lifecycle source: migrations/037_initialization_draft.pgsql')
    && !str_contains($update, '-- lifecycle source: migrations/038_auth_rate_limit_retention.pgsql')
    && !str_contains($update, '-- lifecycle source: migrations/021_admin_permission_catalog.pgsql'));
release070Assert('uninstall removes the ledger in root and package payloads',
    hash('sha256', $uninstall) === hash('sha256', $packageUninstall)
    && str_contains($uninstall, 'DROP TABLE IF EXISTS sand_iam_schema_migration;'));

$rootInfo = parse_ini_file($root . '/info.ini');
$packageInfo = parse_ini_file($package . '/info.ini');
$appConfig = (string) file_get_contents($package . '/config/app.php');
$portal = json_decode((string) file_get_contents($root . '/portal/package.json'), true);
$managementCatalog = (string) file_get_contents($package . '/app/developer/ManagementApiCatalog.php');
release070Assert('0.7.2 release metadata declares matching SandAdmin 6.x support while OpenAPI stays 0.13.0-candidate',
    ($rootInfo['version'] ?? null) === '0.7.2'
    && ($packageInfo['version'] ?? null) === '0.7.2'
    && ($rootInfo['support'] ?? null) === '6.x'
    && ($packageInfo['support'] ?? null) === '6.x'
    && str_contains($appConfig, "'version' => '0.7.2'")
    && is_array($portal) && ($portal['version'] ?? null) === '0.7.2'
    && str_contains($managementCatalog, "'version' => '0.13.0-candidate'"));

$rootRecovery = (string) file_get_contents($root . '/recovery/failed-upgrade.v2.json');
$packageRecovery = (string) file_get_contents($package . '/recovery/failed-upgrade.v2.json');
$recovery = json_decode($rootRecovery, true);
require_once $root . '/tools/package-payload-policy.php';
$normalPayload = sandIamPayloadFiles($root, true);
release070Assert('historical 0.7.0 recovery descriptor remains root/plugin-identical but is excluded from normal 0.7.2 payloads',
    $rootRecovery !== ''
    && $rootRecovery === $packageRecovery
    && is_array($recovery)
    && $rootRecovery === json_encode($recovery, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    && array_keys($recovery) === ['app', 'candidate_payload', 'from_version', 'profile', 'schema', 'to_version', 'update_lifecycle']
    && ($recovery['schema'] ?? null) === 'sandpackage.failed-upgrade-recovery/v2'
    && ($recovery['profile']['schema'] ?? null) === 'sandpackage.failed-upgrade-recovery-profile/v2'
    && ($recovery['profile']['state'] ?? null) === 'prefix_033_034'
    && in_array('ledger_absent', array_column($recovery['profile']['assertions'] ?? [], 'type'), true)
    && !in_array('recovery/failed-upgrade.v2.json', $normalPayload, true)
    && !in_array('plugin/sand-iam/recovery/failed-upgrade.v2.json', $normalPayload, true));

release070Assert('0.7.2 schema source retains exactly 86 SandIAM tables including editable initialization drafts',
    count($sourceTables) === 86
    && in_array('sand_iam_schema_migration', $sourceTables, true)
    && in_array('sand_iam_initialization_draft', $sourceTables, true)
    && in_array('sand_iam_initialization_draft_revision', $sourceTables, true));

echo 'SandIAM 0.7.2 lifecycle non-PG contract passed' . PHP_EOL;
