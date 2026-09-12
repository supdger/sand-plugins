<?php

declare(strict_types=1);

/**
 * Build a local, review-only SandIAM candidate from a frozen source snapshot.
 *
 * This command deliberately does not run SQL, start a service, access a host,
 * or contact the network.  It is not a release or an installation command.
 *
 * Usage:
 *   php sand-iam/tools/build-review-candidate.php
 *   php sand-iam/tools/build-review-candidate.php --release-unsigned
 *   php sand-iam/tools/build-review-candidate.php --artifact-root=/absolute/path
 *   php sand-iam/tools/build-review-candidate.php --rebuild-from=/absolute/artifact/snapshot/package --output=/absolute/rebuild
 */

if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('PHP zip extension is required');
}

require_once __DIR__ . '/package-payload-policy.php';
require_once __DIR__ . '/failed-upgrade-recovery-profile-v2.php';

const SAND_IAM_REVIEW_EPOCH = 946684800; // 2000-01-01T00:00:00Z; valid ZIP/DOS time.

$workspace = dirname(__DIR__, 2);
$source = $workspace . '/sand-iam';
$arguments = array_slice($argv, 1);
$artifactRoot = $workspace . '/.artifacts';
$rebuildFrom = null;
$output = null;
$releaseUnsigned = false;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--artifact-root=')) {
        $artifactRoot = substr($argument, strlen('--artifact-root='));
    } elseif (str_starts_with($argument, '--rebuild-from=')) {
        $rebuildFrom = substr($argument, strlen('--rebuild-from='));
    } elseif (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } elseif ($argument === '--release-unsigned') {
        $releaseUnsigned = true;
    } else {
        throw new InvalidArgumentException('Unsupported argument: ' . $argument);
    }
}

if ($releaseUnsigned && ($rebuildFrom !== null || $output !== null)) {
    throw new InvalidArgumentException('--release-unsigned cannot be combined with rebuild arguments');
}

/** @return string */
function checkedCommand(array $arguments, string $failure): string
{
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    $output = [];
    exec($command . ' 2>&1', $output, $status);
    $text = trim(implode(PHP_EOL, $output));
    if ($status !== 0) {
        throw new RuntimeException($failure . ($text === '' ? '' : ': ' . $text));
    }
    return $text;
}

/** @return array{commit:string,tree:string} Git revision bound to a clean SandIAM source subtree. */
function assertUnsignedReleasePrerequisites(string $workspace, string $source): array
{
    if (!is_file($source . '/LICENSE')) {
        throw new RuntimeException('unsigned release candidate requires an approved project LICENSE');
    }
    $status = checkedCommand(
        ['git', '-C', $workspace, 'status', '--porcelain=v1', '--untracked-files=all', '--', 'sand-iam'],
        'cannot inspect SandIAM source cleanliness',
    );
    if ($status !== '') {
        throw new RuntimeException('unsigned release candidate requires a clean committed sand-iam/ source subtree');
    }
    checkedCommand([PHP_BINARY, $source . '/tools/generate-sbom.php', '--check'], 'unsigned release candidate SBOM gate failed');
    checkedCommand([PHP_BINARY, $source . '/tools/check-release-payload.php'], 'unsigned release candidate payload hygiene gate failed');
    checkedCommand([PHP_BINARY, $source . '/tools/check-package-integrity.php'], 'unsigned release candidate package integrity gate failed');
    $revision = checkedCommand(['git', '-C', $workspace, 'rev-parse', 'HEAD'], 'cannot resolve SandIAM source revision');
    if (preg_match('/^[0-9a-f]{40,64}$/', $revision) !== 1) {
        throw new RuntimeException('resolved SandIAM source revision is invalid');
    }
    $tree = checkedCommand(['git', '-C', $workspace, 'rev-parse', 'HEAD:sand-iam'], 'cannot resolve committed SandIAM tree');
    if (preg_match('/^[0-9a-f]{40,64}$/', $tree) !== 1) {
        throw new RuntimeException('resolved SandIAM source tree is invalid');
    }
    return ['commit' => $revision, 'tree' => $tree];
}

function assertExternalArtifactRoot(string $artifactRoot, string $source): void
{
    $parent = realpath(dirname($artifactRoot));
    $sourceRoot = realpath($source);
    if (!is_string($parent) || !is_string($sourceRoot)) {
        throw new RuntimeException('unsigned release artifact root must have an existing parent');
    }
    $target = $parent . '/' . basename($artifactRoot);
    if ($target === $sourceRoot || str_starts_with($target, $sourceRoot . '/')) {
        throw new RuntimeException('unsigned release artifact root must remain outside sand-iam/');
    }
}

/** @return bool */
function reviewExcluded(string $path): bool
{
    return sandIamPayloadExcluded($path);
}

/** @return bool */
function generatedDescriptor(string $path): bool
{
    return in_array($path, sandIamGeneratedDescriptorPaths(), true);
}

/** @return list<string> */
function payloadFiles(string $root, bool $includeSourceDescriptors): array
{
    return sandIamPayloadFiles($root, $includeSourceDescriptors);
}

/** @return array<string, array{sha256:string,bytes:int}> */
function fileMap(string $root, array $files): array
{
    $result = [];
    foreach ($files as $relative) {
        $path = $root . '/' . $relative;
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Candidate payload file is not regular: ' . $relative);
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new RuntimeException('Cannot hash candidate payload file: ' . $relative);
        }
        $result[$relative] = ['sha256' => $hash, 'bytes' => filesize($path)];
    }
    ksort($result, SORT_STRING);
    return $result;
}

/** @return string */
function canonicalJson(array $value): string
{
    $normalize = null;
    $normalize = static function (mixed $item) use (&$normalize): mixed {
        if (is_float($item)) {
            throw new RuntimeException('Canonical JSON forbids floating-point values');
        }
        if (!is_array($item)) {
            return $item;
        }
        if (!array_is_list($item)) {
            ksort($item, SORT_STRING);
        }
        foreach ($item as $key => $child) {
            $item[$key] = $normalize($child);
        }
        return $item;
    };
    $json = json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (!is_string($json)) {
        throw new RuntimeException('Cannot encode canonical JSON');
    }
    return $json;
}

function createDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create directory: ' . $path);
    }
}

function copyNormalized(string $from, string $to): void
{
    createDirectory(dirname($to));
    if (!copy($from, $to) || !chmod($to, 0644) || !touch($to, SAND_IAM_REVIEW_EPOCH)) {
        throw new RuntimeException('Cannot materialize immutable snapshot file: ' . $to);
    }
}

/** @return array{files:array<string,array{sha256:string,bytes:int}>,digest:string} */
function snapshotSource(string $source, string $snapshot): array
{
    $files = payloadFiles($source, true);
    foreach ($files as $relative) {
        copyNormalized($source . '/' . $relative, $snapshot . '/' . $relative);
    }
    $map = fileMap($snapshot, $files);
    return ['files' => $map, 'digest' => hash('sha256', canonicalJson($map))];
}

/** @return array{digest:string,files:array<string,string>,descriptor_sha256:string,update_sha256:string} */
function writeDescriptor(string $stage): array
{
    $files = payloadFiles($stage, false);
    $hashes = [];
    foreach ($files as $relative) {
        $hash = hash_file('sha256', $stage . '/' . $relative);
        if (!is_string($hash)) {
            throw new RuntimeException('Cannot hash descriptor input: ' . $relative);
        }
        $hashes[$relative] = $hash;
    }
    ksort($hashes, SORT_STRING);
    $payload = [
        'algorithm' => 'sandpackage-normalized-package-manifest/v1',
        'files' => $hashes,
        'schema' => 'sandpackage.normalized-package-manifest/v1',
    ];
    $digest = hash('sha256', canonicalJson($payload));
    $updateHash = hash_file('sha256', $stage . '/update.sql');
    if (!is_string($updateHash)) {
        throw new RuntimeException('Cannot hash staged update.sql');
    }
    $descriptor = [
        'app' => 'sand-iam',
        'candidate_payload' => ['algorithm' => 'sandpackage-normalized-package-manifest/v1', 'digest' => $digest],
        'from_version' => '0.6.0',
        'profile' => sandIamFailedUpgradeRecoveryProfileV2($stage),
        'schema' => 'sandpackage.failed-upgrade-recovery/v2',
        'to_version' => '0.7.0',
        'update_lifecycle' => ['path' => 'update.sql', 'sha256' => $updateHash],
    ];
    $json = canonicalJson($descriptor);
    foreach (['recovery/failed-upgrade.v2.json', 'plugin/sand-iam/recovery/failed-upgrade.v2.json'] as $relative) {
        $path = $stage . '/' . $relative;
        createDirectory(dirname($path));
        if (file_put_contents($path, $json) !== strlen($json) || !chmod($path, 0644) || !touch($path, SAND_IAM_REVIEW_EPOCH)) {
            throw new RuntimeException('Cannot write generated recovery descriptor: ' . $relative);
        }
    }
    return ['digest' => $digest, 'files' => $hashes, 'descriptor_sha256' => hash('sha256', $json), 'update_sha256' => $updateHash];
}

/**
 * Descriptor input deliberately excludes both descriptor paths, so this checks
 * representation parity without turning the normalized payload into a
 * self-referential digest.
 *
 * @param list<string> $roots
 */
function assertGeneratedDescriptorParity(array $roots): void
{
    $expected = null;
    $expectedPath = null;

    foreach ($roots as $root) {
        foreach (sandIamGeneratedDescriptorPaths() as $relative) {
            if (!str_ends_with($relative, '.json')) {
                continue;
            }
            $path = $root . '/' . $relative;
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                throw new RuntimeException('Unable to read generated descriptor: ' . $path);
            }
            if ($expected === null) {
                $expected = $contents;
                $expectedPath = $path;
                continue;
            }
            if (!hash_equals($expected, $contents)) {
                throw new RuntimeException('Generated descriptor parity failed: ' . $path . ' differs from ' . $expectedPath);
            }
        }
    }
}

/** @return array{files:array<string,array{sha256:string,bytes:int}>,archive_sha256:string,bytes:int} */
function archiveStage(string $stage, string $archive): array
{
    $files = payloadFiles($stage, true);
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Cannot create candidate ZIP: ' . $archive);
    }
    try {
        $zip->setArchiveComment('');
        foreach ($files as $relative) {
            $path = $stage . '/' . $relative;
            if (!$zip->addFile($path, $relative)) {
                throw new RuntimeException('Cannot add ZIP entry: ' . $relative);
            }
            if (method_exists($zip, 'setMtimeName')) {
                $zip->setMtimeName($relative, SAND_IAM_REVIEW_EPOCH);
            }
            if (method_exists($zip, 'setExternalAttributesName')) {
                $zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, 0100644 << 16);
            }
            if (method_exists($zip, 'setCompressionName')) {
                $zip->setCompressionName($relative, ZipArchive::CM_DEFLATE, 9);
            }
        }
    } finally {
        $zip->close();
    }
    $hash = hash_file('sha256', $archive);
    if (!is_string($hash)) {
        throw new RuntimeException('Cannot hash candidate ZIP');
    }
    return ['files' => fileMap($stage, $files), 'archive_sha256' => $hash, 'bytes' => filesize($archive)];
}

/** @return array{ok:bool,errors:list<string>,files:array<string,array{sha256:string,bytes:int}>} */
function validateArchive(string $stage, string $archive, array $baseSnapshotFiles, array $descriptor): array
{
    $errors = [];
    $stageFiles = fileMap($stage, payloadFiles($stage, true));
    $baseStage = $stageFiles;
    unset($baseStage['recovery/failed-upgrade.v2.json'], $baseStage['plugin/sand-iam/recovery/failed-upgrade.v2.json']);
    if ($baseStage !== $baseSnapshotFiles) {
        $errors[] = 'immutable snapshot parity mismatch outside generated descriptors';
    }
    $rootDescriptor = file_get_contents($stage . '/recovery/failed-upgrade.v2.json');
    $pluginDescriptor = file_get_contents($stage . '/plugin/sand-iam/recovery/failed-upgrade.v2.json');
    $decoded = is_string($rootDescriptor) ? json_decode($rootDescriptor, true) : null;
    if (!is_string($rootDescriptor) || $rootDescriptor !== $pluginDescriptor || !is_array($decoded)
        || $rootDescriptor !== canonicalJson($decoded)
        || ($decoded['schema'] ?? null) !== 'sandpackage.failed-upgrade-recovery/v2'
        || ($decoded['profile']['schema'] ?? null) !== 'sandpackage.failed-upgrade-recovery-profile/v2'
        || ($decoded['profile']['state'] ?? null) !== 'prefix_033_034'
        || ($decoded['candidate_payload']['digest'] ?? null) !== $descriptor['digest']
        || ($decoded['update_lifecycle']['sha256'] ?? null) !== $descriptor['update_sha256']) {
        $errors[] = 'generated recovery descriptor is not canonical, mirrored, or correctly bound';
    }
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Cannot reopen candidate ZIP');
    }
    $zipFiles = [];
    try {
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? ($stat['name'] ?? null) : null;
            if (!is_string($name) || $name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, '..') || reviewExcluded($name)) {
                $errors[] = 'unsafe ZIP path';
                continue;
            }
            if (isset($zipFiles[$name])) {
                $errors[] = 'duplicate ZIP path: ' . $name;
                continue;
            }
            $content = $zip->getFromIndex($i);
            if (!is_string($content)) {
                $errors[] = 'unreadable ZIP path: ' . $name;
                continue;
            }
            $zipFiles[$name] = ['sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
        }
    } finally {
        $zip->close();
    }
    ksort($zipFiles, SORT_STRING);
    if ($zipFiles !== $stageFiles) {
        $errors[] = 'ZIP content/entry list differs from staged payload';
    }
    return ['ok' => $errors === [], 'errors' => $errors, 'files' => $stageFiles];
}

function materializeStage(string $snapshot, string $stage): void
{
    $files = payloadFiles($snapshot, false);
    foreach ($files as $relative) {
        copyNormalized($snapshot . '/' . $relative, $stage . '/' . $relative);
    }
}

/** @return array{archive:string,archive_sha256:string,archive_bytes:int,entry_files:array<string,array{sha256:string,bytes:int}>,descriptor:array{digest:string,files:array<string,string>,descriptor_sha256:string,update_sha256:string},validation:array{ok:bool,errors:list<string>,files:array<string,array{sha256:string,bytes:int}>}} */
function buildFromSnapshot(string $snapshot, string $destination, string $archiveName, array $baseFiles): array
{
    $stage = $destination . '/stage/package';
    createDirectory($stage);
    materializeStage($snapshot, $stage);
    $descriptor = writeDescriptor($stage);
    $archive = $destination . '/' . $archiveName;
    $archiveResult = archiveStage($stage, $archive);
    $validation = validateArchive($stage, $archive, $baseFiles, $descriptor);
    if (!$validation['ok']) {
        throw new RuntimeException('Candidate archive validation failed: ' . implode('; ', $validation['errors']));
    }
    return ['archive' => $archive, 'archive_sha256' => $archiveResult['archive_sha256'], 'archive_bytes' => $archiveResult['bytes'], 'entry_files' => $archiveResult['files'], 'descriptor' => $descriptor, 'validation' => $validation];
}

if ($rebuildFrom !== null) {
    if ($output === null || $output === '' || !is_dir($rebuildFrom) || file_exists($output)) {
        throw new InvalidArgumentException('--rebuild-from needs an existing snapshot directory and a new --output directory');
    }
    createDirectory($output);
    $baseFiles = fileMap($rebuildFrom, payloadFiles($rebuildFrom, false));
    $result = buildFromSnapshot($rebuildFrom, $output, 'sand-iam-rebuild.zip', $baseFiles);
    assertGeneratedDescriptorParity([$rebuildFrom, $output . '/stage/package']);
    echo canonicalJson(['kind' => 'candidate-rebuild', 'archive_sha256' => $result['archive_sha256'], 'entries' => count($result['entry_files']), 'descriptor_sha256' => $result['descriptor']['descriptor_sha256'], 'payload_sha256' => $result['descriptor']['digest']]) . PHP_EOL;
    exit(0);
}

$sourceRevision = $releaseUnsigned ? assertUnsignedReleasePrerequisites($workspace, $source) : null;
if ($releaseUnsigned) assertExternalArtifactRoot($artifactRoot, $source);

createDirectory($artifactRoot);
$version = '0.7.0';
$next = 10;
foreach (glob($artifactRoot . '/sand-iam-' . $version . '-v*-*') ?: [] as $existing) {
    if (preg_match('/-v(\d+)-/', basename($existing), $match) === 1) {
        $next = max($next, ((int) $match[1]) + 1);
    }
}
$artifactName = 'sand-iam-' . $version . '-v' . $next . '-' . gmdate('Ymd\\THis\\Z');
$artifact = $artifactRoot . '/' . $artifactName;
if (file_exists($artifact)) {
    throw new RuntimeException('Refusing to overwrite artifact: ' . $artifact);
}
createDirectory($artifact);
writeDescriptor($source);
$snapshot = $artifact . '/snapshot/package';
createDirectory($snapshot);
$sourceSnapshot = snapshotSource($source, $snapshot);
$baseFiles = fileMap($snapshot, payloadFiles($snapshot, false));
$snapshotRecord = [
    'schema' => 'sand-iam.source-snapshot/v1',
    'kind' => 'immutable-local-snapshot',
    'source_root' => $source,
    'file_count' => count($sourceSnapshot['files']),
    'source_snapshot_sha256' => $sourceSnapshot['digest'],
    'generated_descriptor_source_files' => array_values(array_filter(array_keys($sourceSnapshot['files']), 'generatedDescriptor')),
    'files' => $sourceSnapshot['files'],
];
file_put_contents($artifact . '/source-snapshot.json', json_encode($snapshotRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
$archiveSuffix = $releaseUnsigned ? '-release-unsigned.zip' : '-candidate-dirty-not-release.zip';
$result = buildFromSnapshot($snapshot, $artifact, $artifactName . $archiveSuffix, $baseFiles);
assertGeneratedDescriptorParity([$source, $snapshot, $artifact . '/stage/package']);

$repeat = $artifact . '/reproducibility/rebuild';
createDirectory($repeat);
$repeatResult = buildFromSnapshot($snapshot, $repeat, 'sand-iam-rebuild.zip', $baseFiles);
assertGeneratedDescriptorParity([$source, $snapshot, $artifact . '/stage/package', $repeat . '/stage/package']);
$repeatOk = $repeatResult['archive_sha256'] === $result['archive_sha256']
    && $repeatResult['entry_files'] === $result['entry_files']
    && $repeatResult['descriptor'] === $result['descriptor'];
if (!$repeatOk) {
    throw new RuntimeException('Repeat build from the same immutable snapshot was not identical');
}

$manifest = [
    'schema' => 'sand-iam.artifact-manifest/v6',
    'kind' => $releaseUnsigned ? 'release-candidate-unsigned' : 'candidate-review-only',
    'release_state' => $releaseUnsigned ? 'release/unsigned' : 'candidate/dirty-not-release',
    'package' => ['app' => 'sand-iam', 'version' => $version, 'archive' => basename($result['archive']), 'sha256' => $result['archive_sha256'], 'bytes' => $result['archive_bytes'], 'entry_count' => count($result['entry_files'])],
    'source_snapshot' => ['file_count' => count($sourceSnapshot['files']), 'sha256' => $sourceSnapshot['digest'], 'path' => 'snapshot/package'],
    'archive_authority_parity' => ['missing' => [], 'unexpected' => [], 'mismatch_non_generated' => [], 'generated_descriptors' => ['recovery/failed-upgrade.v2.json', 'plugin/sand-iam/recovery/failed-upgrade.v2.json'], 'generated_descriptors_source_snapshot_stage_identical' => true, 'passed' => true],
    'candidate_recovery_payload' => ['algorithm' => 'sandpackage-normalized-package-manifest/v1', 'file_count' => count($result['descriptor']['files']), 'digest' => $result['descriptor']['digest'], 'descriptor_sha256' => $result['descriptor']['descriptor_sha256'], 'profile_schema' => 'sandpackage.failed-upgrade-recovery-profile/v2', 'profile_state' => 'prefix_033_034', 'update_sql_sha256' => $result['descriptor']['update_sha256']],
    'reproducibility' => ['same_snapshot_archive_sha256' => $repeatResult['archive_sha256'], 'bit_identical_zip' => $repeatOk, 'entry_list_identical' => true, 'descriptor_identical' => true],
    'files' => $result['entry_files'],
];
if ($sourceRevision !== null) {
    $manifest['source_revision'] = [
        'vcs' => 'git', 'commit' => $sourceRevision['commit'], 'tree' => $sourceRevision['tree'],
        'subtree' => 'sand-iam/', 'clean' => true,
    ];
}
file_put_contents($artifact . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
file_put_contents($artifact . '/SHA256SUMS', $result['archive_sha256'] . '  ' . basename($result['archive']) . PHP_EOL);
$command = 'php sand-iam/tools/build-review-candidate.php --rebuild-from=' . $artifact . '/snapshot/package --output=' . $artifact . '/reproducibility/manual-rebuild';
file_put_contents($artifact . '/REBUILD_COMMAND.txt', $command . PHP_EOL);
$validationReport = "# Candidate build validation\n\n"
    . "- Immutable source snapshot: PASS (" . count($sourceSnapshot['files']) . " files).\n"
    . "- Descriptor-excluded canonical payload digest: PASS (" . count($result['descriptor']['files']) . " files).\n"
    . "- Root/plugin recovery descriptor byte identity and inline v2 profile/update bindings: PASS.\n"
    . "- ZIP path, content, CRC/reader, and staged-entry parity: PASS (" . count($result['entry_files']) . " entries).\n"
    . "- Source, immutable snapshot, staged payload, and ZIP payload are byte-identical, including both generated recovery descriptors: PASS.\n"
    . "- Repeat build from the identical snapshot: PASS (bit-identical ZIP SHA-256).\n\n"
    . ($releaseUnsigned ? "- Clean committed SandIAM source, approved LICENSE, current SBOM, release hygiene, and package integrity prerequisites: PASS.\n\n" : "\n")
    . "This report is package construction evidence only. Lifecycle, database, host, browser, business-loop, deployment, and signed release-provenance acceptance are not run by this builder.\n";
file_put_contents($artifact . '/validation-report.md', $validationReport);
$state = $releaseUnsigned ? 'release/unsigned' : 'candidate/dirty-not-release';
$title = $releaseUnsigned ? 'unsigned release candidate' : 'local review candidate';
$provenance = "# SandIAM {$version} v{$next} {$title}\n\n"
    . "State: `{$state}`. This artifact was built from the immutable local snapshot at `snapshot/package`; it was not registered, uploaded, synchronized, installed, signed, or deployed.\n\n"
    . ($sourceRevision === null ? '' : "- Clean source commit: `{$sourceRevision['commit']}`.\n- Committed SandIAM tree: `{$sourceRevision['tree']}`.\n")
    . "- Source snapshot: " . count($sourceSnapshot['files']) . " files; SHA-256 `{$sourceSnapshot['digest']}`.\n"
    . "- ZIP: " . count($result['entry_files']) . " entries; SHA-256 `{$result['archive_sha256']}`.\n"
    . "- Archive-authority parity: 0 missing, 0 unexpected, 0 non-generated mismatches; source, snapshot, stage, and ZIP include byte-identical generated recovery descriptors.\n"
    . "- Descriptor-excluded payload: " . count($result['descriptor']['files']) . " files; SHA-256 `{$result['descriptor']['digest']}`. Root/plugin descriptors are byte-identical, SHA-256 `{$result['descriptor']['descriptor_sha256']}`, and bind root `update.sql` SHA-256 `{$result['descriptor']['update_sha256']}`.\n"
    . "- Rebuild proof: a second build from the exact same snapshot produced the same ZIP SHA-256 and entry list.\n\n"
    . "v7 and v9 remain historical evidence only and are obsolete for current-source review; this v{$next} artifact does not promote or repair them.\n";
file_put_contents($artifact . '/package-provenance.md', $provenance);
echo canonicalJson(['artifact' => $artifact, 'archive_sha256' => $result['archive_sha256'], 'entries' => count($result['entry_files']), 'source_snapshot_sha256' => $sourceSnapshot['digest'], 'descriptor_sha256' => $result['descriptor']['descriptor_sha256'], 'payload_sha256' => $result['descriptor']['digest'], 'repeat_bit_identical' => $repeatOk]) . PHP_EOL;
