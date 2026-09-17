<?php

declare(strict_types=1);

/**
 * Build the self-contained SandPackage lifecycle payloads.
 *
 * SandPackage imports one statement whenever an input line ends in a
 * semicolon. It does not understand psql's \ir command and cannot keep a
 * multi-line DO block together. The generated files therefore contain every
 * migration inline and collapse each DO block to one physical line.
 */

$root = dirname(__DIR__);
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
];

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

/**
 * Reuse a published migration body inside a lifecycle-owned transaction.
 *
 * The migration source remains byte-for-byte immutable. Only its outer
 * transaction delimiters are omitted from the generated lifecycle so a
 * preceding admission preflight and the migration body share one executor
 * visible transaction.
 */
function withoutOuterTransaction(string $sql, string $name): string
{
    $beginOffset = strpos($sql, "\nBEGIN;");
    $commitOffset = strrpos($sql, "\nCOMMIT;");
    if ($beginOffset === false || $commitOffset === false || $beginOffset >= $commitOffset
        || trim(substr($sql, $commitOffset + strlen("\nCOMMIT;"))) !== '') {
        throw new RuntimeException("Migration {$name} must own one terminal explicit transaction");
    }

    return rtrim(
        substr($sql, 0, $beginOffset + 1)
        . substr($sql, $beginOffset + strlen("\nBEGIN;"), $commitOffset - ($beginOffset + strlen("\nBEGIN;")))
    ) . "\n";
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

foreach ($migrations as $name) {
    $source = readRequired($sourceDirectory . '/' . $name);
    writeExact($packageDirectory . '/' . $name, $source);
}

$header = "-- Generated by tools/build-lifecycle.php; edit lifecycle/base.pgsql or migrations/*.pgsql instead.\n";
$installNames = array_slice($migrations, 4);
$installSource = $base . migrationPayload($sourceDirectory, $installNames);
// A SandPackage upgrade must never replay historical lifecycle input. 0.7.3
// admits only a completed 0.7.2 ledger through 040, then applies migration 041
// exactly once. The admission gate is lifecycle input rather
// than a new migration revision, so it cannot alter the published ledger.
$updateNames = [
    '041_authorization_scope_integrity.pgsql',
];
$updatePreflight = readRequired($root . '/lifecycle/update-072-to-073-preflight.pgsql');
$updateMigration = readRequired($sourceDirectory . '/041_authorization_scope_integrity.pgsql');
$updateSource = "BEGIN;\n"
    . "-- lifecycle source: lifecycle/update-072-to-073-preflight.pgsql\n"
    . rtrim($updatePreflight) . "\n"
    . "-- lifecycle source: migrations/041_authorization_scope_integrity.pgsql\n"
    . withoutOuterTransaction($updateMigration, $updateNames[0])
    . "COMMIT;\n";
$permissions = controllerPermissions($package);
$catalog = generatedPermissionCatalog(readRequired($sourceDirectory . '/021_admin_permission_catalog.pgsql'));
foreach ($permissions as $permission) {
    if (!str_contains($installSource, "'{$permission}'") && !isset($catalog[$permission])) {
        throw new RuntimeException('Admin permission is absent from lifecycle catalog: ' . $permission);
    }
}
$inventory = permissionInventory($permissions);
$install = collapseDoBlocks($header . $inventory . $installSource);
$update = collapseDoBlocks($header . $inventory . $updateSource);
$uninstall = collapseDoBlocks($header . readRequired($root . '/lifecycle/remove.pgsql'));

foreach ([$root . '/install.sql', $package . '/install.sql'] as $file) {
    writeExact($file, $install);
}
foreach ([$root . '/update.sql', $package . '/update.sql'] as $file) {
    writeExact($file, $update);
}
foreach ([$root . '/uninstall.sql', $package . '/uninstall.sql'] as $file) {
    writeExact($file, $uninstall);
}

foreach (['install' => $install, 'update' => $update, 'uninstall' => $uninstall] as $name => $payload) {
    printf("%s bytes=%d sha256=%s\n", $name, strlen($payload), hash('sha256', $payload));
}
