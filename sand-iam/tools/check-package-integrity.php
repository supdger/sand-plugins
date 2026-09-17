<?php

declare(strict_types=1);

/**
 * behavior-test-gate: static-rule
 *
 * Validate the source package without modifying it or touching a database.
 *
 * Usage:
 *   php sand-iam/tools/check-package-integrity.php
 *   php sand-iam/tools/check-package-integrity.php --print-candidate-manifest
 *   php sand-iam/tools/check-package-integrity.php --release --trusted-manifest=/controlled/path/sand-iam-release.json --trusted-public-key=/controlled/keys/sand-iam-ed25519.pub
 *
 * The default mode validates package-internal consistency only. Candidate
 * manifests are review inputs, never release provenance. --release additionally
 * needs a clean worktree and an independently controlled manifest outside this
 * package root; this tool never generates or promotes that trusted manifest.
 */

$root = dirname(__DIR__);
$package = $root . '/plugin/sand-iam';
require_once $root . '/tools/package-payload-policy.php';
$arguments = array_slice($argv, 1);
$releaseMode = in_array('--release', $arguments, true);
$printCandidateManifest = in_array('--print-candidate-manifest', $arguments, true);
$trustedManifestPath = null;
$trustedPublicKeyPath = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--trusted-manifest=')) {
        $trustedManifestPath = substr($argument, strlen('--trusted-manifest='));
    }
    if (str_starts_with($argument, '--trusted-public-key=')) {
        $trustedPublicKeyPath = substr($argument, strlen('--trusted-public-key='));
    }
}
if ($printCandidateManifest
    && ($releaseMode || $trustedManifestPath !== null || $trustedPublicKeyPath !== null)) {
    fwrite(STDERR, "candidate manifest mode cannot be combined with release verification\n");
    exit(2);
}
if (($trustedManifestPath !== null || $trustedPublicKeyPath !== null) && !$releaseMode) {
    fwrite(STDERR, "--trusted-manifest and --trusted-public-key are only valid with --release\n");
    exit(2);
}
$passed = 0;
$total = 0;
$failures = [];

/** @param callable(): bool $check */
$assert = static function (string $label, callable $check) use (&$passed, &$total, &$failures): void {
    ++$total;
    try {
        $ok = $check() === true;
    } catch (Throwable $exception) {
        $ok = false;
        $failures[] = $label . ': ' . $exception->getMessage();
    }

    if ($ok) {
        ++$passed;
        echo "[PASS] {$label}\n";
        return;
    }

    if (!isset($failures[array_key_last($failures)]) || !str_starts_with((string) $failures[array_key_last($failures)], $label . ':')) {
        $failures[] = $label;
    }
    echo "[FAIL] {$label}\n";
};

/** @return list<string> */
$migrationNames = static function (string $directory): array {
    $files = glob($directory . '/*.pgsql');
    if (!is_array($files)) {
        return [];
    }

    $names = array_map('basename', $files);
    sort($names, SORT_STRING);
    return $names;
};

/** @return array<string, string> */
$fileHashes = static function (string $directory, array $names): array {
    $hashes = [];
    foreach ($names as $name) {
        $hash = hash_file('sha256', $directory . '/' . $name);
        if (!is_string($hash)) {
            throw new RuntimeException('cannot hash ' . $directory . '/' . $name);
        }
        $hashes[$name] = $hash;
    }
    return $hashes;
};

/** @return list<string> */
$releaseArtifactFiles = static function () use ($root): array {
    return sandIamPayloadFilePaths($root, true);
};

$hasGitMetadataAncestor = static function (string $path): bool {
    $current = realpath($path);
    if (!is_string($current)) {
        return false;
    }
    while (true) {
        $metadata = $current . '/.git';
        if (is_dir($metadata) || is_file($metadata)) {
            return true;
        }
        $parent = dirname($current);
        if ($parent === $current) {
            return false;
        }
        $current = $parent;
    }
};

/**
 * @param list<string> $directories
 * @return array<string,array<string,string>>|null Relative directory => relative file => SHA-256.
 */
$cleanGitPayloadMaps = static function (array $directories) use ($root, $hasGitMetadataAncestor): ?array {
    $status = [];
    exec('git -C ' . escapeshellarg($root) . ' status --porcelain=v1 --untracked-files=all -- . 2>&1', $status, $statusCode);
    if ($statusCode !== 0) {
        if ($hasGitMetadataAncestor($root)) {
            throw new RuntimeException('cannot inspect Git payload source');
        }
        return null;
    }
    if ($status !== []) {
        return null;
    }
    $revision = [];
    exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>&1', $revision, $revisionCode);
    $commit = $revisionCode === 0 ? trim(implode("\n", $revision)) : '';
    if (preg_match('/^[0-9a-f]{40,64}$/', $commit) !== 1) {
        throw new RuntimeException('cannot resolve clean Git payload source');
    }
    $maps = [];
    foreach ($directories as $directory) {
        $tree = proc_open(
            ['git', '-C', $root, 'ls-tree', '-r', '-z', $commit, '--', $directory],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($tree)) {
            throw new RuntimeException('cannot enumerate clean Git payload source');
        }
        fclose($pipes[0]);
        $listing = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($tree) !== 0 || !is_string($listing) || $listing === '' || !str_ends_with($listing, "\0")) {
            throw new RuntimeException('cannot enumerate clean Git payload source' . ($stderr === '' ? '' : ': ' . trim($stderr)));
        }
        $map = [];
        foreach (explode("\0", substr($listing, 0, -1)) as $entry) {
            if (preg_match('/^100(?:644|755) blob ([0-9a-f]{40,64})\t(.+)$/s', $entry, $match) !== 1
                || !str_starts_with($match[2], $directory . '/')) {
                throw new RuntimeException('clean Git payload source has an unsupported entry');
            }
            $relative = substr($match[2], strlen($directory) + 1);
            if ($relative === '' || isset($map[$relative])) {
                throw new RuntimeException('clean Git payload source has an invalid file map');
            }
            $blob = proc_open(
                ['git', '-C', $root, 'cat-file', '-p', $match[1]],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $blobPipes,
            );
            if (!is_resource($blob)) {
                throw new RuntimeException('cannot read clean Git payload blob');
            }
            fclose($blobPipes[0]);
            $bytes = stream_get_contents($blobPipes[1]);
            fclose($blobPipes[1]);
            $blobStderr = stream_get_contents($blobPipes[2]);
            fclose($blobPipes[2]);
            if (proc_close($blob) !== 0 || !is_string($bytes)) {
                throw new RuntimeException('cannot read clean Git payload blob' . ($blobStderr === '' ? '' : ': ' . trim($blobStderr)));
            }
            $map[$relative] = hash('sha256', $bytes);
        }
        ksort($map, SORT_STRING);
        $maps[$directory] = $map;
    }
    return $maps;
};

/** @return array{schema:string,kind:string,version:string,migration_file_count:int,migrations:list<string>,key_file_hashes:array<string,string>,package_sha256:string} */
$candidateManifest = static function () use ($root, $package, $migrationNames, $releaseArtifactFiles): array {
    $files = $releaseArtifactFiles();
    $tree = [];
    foreach ($files as $relative => $file) {
        $hash = hash_file('sha256', $file);
        if (!is_string($hash)) {
            throw new RuntimeException('cannot hash release artifact: ' . $relative);
        }
        $tree[$relative] = $hash;
    }
    $keyFileHashes = [];
    foreach (['info.ini', 'plugin/sand-iam/info.ini', 'plugin/sand-iam/composer.lock', 'plugin/sand-iam/config/route.php', 'install.sql', 'update.sql', 'uninstall.sql'] as $relative) {
        if (!isset($tree[$relative])) {
            throw new RuntimeException('key release artifact is missing: ' . $relative);
        }
        $keyFileHashes[$relative] = $tree[$relative];
    }
    $appSource = file_get_contents($package . '/config/app.php');
    preg_match("/'version'\\s*=>\\s*'([^']+)'/", is_string($appSource) ? $appSource : '', $match);
    if (!isset($match[1])) {
        throw new RuntimeException('cannot read package version');
    }
    $migrations = $migrationNames($root . '/migrations');
    return [
        'schema' => 'sand-iam.candidate-manifest/v1',
        'kind' => 'candidate-review-only',
        'version' => $match[1],
        'migration_file_count' => count($migrations),
        'migrations' => $migrations,
        'key_file_hashes' => $keyFileHashes,
        'package_sha256' => hash('sha256', implode("\n", array_map(static fn (string $path, string $hash): string => $path . ':' . $hash, array_keys($tree), $tree))),
    ];
};

if ($printCandidateManifest) {
    try {
        echo json_encode($candidateManifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, "[FAIL] candidate manifest cannot enumerate package artifacts: {$exception->getMessage()}\n");
        exit(1);
    }
}

$assert('release artifact payload contains no symbolic links', static function () use ($releaseArtifactFiles): bool {
    $releaseArtifactFiles();
    return true;
});

$builderSource = file_get_contents($root . '/tools/build-lifecycle.php');
$manifestSource = '';
if (is_string($builderSource)
    && preg_match('/\\$migrations\\s*=\\s*\\[(.*?)\\];/s', $builderSource, $manifestMatch) === 1) {
    $manifestSource = $manifestMatch[1];
}
preg_match_all("/^\\s*'([0-9]{3}_[^']+\\.pgsql)',$/m", $manifestSource, $builderMatches);
$manifestMigrationNames = $builderMatches[1] ?? [];
$updateSource = '';
if (is_string($builderSource)
    && preg_match('/\\$updateNames\\s*=\\s*\\[(.*?)\\];/s', $builderSource, $updateMatch) === 1) {
    $updateSource = $updateMatch[1];
}
preg_match_all("/^\\s*'([0-9]{3}_[^']+\\.pgsql)',$/m", $updateSource, $updateMatches);
$updateMigrationNames = $updateMatches[1] ?? [];

$assert('migration revisions are contiguous and begin at 001', static function () use ($migrationNames, $root): bool {
    $names = $migrationNames($root . '/migrations');
    $revisions = [];
    foreach ($names as $name) {
        if (preg_match('/^(\d{3})_.*\.pgsql$/', $name, $matches) !== 1) {
            return false;
        }
        $revisions[(int) $matches[1]] = true;
    }
    $revisionNumbers = array_keys($revisions);
    sort($revisionNumbers, SORT_NUMERIC);
    return $revisionNumbers !== [] && $revisionNumbers === range(1, max($revisionNumbers));
});

$assert('lifecycle builder manifest declares every root migration', static function () use ($migrationNames, $root, $manifestMigrationNames): bool {
    return $manifestMigrationNames !== [] && $migrationNames($root . '/migrations') === $manifestMigrationNames;
});

$assert('root and plugin migration payloads have matching names and hashes', static function () use ($migrationNames, $fileHashes, $root, $package): bool {
    $names = $migrationNames($root . '/migrations');
    return $names !== []
        && $names === $migrationNames($package . '/migrations')
        && $fileHashes($root . '/migrations', $names) === $fileHashes($package . '/migrations', $names);
});

$assert('root and plugin lifecycle payloads have matching hashes', static function () use ($root, $package): bool {
    foreach (['install.sql', 'update.sql', 'uninstall.sql'] as $file) {
        if (!is_file($root . '/' . $file) || !is_file($package . '/' . $file)
            || hash_file('sha256', $root . '/' . $file) !== hash_file('sha256', $package . '/' . $file)) {
            return false;
        }
    }
    return true;
});

$assert('normal 0.7.3 payload excludes historical failed-upgrade recovery descriptors', static function () use ($releaseArtifactFiles): bool {
    $files = $releaseArtifactFiles();
    foreach (sandIamGeneratedDescriptorPaths() as $descriptor) {
        if (isset($files[$descriptor])) {
            return false;
        }
    }
    return true;
});

$assert('generated lifecycle separates full install from guarded 0.7.2 to 0.7.3 update and safe cleanup payload', static function () use ($root, $manifestMigrationNames, $updateMigrationNames): bool {
    $install = file_get_contents($root . '/install.sql');
    $update = file_get_contents($root . '/update.sql');
    $uninstall = file_get_contents($root . '/uninstall.sql');
    if (!is_string($install) || !is_string($update) || !is_string($uninstall)) {
        return false;
    }

    $collapse = static function (string $sql): string {
        $lines = preg_split('/\\R/u', $sql);
        if (!is_array($lines)) {
            throw new RuntimeException('cannot split migration SQL');
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
        return rtrim(implode("\n", $result));
    };

    foreach ($manifestMigrationNames as $offset => $name) {
        $source = file_get_contents($root . '/migrations/' . $name);
        $payload = is_string($source) ? $collapse($source) : '';
        if ($name === '038_auth_rate_limit_retention.pgsql') {
            // Only the generated fresh-install index probe differs from the
            // immutable published migration; all remaining bytes must match.
            $payload = str_replace(
                'pg_get_indexdef(actual_index.indexrelid, 3, true) IS NULL',
                'actual_index.indnatts = 2 AND actual_index.indnkeyatts = 2',
                $payload,
                $replacements
            );
            if ($replacements !== 1) {
                throw new RuntimeException('Migration 038 index compatibility source changed');
            }
        }
        if ($offset >= 4 && !str_contains($install, $payload)) {
            throw new RuntimeException('install is missing migration payload ' . $name);
        }
        if (!in_array($name, $updateMigrationNames, true) && str_contains($update, '-- lifecycle source: migrations/' . $name)) {
            throw new RuntimeException('update replays historical migration ' . $name);
        }
    }
    if ($updateMigrationNames !== [
        '041_authorization_scope_integrity.pgsql',
    ]) {
        throw new RuntimeException('0.7.3 update manifest must contain only 041');
    }
    $preflight = file_get_contents($root . '/lifecycle/update-072-to-073-preflight.pgsql');
    if (!is_string($preflight) || !str_contains($update, '-- lifecycle source: lifecycle/update-072-to-073-preflight.pgsql')
        || !str_contains($update, $collapse($preflight))
        || strpos($update, $collapse($preflight)) > strpos($update, '-- lifecycle source: migrations/041_authorization_scope_integrity.pgsql')) {
        throw new RuntimeException('0.7.3 update must admit exact 001-040 before 041');
    }
    foreach ($updateMigrationNames as $name) {
        $source = file_get_contents($root . '/migrations/' . $name);
        $payload = is_string($source) ? $collapse($source) : '';
        if (in_array($name, $updateMigrationNames, true)) {
            $beginOffset = strpos($payload, "\nBEGIN;");
            $commitOffset = strrpos($payload, "\nCOMMIT;");
            if ($beginOffset === false || $commitOffset === false || $beginOffset >= $commitOffset) {
                throw new RuntimeException($name . ' has no composable outer transaction');
            }
            $payload = rtrim(
                substr($payload, 0, $beginOffset + 1)
                . substr($payload, $beginOffset + strlen("\nBEGIN;"), $commitOffset - ($beginOffset + strlen("\nBEGIN;")))
            );
        }
        if ($payload === '' || !str_contains($update, $payload)) {
            throw new RuntimeException('update is missing release migration payload ' . $name);
        }
    }
    if (!str_contains($update, "\nBEGIN;\n-- lifecycle source: lifecycle/update-072-to-073-preflight.pgsql")
        || !str_ends_with($update, "COMMIT;\n")) {
        throw new RuntimeException('0.7.3 update must compose preflight and 041 under one explicit transaction');
    }
    if (!str_contains($uninstall, 'DROP TABLE IF EXISTS sand_iam_security_operation')
        || !str_contains($uninstall, 'DROP TABLE IF EXISTS sand_iam_application_business_action')) {
        throw new RuntimeException('uninstall does not remove every generated migration table');
    }

    // These links point from an otherwise earlier principal table to a table
    // scheduled for deletion first. They must be detached explicitly: CASCADE
    // would hide lifecycle omissions and could reach host-owned objects.
    $reverseForeignKeys = [
        'sand_iam_oauth_client:sand_iam_oauth_registration_token' => [
            'ALTER TABLE IF EXISTS sand_iam_oauth_client DROP CONSTRAINT IF EXISTS fk_sand_iam_oauth_client_dcr_token;',
        ],
        'sand_iam_auth_session:sand_iam_identity_binding' => [
            'ALTER TABLE IF EXISTS sand_iam_auth_session DROP CONSTRAINT IF EXISTS fk_sand_iam_auth_session_federation_binding;',
            'ALTER TABLE IF EXISTS sand_iam_auth_session DROP CONSTRAINT IF EXISTS fk_sand_iam_auth_session_identity_binding;',
        ],
        'sand_iam_policy:sand_iam_policy_version' => [
            'ALTER TABLE IF EXISTS sand_iam_policy DROP CONSTRAINT IF EXISTS fk_sand_iam_policy_published_version;',
            'ALTER TABLE IF EXISTS sand_iam_policy DROP CONSTRAINT IF EXISTS fk_sand_iam_policy_published_version_owner;',
        ],
    ];
    foreach ($reverseForeignKeys as $constraints) {
        foreach ($constraints as $constraint) {
            if (!str_contains($uninstall, $constraint)) {
                throw new RuntimeException('uninstall does not detach reverse foreign key: ' . $constraint);
            }
        }
    }
    if (preg_match('/DROP\\s+TABLE[^;]*\\s+CASCADE\\b/i', $uninstall) === 1
        || preg_match('/DROP\\s+SCHEMA[^;]*\\s+CASCADE\\b/i', $uninstall) === 1) {
        throw new RuntimeException('uninstall must not use CASCADE');
    }
    if (preg_match('/DROP\\s+TABLE[^;]*\\bsand_system_/i', $uninstall) === 1
        || preg_match('/\\bTRUNCATE\\s+(?:TABLE\\s+)?sand_system_/i', $uninstall) === 1) {
        throw new RuntimeException('uninstall must not remove or truncate host-owned system tables');
    }
    foreach (['DELETE FROM sand_system_role_menu', 'DELETE FROM sand_system_menu'] as $hostCleanup) {
        if (substr_count($uninstall, $hostCleanup) !== 1) {
            throw new RuntimeException('uninstall host cleanup must remain limited to SandIAM menu bindings');
        }
    }

    $schema = (string) file_get_contents($root . '/lifecycle/base.pgsql');
    foreach ($manifestMigrationNames as $name) {
        $schema .= "\n" . (string) file_get_contents($root . '/migrations/' . $name);
    }
    $foreignKeyPairs = [];
    preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\\s+(sand_iam_[a-z0-9_]+)\\s*\\((.*?)\\);/si', $schema, $tables, PREG_SET_ORDER);
    foreach ($tables as $table) {
        preg_match_all('/REFERENCES\\s+(sand_iam_[a-z0-9_]+)/i', (string) $table[2], $targets);
        foreach ($targets[1] ?? [] as $target) {
            if ($table[1] !== $target) {
                $foreignKeyPairs[$table[1] . ':' . $target] = true;
            }
        }
    }
    $statements = preg_split('/;\\s*(?:\\R|$)/u', $schema);
    if (!is_array($statements)) {
        throw new RuntimeException('cannot inspect lifecycle foreign keys');
    }
    foreach ($statements as $statement) {
        if (preg_match('/\\bALTER\\s+TABLE(?:\\s+IF\\s+EXISTS)?(?:\\s+ONLY)?\\s+(sand_iam_[a-z0-9_]+)\\b/is', $statement, $tableMatch) !== 1) {
            continue;
        }
        preg_match_all('/\\bREFERENCES\\s+(sand_iam_[a-z0-9_]+)/i', $statement, $referenceMatches);
        foreach ($referenceMatches[1] ?? [] as $target) {
            if ($tableMatch[1] !== $target) {
                $foreignKeyPairs[$tableMatch[1] . ':' . $target] = true;
            }
        }
    }
    preg_match_all('/\\bCREATE\\s+TABLE(?:\\s+IF\\s+NOT\\s+EXISTS)?\\s+(sand_iam_[a-z0-9_]+)\\b/i', $schema, $createdTables);
    preg_match_all('/DROP TABLE IF EXISTS\\s+(sand_iam_[a-z0-9_]+);/i', $uninstall, $droppedTables);
    $createdTableSet = array_fill_keys($createdTables[1] ?? [], true);
    $droppedTableSet = array_fill_keys($droppedTables[1] ?? [], true);
    $missingDroppedTables = array_keys(array_diff_key($createdTableSet, $droppedTableSet));
    sort($missingDroppedTables, SORT_STRING);
    if ($missingDroppedTables !== []) {
        throw new RuntimeException('uninstall is missing owned table cleanup: ' . implode(', ', $missingDroppedTables));
    }
    $dropPositions = [];
    foreach ($droppedTables[1] ?? [] as $position => $table) {
        $dropPositions[$table] ??= $position;
    }
    foreach ($foreignKeyPairs as $pair => $_) {
        [$dependent, $principal] = explode(':', $pair, 2);
        if (!isset($dropPositions[$dependent], $dropPositions[$principal])) {
            throw new RuntimeException('uninstall is missing owned foreign-key table: ' . $pair);
        }
        if ($dropPositions[$dependent] > $dropPositions[$principal] && !isset($reverseForeignKeys[$pair])) {
            throw new RuntimeException('uninstall drops principal before dependent without an explicit detached foreign key: ' . $pair);
        }
    }
    foreach (array_keys($reverseForeignKeys) as $pair) {
        if (!isset($foreignKeyPairs[$pair])) {
            throw new RuntimeException('reverse foreign-key contract no longer matches schema: ' . $pair);
        }
    }
    return true;
});

$assert('published 0.6.0 migration 021 remains byte-immutable in root and package payloads', static function () use ($root, $package): bool {
    $expected = 'f263ec1450bbd15d883c1d5b0f3a0fcfd15db602df9d120b1049a0c4cc428db9';
    foreach ([$root . '/migrations/021_admin_permission_catalog.pgsql', $package . '/migrations/021_admin_permission_catalog.pgsql'] as $file) {
        if (!is_file($file) || hash_file('sha256', $file) !== $expected) {
            return false;
        }
    }
    return true;
});

$assert('migration ledger catalogs the published baseline and 037-041 self-register with exact checksums', static function () use ($root, $manifestMigrationNames): bool {
    $ledger = (string) file_get_contents($root . '/migrations/035_schema_migration_ledger.pgsql');
    if ($ledger === '' || !str_contains($ledger, 'CREATE TABLE IF NOT EXISTS sand_iam_schema_migration')
        || !str_contains($ledger, 'migration_file varchar(160) PRIMARY KEY')
        || !str_contains($ledger, 'checksum char(64) NOT NULL')
        || !str_contains($ledger, 'package_version varchar(32) NOT NULL')
        || !str_contains($ledger, 'executed_time timestamp(0) without time zone NOT NULL')) {
        return false;
    }
    if (preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $ledger, $self) !== 1) {
        return false;
    }
    $canonical = preg_replace("/(WITH self_checksum\\(checksum\\) AS \\(VALUES \\(')[^']+/", '${1}__SELF_SHA256__', $ledger, 1);
    if (!is_string($canonical) || hash('sha256', $canonical) !== $self[1]) {
        return false;
    }
    foreach ($manifestMigrationNames as $name) {
        if (in_array($name, ['039_service_grant_nullable_data_class.pgsql', '040_passkey_auth_challenge_identity.pgsql', '041_authorization_scope_integrity.pgsql'], true)) {
            continue;
        }
        if (!str_contains($ledger, "'{$name}'")) {
            return false;
        }
    }
    $draft = (string) file_get_contents($root . '/migrations/037_initialization_draft.pgsql');
    if ($draft === ''
        || preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $draft, $draftChecksum) !== 1
        || hash('sha256', str_replace($draftChecksum[1], '__SELF_SHA256__', $draft)) !== $draftChecksum[1]
        || !str_contains($draft, "INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)")
        || !str_contains($draft, "SELECT '037_initialization_draft.pgsql', 37")
        || !str_contains($draft, '(SELECT count(*) FROM sand_iam_schema_migration) <> 38')
        || !str_contains($draft, 'sand_iam_initialization_draft')
        || !str_contains($draft, 'sand_iam_initialization_draft_revision')
        || !str_contains($draft, 'sand_iam:initialization:save')
        || !str_contains($draft, 'sand_iam:initialization:update')
        || !str_contains($draft, 'sand_iam:initialization:disable')) {
        return false;
    }
    $retention = (string) file_get_contents($root . '/migrations/038_auth_rate_limit_retention.pgsql');
    if ($retention === ''
        || preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $retention, $retentionChecksum) !== 1
        || hash('sha256', str_replace($retentionChecksum[1], '__SELF_SHA256__', $retention)) !== $retentionChecksum[1]
        || !str_contains($retention, "SELECT '038_auth_rate_limit_retention.pgsql', 38")
        || !str_contains($retention, '(SELECT count(*) FROM sand_iam_schema_migration) <> 39')
        || !str_contains($retention, 'idx_sand_iam_auth_rate_limit_retention')) {
        return false;
    }
    $nullableDataClass = (string) file_get_contents($root . '/migrations/039_service_grant_nullable_data_class.pgsql');
    if ($nullableDataClass === ''
        || preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $nullableDataClass, $nullableChecksum) !== 1
        || hash('sha256', str_replace($nullableChecksum[1], '__SELF_SHA256__', $nullableDataClass)) !== $nullableChecksum[1]
        || !str_contains($nullableDataClass, "SELECT '039_service_grant_nullable_data_class.pgsql', 39")
        || !str_contains($nullableDataClass, '(SELECT count(*) FROM sand_iam_schema_migration) <> 40')
        || !str_contains($nullableDataClass, 'ALTER COLUMN data_class DROP NOT NULL')
        || !str_contains($nullableDataClass, "package_version <> '0.7.2'")) {
        return false;
    }
    $passkeyChallengeIdentity = (string) file_get_contents($root . '/migrations/040_passkey_auth_challenge_identity.pgsql');
    if ($passkeyChallengeIdentity === ''
        || preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $passkeyChallengeIdentity, $passkeyChallengeChecksum) !== 1
        || hash('sha256', str_replace($passkeyChallengeChecksum[1], '__SELF_SHA256__', $passkeyChallengeIdentity)) !== $passkeyChallengeChecksum[1]
        || !str_contains($passkeyChallengeIdentity, "SELECT '040_passkey_auth_challenge_identity.pgsql', 40")
        || !str_contains($passkeyChallengeIdentity, '(SELECT count(*) FROM sand_iam_schema_migration) <> 41')
        || !str_contains($passkeyChallengeIdentity, "CHECK (purpose = 'webauthn_auth' OR identity_id IS NOT NULL)")
        || !str_contains($passkeyChallengeIdentity, "package_version <> '0.7.2'")) {
        return false;
    }
    $authorizationScopeIntegrity = (string) file_get_contents($root . '/migrations/041_authorization_scope_integrity.pgsql');
    if ($authorizationScopeIntegrity === ''
        || preg_match("/WITH self_checksum\\(checksum\\) AS \\(VALUES \\('([0-9a-f]{64})'\\)\\)/", $authorizationScopeIntegrity, $authorizationScopeChecksum) !== 1
        || hash('sha256', str_replace($authorizationScopeChecksum[1], '__SELF_SHA256__', $authorizationScopeIntegrity)) !== $authorizationScopeChecksum[1]
        || !str_contains($authorizationScopeIntegrity, "SELECT '041_authorization_scope_integrity.pgsql', 41")
        || !str_contains($authorizationScopeIntegrity, '(SELECT count(*) FROM sand_iam_schema_migration) <> 42')
        || !str_contains($authorizationScopeIntegrity, 'ALTER TABLE sand_iam_policy ALTER COLUMN action TYPE varchar(96)')
        || !str_contains($authorizationScopeIntegrity, 'fk_sand_iam_policy_published_version_owner')
        || !str_contains($authorizationScopeIntegrity, "package_version <> '0.7.3'")) {
        return false;
    }
    return str_contains($ledger, 'migration ledger checksum or package-version conflict; refusing to continue')
        && str_contains($ledger, 'exact legacy or ledger-backed relation fingerprint is incompatible')
        && str_contains($ledger, 'migration ledger contains an unknown migration filename; refusing to continue')
        && str_contains($ledger, "('sand_iam_identity_provider', 'application_id', 'bigint', 'YES', false)")
        && str_contains($ledger, "'ck_sand_iam_identity_provider_scope', 'c', NULL, 'checkscope_type=''application''andapplication_idisnotnullorscope_type=''organization''andapplication_idisnull'")
        && str_contains($ledger, 'IF matched_columns <> 30 THEN');
});

$assert('composer declares complete platform and locked SAML runtime dependency', static function () use ($package): bool {
    $manifest = json_decode((string) file_get_contents($package . '/composer.json'), true);
    $lock = json_decode((string) file_get_contents($package . '/composer.lock'), true);
    if (!is_array($manifest) || !is_array($lock)
        || ($manifest['require']['php'] ?? null) !== '>=8.2'
        || ($manifest['require']['onelogin/php-saml'] ?? null) !== '^4.3.2') {
        return false;
    }
    foreach (['ctype', 'curl', 'dom', 'json', 'ldap', 'libxml', 'mbstring', 'openssl', 'pdo', 'pdo_pgsql', 'sodium', 'zip', 'zlib'] as $extension) {
        $requirement = 'ext-' . $extension;
        if (($manifest['require'][$requirement] ?? null) !== '*' || ($lock['platform'][$requirement] ?? null) !== '*') {
            return false;
        }
    }
    foreach ($lock['packages'] ?? [] as $dependency) {
        if (($dependency['name'] ?? null) === 'onelogin/php-saml' && ($dependency['version'] ?? null) === '4.3.2') {
            return true;
        }
    }
    return false;
});

$assert('SAML dependency resolves through composer autoload', static function () use ($package): bool {
    $autoload = $package . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('vendor/autoload.php missing; run composer install before runtime validation');
    }
    require_once $autoload;
    foreach (['OneLogin\\Saml2\\Auth', 'OneLogin\\Saml2\\Settings', 'OneLogin\\Saml2\\Response'] as $class) {
        if (!class_exists($class)) {
            throw new RuntimeException($class . ' is not resolvable');
        }
    }
    return true;
});

$assert('protocol adapter guards and references the declared SAML runtime', static function () use ($package): bool {
    $source = file_get_contents($package . '/app/federation/OneLoginSamlAssertionVerifier.php');
    return is_string($source)
        && str_contains($source, "class_exists('OneLogin\\\\Saml2\\\\Auth')")
        && str_contains($source, '\\OneLogin\\Saml2\\Response');
});

$requiredFiles = [
    'model' => ['app/model/Application.php', 'app/model/SecurityOperation.php'],
    'validation and authorization runtime' => ['app/runtime/ScopeMatcher.php', 'app/runtime/PolicyAuthorizer.php', 'app/runtime/IdentityContextProvider.php', 'app/runtime/ServiceInvocationFactResolver.php', 'app/runtime/ServiceInvocationFactResolverRegistry.php', 'app/runtime/ResolvedInvocationFacts.php', 'app/runtime/ServiceInvocationAuthorizer.php'],
    'logic services' => ['app/service/HumanAuthService.php', 'app/service/IdempotencyService.php', 'app/service/AuditWriter.php'],
    'API and admin controllers' => ['app/api/controller/AuthController.php', 'app/api/controller/RuntimeContextController.php', 'app/admin/controller/ApplicationController.php'],
    'route and configuration' => [
        'config/route.php', 'config/app.php', 'config/process.php', 'config/menu.php',
        'bin/RuntimeConfigurationPreflight.php', 'bin/check-runtime-configuration.php',
        'bin/RuntimeRequirementsPreflight.php', 'bin/check-runtime-requirements.php',
    ],
    'account portal runtime source and packaged assets' => ['../../portal/package.json', '../../portal/pnpm-lock.yaml', '../../portal/scripts/build.mjs', '../../portal/src/app.ts', 'public/account/index.html', 'public/account/account.js'],
    'admin UI payload' => ['../../sandadmin-artd/src/views/plugin/sand-iam/index/index.vue', '../../sandadmin-artd/src/views/plugin/sand-iam/api/types.ts'],
    'SDK and user-facing documentation' => [
        '../../sdk/dart/README.md', '../../sdk/php/composer.json', '../../sdk/typescript/package.json',
        '../../README.md', '../../CONTRIBUTING.md', '../../SECURITY.md', '../../LICENSE', '../../NOTICE', '../../THIRD_PARTY_NOTICES.md', '../../release-build-contract.json',
        '../../docs/user-guide/sand-iam-first-connection.md',
        '../../docs/user-guide/sand-iam-operator-guide.md',
        '../../docs/user-guide/installation-and-upgrade.md',
        '../../docs/user-guide/configuration-reference.md',
        '../../docs/user-guide/application-integration.md',
        '../../docs/user-guide/application-user-guide.md',
        '../../docs/user-guide/security-hardening.md',
        '../../docs/user-guide/backup-and-restore.md',
        '../../docs/user-guide/troubleshooting.md',
    ],
];
foreach ($requiredFiles as $area => $files) {
    $assert($area . ' required payload exists', static function () use ($package, $files): bool {
        foreach ($files as $file) {
            if (!is_file($package . '/' . $file)) {
                return false;
            }
        }
        return true;
    });
}

$assert('CycloneDX SBOM matches committed dependency locks', static function () use ($root): bool {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/generate-sbom.php') . ' --check';
    $output = [];
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0) throw new RuntimeException(implode("\n", $output));
    $document = json_decode((string) file_get_contents($root . '/SBOM.cdx.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($document['bomFormat'] ?? null) !== 'CycloneDX' || ($document['specVersion'] ?? null) !== '1.6') return false;
    $components = is_array($document['components'] ?? null) ? $document['components'] : [];
    $purls = array_column($components, 'purl');
    $w3cComponent = null;
    foreach ($components as $component) {
        if (is_array($component) && ($component['bom-ref'] ?? null) === 'urn:sandiam:vendored:w3c-xmldsig-core-schema@2002-02-08') {
            $w3cComponent = $component;
            break;
        }
    }
    return count($components) >= 7
        && in_array('pkg:composer/onelogin/php-saml@4.3.2', $purls, true)
        && in_array('pkg:composer/robrichards/xmlseclibs@3.1.5', $purls, true)
        && in_array('pkg:npm/typescript@5.9.3', $purls, true)
        && in_array('pkg:pub/http@1.6.0', $purls, true)
        && is_array($w3cComponent)
        && ($w3cComponent['type'] ?? null) === 'data'
        && ($w3cComponent['scope'] ?? null) === 'required'
        && (($w3cComponent['licenses'][0]['license']['name'] ?? null) === 'W3C Software Notice and License');
});

$assert('TypeScript SDK exports resolve to packaged ESM and declaration files only', static function () use ($root, $releaseArtifactFiles): bool {
    $sdkRoot = $root . '/sdk/typescript';
    $packageJson = file_get_contents($sdkRoot . '/package.json');
    try {
        $manifest = is_string($packageJson) ? json_decode($packageJson, true, 512, JSON_THROW_ON_ERROR) : null;
    } catch (JsonException $exception) {
        throw new RuntimeException('TypeScript SDK package.json is invalid JSON', 0, $exception);
    }
    if (!is_array($manifest) || !is_array($manifest['exports'] ?? null) || !isset($manifest['exports']['.'])) {
        throw new RuntimeException('TypeScript SDK must declare a root export');
    }

    /** @return list<string> */
    $exportTargets = static function (mixed $value) use (&$exportTargets): array {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $targets = [];
        foreach ($value as $nested) {
            array_push($targets, ...$exportTargets($nested));
        }
        return $targets;
    };

    $targets = $exportTargets($manifest['exports']);
    if (is_string($manifest['types'] ?? null)) {
        $targets[] = $manifest['types'];
    }
    $targets = array_values(array_unique($targets));
    if ($targets === []) {
        throw new RuntimeException('TypeScript SDK exports declare no runtime or declaration target');
    }

    $safeTarget = static function (string $target): string {
        if (!str_starts_with($target, './dist/')
            || str_contains($target, '\\')
            || str_contains($target, '..')
            || !preg_match('#^\./dist/[A-Za-z0-9._/-]+\.(?:js|d\.ts)$#', $target)) {
            throw new RuntimeException('TypeScript SDK export escapes the approved dist boundary: ' . $target);
        }
        return 'sdk/typescript/' . substr($target, 2);
    };

    /** @var array<string,true> $expected */
    $expected = [];
    $pending = [];
    foreach ($targets as $target) {
        $pending[] = $safeTarget($target);
    }

    // ESM imports and declaration re-exports are part of the public runtime
    // closure even when package.json only exposes the root entrypoint.
    while ($pending !== []) {
        $relative = array_pop($pending);
        if (!is_string($relative) || isset($expected[$relative])) {
            continue;
        }
        $expected[$relative] = true;
        $absolute = $root . '/' . $relative;
        if (!is_file($absolute)) {
            throw new RuntimeException('TypeScript SDK export target is missing: ' . $relative);
        }
        $source = file_get_contents($absolute);
        if (!is_string($source)) {
            throw new RuntimeException('cannot read TypeScript SDK export target: ' . $relative);
        }
        if (preg_match_all("#(?:from\\s*|export\\s*\\*\\s*from\\s*)['\"](\\./[^'\"]+)['\"]#", $source, $matches) !== false) {
            foreach ($matches[1] as $import) {
                if (!is_string($import)) {
                    continue;
                }
                $resolved = dirname($relative) . '/' . substr($import, 2);
                if (str_ends_with($relative, '.d.ts') && str_ends_with($resolved, '.js')) {
                    $resolved = substr($resolved, 0, -3) . '.d.ts';
                }
                if (!str_starts_with($resolved, 'sdk/typescript/dist/') || str_contains($resolved, '..')) {
                    throw new RuntimeException('TypeScript SDK runtime import escapes the approved dist boundary: ' . $import);
                }
                $pending[] = $resolved;
            }
        }
    }

    $payload = $releaseArtifactFiles();
    foreach (array_keys($expected) as $relative) {
        if (!isset($payload[$relative])) {
            throw new RuntimeException('TypeScript SDK export target is absent from candidate payload: ' . $relative);
        }
    }
    foreach (array_keys($payload) as $relative) {
        if (preg_match('#(?:^|/)dist/#', $relative) === 1 && !str_starts_with($relative, 'sdk/typescript/dist/')) {
            throw new RuntimeException('candidate payload contains a dist artifact outside the TypeScript SDK allowlist: ' . $relative);
        }
    }
    return isset($expected['sdk/typescript/dist/index.js'], $expected['sdk/typescript/dist/index.d.ts'], $expected['sdk/typescript/dist/management.js'], $expected['sdk/typescript/dist/management.d.ts']);
});

$assert('release build contract locks toolchain and reviewed runtime payloads', static function () use ($root, $releaseArtifactFiles, $cleanGitPayloadMaps): bool {
    $contractPath = $root . '/release-build-contract.json';
    $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($contract)
        || ($contract['schema'] ?? null) !== 'sand-iam.release-build-contract/v1'
        || ($contract['kind'] ?? null) !== 'reviewed-runtime-payload-inputs'
        || ($contract['source']['formal_builder'] ?? null) !== 'git-blob-only'
        || ($contract['source']['repeat_build'] ?? null) !== 'independent-git-stage') {
        return false;
    }
    $toolchain = $contract['toolchain'] ?? null;
    if (!is_array($toolchain)
        || ($toolchain['php'] ?? null) !== PHP_VERSION
        || ($toolchain['zip_extension'] ?? null) !== phpversion('zip')
        || ($toolchain['libzip'] ?? null) !== (defined('ZipArchive::LIBZIP_VERSION') ? ZipArchive::LIBZIP_VERSION : null)) {
        return false;
    }
    $commandVersion = static function (array $command): string {
        $output = [];
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $status);
        if ($status !== 0) throw new RuntimeException('cannot read fixed build toolchain version');
        return trim(implode("\n", $output));
    };
    $composerVersion = $commandVersion(['composer', '--version']);
    $nodeVersion = $commandVersion(['node', '--version']);
    $pnpmVersion = $commandVersion(['pnpm', '--version']);
    if (preg_match('/Composer version ([0-9]+\.[0-9]+\.[0-9]+)/', $composerVersion, $composerMatch) !== 1
        || ($toolchain['composer'] ?? null) !== $composerMatch[1]
        || ($toolchain['node'] ?? null) !== $nodeVersion
        || ($toolchain['pnpm'] ?? null) !== $pnpmVersion
        || ($toolchain['typescript'] ?? null) !== '5.9.3') {
        return false;
    }
    $composer = $contract['composer'] ?? null;
    $typescript = $contract['typescript'] ?? null;
    $installedComposer = (string) file_get_contents($root . '/plugin/sand-iam/vendor/composer/installed.php');
    if (!is_array($composer) || !is_array($typescript)
        || ($composer['lock_sha256'] ?? null) !== hash_file('sha256', $root . '/plugin/sand-iam/composer.lock')
        || ($composer['environment'] ?? null) !== [
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER_ROOT_VERSION' => '0.7.3',
        ]
        || ($composer['arguments'] ?? null) !== [
            'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--classmap-authoritative',
            '--no-interaction', '--no-plugins', '--no-scripts',
        ]
        || substr_count($installedComposer, "'pretty_version' => '0.7.3'") !== 2
        || substr_count($installedComposer, "'version' => '0.7.3.0'") !== 2
        || str_contains($installedComposer, "'pretty_version' => '0.7.2'")
        || ($typescript['lock_sha256'] ?? null) !== hash_file('sha256', $root . '/sdk/typescript/pnpm-lock.yaml')
        || ($typescript['package_integrity'] ?? null) !== 'sha512-jl1vZzPDinLr9eUt3J/t7V6FgNEw9QjvBPdysz9KfQDD41fQrC2Y4vKQdiaUpFT4bXlb1RHhLpp8wtm6M5TgSw==') {
        return false;
    }
    $payload = $releaseArtifactFiles();
    $generatedPayloads = $contract['generated_payloads'] ?? null;
    $requiredGeneratedPayloads = ['plugin/sand-iam/vendor', 'sdk/typescript/dist'];
    if (!is_array($generatedPayloads)) {
        return false;
    }
    $declaredGeneratedPayloads = array_keys($generatedPayloads);
    sort($declaredGeneratedPayloads, SORT_STRING);
    if ($declaredGeneratedPayloads !== $requiredGeneratedPayloads) {
        return false;
    }
    $gitPayloadMaps = $cleanGitPayloadMaps($requiredGeneratedPayloads);
    foreach ($requiredGeneratedPayloads as $directory) {
        $expected = $generatedPayloads[$directory] ?? null;
        if (!is_array($expected)) {
            return false;
        }
        $expectedFields = array_keys($expected);
        sort($expectedFields, SORT_STRING);
        if ($expectedFields !== ['file_count', 'tree_sha256']
            || !is_int($expected['file_count']) || $expected['file_count'] < 1
            || !is_string($expected['tree_sha256']) || preg_match('/^[0-9a-f]{64}$/', $expected['tree_sha256']) !== 1) {
            return false;
        }
        if ($gitPayloadMaps !== null) {
            $map = $gitPayloadMaps[$directory] ?? null;
            if (!is_array($map)) return false;
        } else {
            $absolute = $root . '/' . $directory;
            if (!is_dir($absolute) || is_link($absolute)) return false;
            $map = [];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) continue;
                $relative = substr($file->getPathname(), strlen($absolute) + 1);
                $hash = hash_file('sha256', $file->getPathname());
                if (!is_string($hash)) return false;
                $map[$relative] = $hash;
            }
            ksort($map, SORT_STRING);
        }
        if (($expected['file_count'] ?? null) !== count($map)
            || ($expected['tree_sha256'] ?? null) !== hash('sha256', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
            return false;
        }
    }
    return isset($payload['plugin/sand-iam/vendor/autoload.php'], $payload['sdk/typescript/dist/index.js']);
});

$assert('eligible release payload is clean, tracked, and matches HEAD Git blobs', static function () use ($root, $releaseArtifactFiles, $hasGitMetadataAncestor): bool {
    $workspace = $root;
    $output = [];
    exec('git -C ' . escapeshellarg($workspace) . ' rev-parse --show-toplevel 2>&1', $output, $status);
    $gitRoot = $status === 0 ? trim(implode("\n", $output)) : '';
    if ($status !== 0) {
        // Isolated non-Git fixtures test package semantics; formal candidate
        // construction always performs this gate against a real Git commit.
        return !$hasGitMetadataAncestor($root);
    }
    if ($gitRoot === '' || !is_dir($gitRoot)) return false;
    $rootPath = realpath($root);
    $gitRootPath = realpath($gitRoot);
    if (!is_string($rootPath) || !is_string($gitRootPath) || ($rootPath !== $gitRootPath && !str_starts_with($rootPath, $gitRootPath . '/'))) {
        throw new RuntimeException('resolved package root is outside the Git worktree');
    }
    $sourcePrefix = $rootPath === $gitRootPath ? '' : substr($rootPath, strlen($gitRootPath) + 1);
    $dirty = [];
    exec('git -C ' . escapeshellarg($gitRootPath) . ' status --porcelain=v1 --untracked-files=all -- ' . escapeshellarg($sourcePrefix === '' ? '.' : $sourcePrefix) . ' 2>&1', $dirty, $dirtyStatus);
    if ($dirtyStatus !== 0 || $dirty !== []) return false;
    foreach (array_keys($releaseArtifactFiles()) as $relative) {
        $path = ($sourcePrefix === '' ? '' : $sourcePrefix . '/') . $relative;
        $tracked = [];
        exec('git -C ' . escapeshellarg($gitRootPath) . ' ls-files --error-unmatch -- ' . escapeshellarg($path) . ' 2>&1', $tracked, $trackedStatus);
        if ($trackedStatus !== 0) {
            throw new RuntimeException('eligible release payload is not tracked by Git: ' . $relative);
        }
    }
    return true;
});

/** @return list<string> */
$textFiles = static function (array $directories, array $extensions): array {
    $result = [];
    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }
            $result[] = $file->getPathname();
        }
    }
    return $result;
};

$assert('PostgreSQL SQL has no sa_* business table or MySQL dialect', static function () use ($textFiles, $root, $package): bool {
    $patterns = [
        '/\\b(?:create|alter|drop)\\s+table(?:\\s+if\\s+(?:not\\s+)?exists)?\\s+sa_[a-z0-9_]+/i',
        '/\\b(?:insert\\s+into|update|delete\\s+from)\\s+sa_[a-z0-9_]+/i',
        '/\\b(?:auto_increment|unsigned|engine\\s*=|charset\\s*=|collate\\s*=)\\b/i',
    ];
    $files = $textFiles([$root . '/migrations', $root . '/lifecycle', $package . '/migrations'], ['sql', 'pgsql']);
    foreach ([$root . '/install.sql', $root . '/update.sql', $root . '/uninstall.sql', $package . '/install.sql', $package . '/update.sql', $package . '/uninstall.sql'] as $file) {
        if (!is_file($file)) {
            return false;
        }
        $files[] = $file;
    }
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if (!is_string($content)) {
            return false;
        }
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return false;
            }
        }
    }
    return true;
});

$assert('release metadata versions and host support are consistent', static function () use ($root, $package): bool {
    $readIni = static function (string $file): ?array {
        $ini = parse_ini_file($file);
        return is_array($ini) ? $ini : null;
    };
    $rootInfo = $readIni($root . '/info.ini');
    $packageInfo = $readIni($package . '/info.ini');
    if (!is_array($rootInfo) || !is_array($packageInfo)
        || !is_string($rootInfo['version'] ?? null)
        || !is_string($packageInfo['version'] ?? null)
        || !is_string($rootInfo['support'] ?? null)
        || !is_string($packageInfo['support'] ?? null)
        || !is_string($rootInfo['website'] ?? null)
        || !is_string($packageInfo['website'] ?? null)
        || $rootInfo['support'] !== $packageInfo['support']
        || $rootInfo['website'] !== $packageInfo['website']
        || preg_match('/^\d+\.x(?:\|\d+\.x)*$/', $rootInfo['support']) !== 1) {
        return false;
    }
    $supportsHostVersion = static function (string $support, string $hostVersion): bool {
        if (preg_match('/^(\d+)\./', $hostVersion, $match) !== 1) {
            return false;
        }
        return in_array($match[1] . '.x', explode('|', $support), true);
    };
    if (!$supportsHostVersion($rootInfo['support'], '6.0.11')) {
        return false;
    }
    $appSource = file_get_contents($package . '/config/app.php');
    preg_match("/'version'\\s*=>\\s*'([^']+)'/", is_string($appSource) ? $appSource : '', $match);
    $version = isset($match[1]) ? $match[1] : null;
    return $version !== null && $version === $rootInfo['version'] && $version === $packageInfo['version'];
});

/** @return non-empty-string */
$externalRegularFile = static function (?string $path, string $label) use ($root): string {
    if (!is_string($path) || $path === '') {
        throw new RuntimeException('missing ' . $label);
    }
    if (!str_starts_with($path, '/') || preg_match('#/(?:\.{1,2})(?:/|$)#', $path) === 1) {
        throw new RuntimeException($label . ' must use an absolute canonical path outside the sand-iam package root');
    }
    $rootPath = realpath($root);
    if ($rootPath === false) {
        throw new RuntimeException('cannot resolve sand-iam package root');
    }
    if ($path === $rootPath || str_starts_with($path, $rootPath . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException($label . ' must be stored outside the sand-iam package root');
    }
    $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
    $current = '';
    foreach ($parts as $offset => $part) {
        $current .= '/' . $part;
        $stat = lstat($current);
        if ($stat === false) {
            throw new RuntimeException($label . ' path does not exist');
        }
        if (($stat['mode'] & 0170000) === 0120000) {
            throw new RuntimeException($label . ' path must not contain symbolic links');
        }
        if ($offset < count($parts) - 1 && (($stat['mode'] & 0170000) !== 0040000)) {
            throw new RuntimeException($label . ' parent is not a directory');
        }
    }
    $finalStat = lstat($path);
    if ($finalStat === false || (($finalStat['mode'] & 0170000) !== 0100000) || !is_file($path)) {
        throw new RuntimeException($label . ' must be a regular file');
    }
    return $path;
};

/** @return string */
$strictBase64 = static function (string $encoded, int $expectedLength, string $label): string {
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || base64_encode($decoded) !== $encoded || strlen($decoded) !== $expectedLength) {
        throw new RuntimeException($label . ' must be strict base64 for exactly ' . $expectedLength . ' bytes');
    }
    return $decoded;
};

$canonicalize = null;
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map($canonicalize, $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = $canonicalize($item);
    }
    return $value;
};

$verifiedTrustedManifest = null;
$validateTrustedManifest = static function () use (&$verifiedTrustedManifest, $trustedManifestPath, $externalRegularFile, $candidateManifest, $strictBase64): bool {
    $manifestPath = $externalRegularFile($trustedManifestPath, '--trusted-manifest=/path/outside/sand-iam');
    try {
        $trusted = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('trusted manifest is not valid JSON', 0, $exception);
    }
    if (!is_array($trusted)) {
        throw new RuntimeException('trusted manifest must be a JSON object');
    }
    $candidate = $candidateManifest();
    if (($trusted['schema'] ?? null) !== 'sand-iam.release-provenance/v1'
        || ($trusted['kind'] ?? null) !== 'external-reviewed-release') {
        throw new RuntimeException('trusted manifest has no external-reviewed release schema');
    }
    foreach (['version', 'migration_file_count', 'migrations', 'package_sha256'] as $field) {
        if (($trusted[$field] ?? null) !== $candidate[$field]) {
            throw new RuntimeException('trusted manifest ' . $field . ' differs from the candidate package');
        }
    }
    if (!is_array($trusted['key_file_hashes'] ?? null) || $trusted['key_file_hashes'] !== $candidate['key_file_hashes']) {
        throw new RuntimeException('trusted manifest key_file_hashes differ from the candidate package');
    }
    $provenance = $trusted['provenance'] ?? null;
    $signature = is_array($provenance) ? ($provenance['signature'] ?? null) : null;
    foreach (['source', 'reference', 'approved_by', 'approved_at'] as $field) {
        if (!is_array($provenance) || !is_string($provenance[$field] ?? null) || trim($provenance[$field]) === '') {
            throw new RuntimeException('trusted manifest provenance.' . $field . ' is required');
        }
    }
    if (!is_array($signature) || ($signature['algorithm'] ?? null) !== 'ed25519' || !is_string($signature['value'] ?? null)) {
        throw new RuntimeException('trusted manifest provenance.signature must use ed25519');
    }
    $strictBase64($signature['value'], 64, 'trusted manifest provenance.signature.value');
    $verifiedTrustedManifest = $trusted;
    return true;
};

$statusOutput = [];
$statusCode = 1;
exec('git -C ' . escapeshellarg($root) . ' status --porcelain', $statusOutput, $statusCode);
$dirty = $statusCode !== 0 || $statusOutput !== [];
if ($dirty) {
    echo "[CANDIDATE] working tree is dirty; package checks prove internal consistency only, not release provenance\n";
} else {
    echo "[CANDIDATE] working tree is clean; package checks still do not certify release provenance\n";
}
if ($releaseMode) {
    $assert('release mode requires a clean working tree', static fn (): bool => !$dirty);
    $assert('release mode requires an external trusted provenance manifest', $validateTrustedManifest);
    $assert('release mode requires an external Ed25519 public key and valid signature', static function () use ($trustedPublicKeyPath, $externalRegularFile, $strictBase64, &$verifiedTrustedManifest, $canonicalize): bool {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('sodium Ed25519 verification is unavailable');
        }
        $keyPath = $externalRegularFile($trustedPublicKeyPath, '--trusted-public-key=/path/outside/sand-iam');
        $encodedKey = trim((string) file_get_contents($keyPath));
        $publicKey = $strictBase64($encodedKey, 32, 'trusted Ed25519 public key');
        if (!is_array($verifiedTrustedManifest)) {
            throw new RuntimeException('trusted manifest must pass content validation before signature verification');
        }
        $signature = $verifiedTrustedManifest['provenance']['signature']['value'];
        $detachedSignature = $strictBase64($signature, 64, 'trusted manifest provenance.signature.value');
        $unsigned = $verifiedTrustedManifest;
        unset($unsigned['provenance']['signature']);
        try {
            $payload = json_encode($canonicalize($unsigned), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('cannot canonicalize unsigned trusted manifest payload', 0, $exception);
        }
        if (!is_string($payload) || !sodium_crypto_sign_verify_detached($detachedSignature, $payload, $publicKey)) {
            throw new RuntimeException('trusted manifest Ed25519 detached signature verification failed');
        }
        return true;
    });
}

$failed = $total - $passed;
echo "Package integrity: passed={$passed}/{$total}; failed={$failed}\n";
if ($failures !== []) {
    echo "Failed items:\n";
    foreach (array_values(array_unique($failures)) as $failure) {
        echo "- {$failure}\n";
    }
}

exit($failed === 0 ? 0 : 1);
