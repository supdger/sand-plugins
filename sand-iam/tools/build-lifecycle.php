<?php

declare(strict_types=1);

/**
 * Build the self-contained SandPackage lifecycle payloads.
 *
 * Every profile is self-contained; psql's \ir command is never required.
 * Historical profiles retain their frozen statement folding for older
 * importers. The 0.8.2/0.8.3 update uses the public PostgreSQL executor's multiline
 * dollar-quote support and preserves embedded migration bytes.
 * The 0.7.6/0.8.0 profiles retain the published fresh admission
 * and raw base; the historical embedded073 profile retains its old folding.
 * The 0.8.0/0.8.1 profiles atomically admits published073 then applies revision043.
 * Default/--check verifies bytes without writes; --output builds into a new
 * external directory. --profile=legacy-0.7.3 preserves the embedded42 baseline.
 * Only explicit --write can replace source lifecycle files.
 */

require_once __DIR__ . '/lifecycle-sql.php';

$options = getopt('', ['profile:', 'output:', 'check', 'write']);
$root = dirname(__DIR__);
$version = parse_ini_file($root . '/info.ini')['version'] ?? '';
$profile = $options['profile'] ?? (in_array($version, ['0.7.6', '0.8.0', '0.8.1', '0.8.2', '0.8.3', '0.8.4'], true) ? $version : 'legacy-0.7.3');
if (!in_array($profile, ['0.7.6', '0.8.0', '0.8.1', '0.8.2', '0.8.3', '0.8.4', 'legacy-0.7.3'], true)) throw new RuntimeException('Unsupported lifecycle profile');
if (isset($options['output'], $options['write']) || isset($options['check'], $options['write'])) {
    throw new RuntimeException('Choose check, a new output directory, or explicit source write');
}
$output = $options['output'] ?? null;
if ($output !== null) {
    if (!is_string($output)) throw new RuntimeException('Output directory must be a string');
    $absolute = DIRECTORY_SEPARATOR === '\\'
        ? preg_match('~^(?:[A-Za-z]:[/\\\\]|[/\\\\]{2}[^/\\\\]+[/\\\\][^/\\\\]+[/\\\\])~', $output) === 1
        : str_starts_with($output, '/');
    if (!$absolute || file_exists($output)
        || realpath(dirname($output)) === false || is_link(dirname($output))
        || str_starts_with(realpath(dirname($output)) . '/', realpath($root) . '/')) {
        throw new RuntimeException('Output must be a new absolute directory outside SandIAM source');
    }
}
$package = $root . '/plugin/sand-iam';
$sourceDirectory = $root . '/migrations';
$packageDirectory = $package . '/migrations';

$migrations = [
    '001_iam04_admin_organization_grant.pgsql',
    '002_identity_provider_scope.pgsql',
    '003_human_auth_core.pgsql',
    '004_mfa_passkey.pgsql',
    '005_oauth_oidc.pgsql',
    '006_federation_directory_scim.pgsql',
    '006_federation_integrity.pgsql',
    '007_federation_handoff.pgsql',
    '008_api_governance.pgsql',
    '009_admin_application_grant.pgsql',
    '010_webhook_delivery.pgsql',
    '011_application_experience_message_provider.pgsql',
    '012_identity_lifecycle_group.pgsql',
    '013_identity_invitation.pgsql',
    '014_identity_import_export.pgsql',
    '015_identity_sync_connector.pgsql',
    '016_oauth_dynamic_registration_logout.pgsql',
    '017_cas_protocol.pgsql',
    '018_radius_server.pgsql',
    '019_security_operations.pgsql',
    '020_initialization_package.pgsql',
    '021_admin_permission_catalog.pgsql',
    '022_model_soft_delete_contract.pgsql',
    '023_federation_protocol_constraint_alignment.pgsql',
    '024_scim_group_member_lifecycle.pgsql',
    '025_message_provider_mount_scope_integrity.pgsql',
    '026_sync_connector_scope_integrity.pgsql',
    '027_security_operation_idempotency.pgsql',
    '028_application_business_action.pgsql',
    '029_policy_versioning.pgsql',
    '030_service_grant_invocation_control.pgsql',
    '031_oidc_signing_key_rotation.pgsql',
    '032_initialization_binding_application_business_action.pgsql',
    '033_identity_group_role.pgsql',
    '034_identity_group_role_permission_catalog.pgsql',
    '035_schema_migration_ledger.pgsql',
    '036_acceptance_fixture_support.pgsql',
    '037_initialization_draft.pgsql',
    '038_auth_rate_limit_retention.pgsql',
    '039_service_grant_nullable_data_class.pgsql',
    '040_passkey_auth_challenge_identity.pgsql',
    '041_authorization_scope_integrity.pgsql',
    '042_permission_menu_hierarchy.pgsql',
    '043_scope_audit_event_key.pgsql',
];

if (!in_array($profile, ['0.8.0', '0.8.1', '0.8.2', '0.8.3', '0.8.4'], true)) $migrations = array_slice($migrations, 0, -1);
if ($profile === 'legacy-0.7.3') $migrations = array_slice($migrations, 0, -1);

/** @return non-empty-string */
function readRequired(string $file): string
{
    $content = file_get_contents($file);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Cannot read lifecycle source: ' . $file);
    }

    return $content;
}

function writeExact(string $file, string $content): void
{
    if (file_put_contents($file, $content) !== strlen($content)) {
        throw new RuntimeException('Cannot write lifecycle payload: ' . $file);
    }
}

function collapseDoBlocks(string $sql): string
{
    // UTF-8 mode is required: in byte mode PCRE can treat the 0x85 byte inside
    // a Chinese character as an NEL line break and corrupt human-facing text.
    $lines = preg_split('/\R/u', $sql);
    if (!is_array($lines)) {
        throw new RuntimeException('Cannot split lifecycle SQL');
    }

    $result = [];
    $block = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($block === [] && str_starts_with($trimmed, 'DO $$')) {
            if (str_contains($trimmed, '$$;')) {
                $result[] = $line;
                continue;
            }
            $block[] = $trimmed;
            continue;
        }

        if ($block !== []) {
            if (str_starts_with($trimmed, '--')) {
                throw new RuntimeException('A comment inside a DO block cannot be flattened safely');
            }
            if ($trimmed !== '') {
                $block[] = $trimmed;
            }
            if (str_ends_with($trimmed, '$$;')) {
                $result[] = implode(' ', $block);
                $block = [];
            }
            continue;
        }

        $result[] = $line;
    }

    if ($block !== []) {
        throw new RuntimeException('Unclosed DO block in lifecycle SQL');
    }

    return rtrim(implode("\n", $result)) . "\n";
}

/** @param list<string> $names */
function migrationPayload(string $directory, array $names): string
{
    $payload = '';
    foreach ($names as $name) {
        $payload .= "\n-- lifecycle source: migrations/{$name}\n";
        $source = readRequired($directory . '/' . $name);
        if ($name === '038_auth_rate_limit_retention.pgsql') {
            // Preserve the published migration and its ledger checksum.
            // PostgreSQL returns an empty string for an absent index column.
            $source = str_replace(
                'pg_get_indexdef(actual_index.indexrelid, 3, true) IS NULL',
                'actual_index.indnatts = 2 AND actual_index.indnkeyatts = 2',
                $source,
                $replacements
            );
            if ($replacements !== 1) {
                throw new RuntimeException('Migration 038 index compatibility patch source changed');
            }
        }
        $payload .= rtrim($source) . "\n";
    }

    return $payload;
}

/** @return list<string> */
function controllerPermissions(string $package): array
{
    $permissions = [];
    $files = glob($package . '/app/admin/controller/*.php');
    if (!is_array($files)) {
        throw new RuntimeException('Cannot enumerate admin controllers');
    }
    foreach ($files as $file) {
        $source = readRequired($file);
        preg_match_all("/'(sand_iam:[a-z_]+:[a-zA-Z]+)'/", $source, $matches);
        foreach ($matches[1] ?? [] as $permission) {
            $permissions[(string) $permission] = true;
        }
    }
    ksort($permissions);

    return array_keys($permissions);
}

/** @return array<string, true> */
function generatedPermissionCatalog(string $migration): array
{
    preg_match_all(
        "/\\('([a-z_]+)',\\s*'[^']+',\\s*'[^']+',\\s*\\d+,\\s*ARRAY\\[([^\\]]+)]::text\\[\\]\\)/u",
        $migration,
        $groups,
        PREG_SET_ORDER
    );
    $catalog = [];
    foreach ($groups as $group) {
        preg_match_all("/'([a-zA-Z_]+)'/", (string) $group[2], $actions);
        foreach ($actions[1] ?? [] as $action) {
            $catalog['sand_iam:' . $group[1] . ':' . $action] = true;
        }
    }

    return $catalog;
}

/** @param list<string> $permissions */
function permissionInventory(array $permissions): string
{
    $lines = [
        '-- Admin controller permission inventory; validated against migration 021 during generation.',
    ];
    foreach ($permissions as $permission) {
        $lines[] = "-- '{$permission}'";
    }

    return implode("\n", $lines) . "\n";
}

$base = readRequired($root . '/lifecycle/base.pgsql');
if (str_contains($base, '\\ir')) {
    throw new RuntimeException('The lifecycle base must not contain psql include directives');
}
$firstTransaction = strpos($base, "\nBEGIN;\n");
if ($firstTransaction === false) {
    throw new RuntimeException('Cannot locate the menu/schema boundary in lifecycle/base.pgsql');
}
$menu = substr($base, 0, $firstTransaction + 1);

$header = "-- Generated by tools/build-lifecycle.php; edit lifecycle/base.pgsql or migrations/*.pgsql instead.\n";
$installNames = array_slice($migrations, 4);
$installSource = $base . migrationPayload($sourceDirectory, $installNames);
// A SandPackage upgrade must never replay historical lifecycle input. 0.7.3
// admits only a completed 0.7.2 ledger through 040, then applies migration 041
// exactly once. The admission gate is lifecycle input rather
// than a new migration revision, so it cannot alter the published ledger.
$legacyUpdateNames = [
    '041_authorization_scope_integrity.pgsql',
];
$updatePreflight = readRequired($root . '/lifecycle/update-072-to-073-preflight.pgsql');
$updateMigration = readRequired($sourceDirectory . '/041_authorization_scope_integrity.pgsql');
$updateSource = "BEGIN;\n"
    . "-- lifecycle source: lifecycle/update-072-to-073-preflight.pgsql\n"
    . rtrim($updatePreflight) . "\n"
    . "-- lifecycle source: migrations/041_authorization_scope_integrity.pgsql\n"
    . withoutOuterTransaction($updateMigration, $legacyUpdateNames[0])
    . "COMMIT;\n";
$permissions = controllerPermissions($package);
$catalog = generatedPermissionCatalog(readRequired($sourceDirectory . '/021_admin_permission_catalog.pgsql'));
foreach ($permissions as $permission) {
    if (!str_contains($installSource, "'{$permission}'") && !isset($catalog[$permission])) {
        throw new RuntimeException('Admin permission is absent from lifecycle catalog: ' . $permission);
    }
}
$inventory = permissionInventory($permissions);
if ($profile === 'legacy-0.7.3') {
    $install = collapseDoBlocks($header . $inventory . $installSource);
    $update = collapseDoBlocks($header . $inventory . $updateSource);
    $uninstall = collapseDoBlocks($header . readRequired($root . '/lifecycle/remove.pgsql'));
} else {
    // Match the published073 baseline carried by076. Keep the reviewed fresh
    // admission block multiline; only migration DO blocks use legacy folding.
    $admission = <<<'SQL'
-- Fail before the first menu write when this is not a fresh SandIAM target.
DO $sand_iam_fresh_install$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_class relation
        JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
        WHERE namespace.nspname = current_schema()
          AND (
              relation.relname LIKE 'sand\_iam\_%' ESCAPE '\'
              OR relation.relname LIKE 'idx\_sand\_iam\_%' ESCAPE '\'
              OR relation.relname LIKE 'uk\_sand\_iam\_%' ESCAPE '\'
              OR relation.relname LIKE 'uq\_sand\_iam\_%' ESCAPE '\'
              OR relation.relname LIKE 'ux\_sand\_iam\_%' ESCAPE '\'
          )
    ) OR EXISTS (
        SELECT 1
        FROM pg_type type_entry
        JOIN pg_namespace namespace ON namespace.oid = type_entry.typnamespace
        WHERE namespace.nspname = current_schema()
          AND type_entry.typname LIKE 'sand\_iam\_%' ESCAPE '\'
    ) THEN
        RAISE EXCEPTION 'SandIAM fresh install refuses existing sand_iam_* objects in schema %', current_schema();
    END IF;

    IF to_regclass(format('%I.sand_system_menu', current_schema())) IS NULL
       OR to_regclass(format('%I.sand_system_role_menu', current_schema())) IS NULL THEN
        RAISE EXCEPTION 'SandIAM fresh install requires the SandAdmin menu and role-menu tables';
    END IF;

    IF EXISTS (
        SELECT 1 FROM sand_system_menu
        WHERE code = 'SandIAM'
           OR code LIKE 'SandIAM%'
           OR code LIKE 'sand\_iam:%' ESCAPE '\'
           OR slug LIKE 'sand\_iam:%' ESCAPE '\'
           OR path = '/sand-iam'
           OR path LIKE '/sand-iam/%'
    ) THEN
        RAISE EXCEPTION 'SandIAM fresh install refuses existing menu codes, permission slugs, or routes';
    END IF;
END
$sand_iam_fresh_install$;
SQL;
    $inventoryLines = explode("\n", $inventory);
    $inventoryHeader = array_shift($inventoryLines);
    $install = "-- SandIAM installation lifecycle for the release package.\n"
        . $inventoryHeader . "\n" . rtrim($admission) . "\n" . implode("\n", $inventoryLines)
        . $base;
    $install .= collapseDoBlocks(migrationPayload($sourceDirectory, array_slice($migrations, 4)));
    $preflightFile = in_array($profile, ['0.8.0', '0.8.1', '0.8.2', '0.8.3', '0.8.4'], true)
        ? '/lifecycle/update-073-or-075-or-076-to-080-preflight.pgsql'
        : '/lifecycle/update-073-or-075-to-076-preflight.pgsql';
    $update = readRequired($root . $preflightFile);
    if (in_array($profile, ['0.8.0', '0.8.1', '0.8.2', '0.8.3', '0.8.4'], true)) {
        // Admission and migration share one transaction: failure restores both
        // the audit constraint and ledger. The standalone admission is read-only.
        $admission = str_replace('BEGIN TRANSACTION READ ONLY;', 'BEGIN;', $update);
        $commitOffset = strrpos($admission, "\nCOMMIT;");
        if ($commitOffset === false || trim(substr($admission, $commitOffset + strlen("\nCOMMIT;"))) !== '') {
            throw new RuntimeException('0.8.0 admission must own one terminal explicit transaction');
        }
        $update = substr($admission, 0, $commitOffset + 1)
            . "-- lifecycle source: migrations/043_scope_audit_event_key.pgsql\n"
            . collapseDoBlocks(withoutOuterTransaction(
                readRequired($sourceDirectory . '/043_scope_audit_event_key.pgsql'),
                '043_scope_audit_event_key.pgsql'
            ))
            . "COMMIT;\n";
    }
    if (in_array($profile, ['0.8.2', '0.8.3', '0.8.4'], true)) {
        require_once __DIR__ . '/build-upgrade-082.php';
        $update = sandIamBuild082Update($root);
    }
    $uninstall = "-- SandIAM uninstall lifecycle for the release package.\n"
        . readRequired($root . '/lifecycle/remove.pgsql');
}
$payloads = ['install' => $install, 'update' => $update, 'uninstall' => $uninstall];
if ($output !== null) {
    if (!mkdir($output, 0700)) throw new RuntimeException('Cannot create new output directory');
    mkdir($output . '/migrations', 0700);
    foreach ($migrations as $name) writeExact($output . '/migrations/' . $name, readRequired($sourceDirectory . '/' . $name));
    foreach ($payloads as $name => $payload) writeExact($output . '/' . $name . '.sql', $payload);
} elseif (isset($options['write'])) {
    foreach ($migrations as $name) writeExact($packageDirectory . '/' . $name, readRequired($sourceDirectory . '/' . $name));
    foreach ($payloads as $name => $payload) {
        writeExact($root . '/' . $name . '.sql', $payload);
        writeExact($package . '/' . $name . '.sql', $payload);
    }
} else {
    foreach ($payloads as $name => $payload) {
        if ($payload !== readRequired($root . '/' . $name . '.sql')
            || $payload !== readRequired($package . '/' . $name . '.sql')) {
            throw new RuntimeException('Generated lifecycle differs from frozen package: ' . $name);
        }
    }
    echo "[PASS] lifecycle generation matches frozen package bytes; source unchanged\n";
}
foreach ($payloads as $name => $payload) {
    printf("%s profile=%s migrations=%d bytes=%d sha256=%s\n", $name, $profile, count($migrations), strlen($payload), hash('sha256', $payload));
}
