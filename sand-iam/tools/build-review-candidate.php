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
 *   php sand-iam/tools/build-review-candidate.php --release-unsigned --source-commit=<commit>
 *   php sand-iam/tools/build-review-candidate.php --verify-git-stage=/absolute/stage --source-commit=<commit>
 *   php sand-iam/tools/build-review-candidate.php --artifact-root=/absolute/path
 *   php sand-iam/tools/build-review-candidate.php --rebuild-from=/absolute/artifact/snapshot/package --output=/absolute/rebuild
 */

if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('PHP zip extension is required');
}

require_once __DIR__ . '/package-payload-policy.php';

const SAND_IAM_REVIEW_EPOCH = 946684800; // 2000-01-01T00:00:00Z; valid ZIP/DOS time.

$workspace = dirname(__DIR__, 2);
$source = $workspace . '/sand-iam';
$arguments = array_slice($argv, 1);
$artifactRoot = $workspace . '/.artifacts';
$rebuildFrom = null;
$output = null;
$releaseUnsigned = false;
$sourceCommit = null;
$verifyGitStage = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--artifact-root=')) {
        $artifactRoot = substr($argument, strlen('--artifact-root='));
    } elseif (str_starts_with($argument, '--rebuild-from=')) {
        $rebuildFrom = substr($argument, strlen('--rebuild-from='));
    } elseif (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } elseif ($argument === '--release-unsigned') {
        $releaseUnsigned = true;
    } elseif (str_starts_with($argument, '--source-commit=')) {
        $sourceCommit = substr($argument, strlen('--source-commit='));
    } elseif (str_starts_with($argument, '--verify-git-stage=')) {
        $verifyGitStage = substr($argument, strlen('--verify-git-stage='));
    } else {
        throw new InvalidArgumentException('Unsupported argument: ' . $argument);
    }
}

if ($releaseUnsigned && ($rebuildFrom !== null || $output !== null)) {
    throw new InvalidArgumentException('--release-unsigned cannot be combined with rebuild arguments');
}
if ($sourceCommit !== null && !$releaseUnsigned && $verifyGitStage === null) {
    throw new InvalidArgumentException('--source-commit is only valid with --release-unsigned or --verify-git-stage');
}
if ($verifyGitStage !== null && ($releaseUnsigned || $rebuildFrom !== null || $output !== null || $sourceCommit === null || $sourceCommit === '')) {
    throw new InvalidArgumentException('--verify-git-stage requires only --source-commit=<commit>');
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
    $revision = checkedCommand(['git', '-C', $workspace, 'rev-parse', 'HEAD'], 'cannot resolve SandIAM source revision');
    if (preg_match('/^[0-9a-f]{40,64}$/', $revision) !== 1) {
        throw new RuntimeException('resolved SandIAM source revision is invalid');
    }
    $tree = checkedCommand(['git', '-C', $workspace, 'rev-parse', 'HEAD:sand-iam'], 'cannot resolve committed SandIAM tree');
    if (preg_match('/^[0-9a-f]{40,64}$/', $tree) !== 1) {
        throw new RuntimeException('resolved SandIAM source tree is invalid');
    }
    // Parse the committed tree before policy tools traverse the worktree. This
    // makes malformed or unsupported Git names fail at the authoritative
    // source boundary rather than being hidden by filesystem enumeration.
    gitBlobMap($workspace, $revision);
    checkedCommand([PHP_BINARY, $source . '/tools/generate-sbom.php', '--check'], 'unsigned release candidate SBOM gate failed');
    checkedCommand([PHP_BINARY, $source . '/tools/check-release-payload.php'], 'unsigned release candidate payload hygiene gate failed');
    checkedCommand([PHP_BINARY, $source . '/tools/check-package-integrity.php'], 'unsigned release candidate package integrity gate failed');
    return ['commit' => $revision, 'tree' => $tree];
}

/** @return array{commit:string,tree:string} */
function resolveSourceRevision(string $workspace, ?string $requestedCommit, array $headRevision): array
{
    $commit = $requestedCommit === null || $requestedCommit === '' ? $headRevision['commit'] : $requestedCommit;
    if (preg_match('/^[0-9a-f]{40,64}$/', $commit) !== 1) {
        throw new InvalidArgumentException('--source-commit must be a full Git object id');
    }
    $resolved = checkedCommand(['git', '-C', $workspace, 'rev-parse', $commit . '^{commit}'], 'cannot resolve requested source commit');
    if (!hash_equals($headRevision['commit'], $resolved)) {
        throw new RuntimeException('unsigned release candidate only accepts the clean current HEAD commit');
    }
    $tree = checkedCommand(['git', '-C', $workspace, 'rev-parse', $resolved . ':sand-iam'], 'cannot resolve requested SandIAM tree');
    return ['commit' => $resolved, 'tree' => $tree];
}

function materializeGitSubtree(string $workspace, string $commit, string $destination): string
{
    // Do not use `git archive` here.  Its export-ignore attributes can remove
    // an otherwise eligible controller or runtime file before the candidate
    // checker sees it.  Materialize each eligible Git blob directly instead.
    materializeGitBlobMap($workspace, gitEligibleBlobMap($workspace, $commit), $destination);
    $source = $destination . '/sand-iam';
    if (!is_dir($source) || is_link($source)) {
        throw new RuntimeException('Git source subtree was not materialized as a regular directory');
    }
    return $source;
}

/** @return array<string,string> */
function gitBlobMap(string $workspace, string $commit): array
{
    $process = proc_open(
        ['git', '-C', $workspace, 'ls-tree', '-r', '-z', '--full-tree', $commit, '--', 'sand-iam'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) throw new RuntimeException('cannot enumerate Git tree');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($output)) {
        throw new RuntimeException('cannot enumerate Git tree' . ($stderr === '' ? '' : ': ' . trim($stderr)));
    }
    if ($output === '' || !str_ends_with($output, "\0")) {
        throw new RuntimeException('malformed Git tree listing');
    }
    $map = [];
    foreach (explode("\0", substr($output, 0, -1)) as $entry) {
        $separator = strpos($entry, "\t");
        if ($separator === false) throw new RuntimeException('malformed Git tree entry: missing tab separator');
        $metadata = substr($entry, 0, $separator);
        $path = substr($entry, $separator + 1);
        if (preg_match('/^([0-7]{6}) ([a-z]+) ([0-9a-f]{40,64})$/', $metadata, $match) !== 1) {
            throw new RuntimeException('malformed Git tree entry metadata');
        }
        if (!in_array($match[1], ['100644', '100755'], true)) {
            throw new RuntimeException('unsupported Git tree mode: ' . $match[1]);
        }
        if ($match[2] !== 'blob') {
            throw new RuntimeException('unsupported Git tree type: ' . $match[2]);
        }
        if (!str_starts_with($path, 'sand-iam/') || !sandIamPayloadPathSupported($path)) {
            throw new RuntimeException('unsupported Git tree path characters');
        }
        if (isset($map[$path])) {
            throw new RuntimeException('malformed Git tree listing: duplicate path');
        }
        $map[$path] = $match[3];
    }
    if ($map === []) throw new RuntimeException('Git source tree has no SandIAM blobs');
    return $map;
}

/** @return array<string,string> Git path => blob object id for the complete eligible package set. */
function gitEligibleBlobMap(string $workspace, string $commit): array
{
    $eligible = [];
    foreach (gitBlobMap($workspace, $commit) as $path => $blob) {
        $relative = substr($path, strlen('sand-iam/'));
        $underPayloadRoot = false;
        foreach (sandIamPayloadRoots() as $root) {
            if ($relative === $root || str_starts_with($relative, $root . '/')) {
                $underPayloadRoot = true;
                break;
            }
        }
        if ($underPayloadRoot && !sandIamPayloadExcluded($relative)) {
            $eligible[$path] = $blob;
        }
    }
    ksort($eligible, SORT_STRING);
    if ($eligible === []) {
        throw new RuntimeException('Git source tree has no eligible SandIAM payload blobs');
    }
    return $eligible;
}

/** @param array<string,string> $blobs Git path => blob object id */
function materializeGitBlobMap(string $workspace, array $blobs, string $destination): void
{
    foreach ($blobs as $path => $blob) {
        $relative = substr($path, strlen('sand-iam/'));
        $target = $destination . '/sand-iam/' . $relative;
        if (!str_starts_with($path, 'sand-iam/') || preg_match('/^[0-9a-f]{40,64}$/', $blob) !== 1
            || file_exists($target) || is_link($target)) {
            throw new RuntimeException('cannot materialize an invalid or existing Git blob destination');
        }
        createDirectory(dirname($target));
    }
    $process = proc_open(
        ['git', '-C', $workspace, 'cat-file', '--batch'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start Git blob materialization');
    }
    try {
        foreach ($blobs as $path => $blob) {
            if (fwrite($pipes[0], $blob . "\n") === false || !fflush($pipes[0])) {
                throw new RuntimeException('cannot request Git blob');
            }
            $header = fgets($pipes[1]);
            if (!is_string($header) || preg_match('/^([0-9a-f]{40,64}) blob (\d+)$/', rtrim($header, "\n"), $match) !== 1
                || !hash_equals($blob, $match[1])) {
                throw new RuntimeException('cannot read requested Git blob');
            }
            $target = $destination . '/sand-iam/' . substr($path, strlen('sand-iam/'));
            $handle = fopen($target, 'wb');
            if (!is_resource($handle)) throw new RuntimeException('cannot create Git blob destination');
            try {
                $remaining = (int) $match[2];
                while ($remaining > 0) {
                    $chunk = fread($pipes[1], min(8192, $remaining));
                    if (!is_string($chunk) || $chunk === '') throw new RuntimeException('cannot read Git blob content');
                    if (fwrite($handle, $chunk) !== strlen($chunk)) throw new RuntimeException('cannot write Git blob content');
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($handle);
            }
            if (fread($pipes[1], 1) !== "\n" || !chmod($target, 0644) || !touch($target, SAND_IAM_REVIEW_EPOCH)) {
                throw new RuntimeException('cannot finalize Git blob destination');
            }
        }
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) throw new RuntimeException('cannot materialize Git blobs' . ($stderr === '' ? '' : ': ' . trim($stderr)));
    } catch (Throwable $exception) {
        foreach ([0, 1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) fclose($pipes[$index]);
        }
        proc_terminate($process);
        proc_close($process);
        throw $exception;
    }
}

function gitObjectHash(string $path, string $algorithm): string
{
    $bytes = filesize($path);
    if (!is_int($bytes)) throw new RuntimeException('cannot size source stage file');
    $hash = hash_init($algorithm);
    hash_update($hash, 'blob ' . $bytes . "\0");
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) throw new RuntimeException('cannot read source stage file');
    try {
        hash_update_stream($hash, $handle);
    } finally {
        fclose($handle);
    }
    return hash_final($hash);
}

function assertStageMatchesGitBlobs(string $workspace, string $commit, string $stage): void
{
    $algorithm = checkedCommand(['git', '-C', $workspace, 'rev-parse', '--show-object-format'], 'cannot resolve Git object format');
    if (!in_array($algorithm, hash_algos(), true)) throw new RuntimeException('unsupported Git object format');
    $blobs = gitEligibleBlobMap($workspace, $commit);
    $expected = array_map(static fn (string $path): string => substr($path, strlen('sand-iam/')), array_keys($blobs));
    $actual = payloadFiles($stage, true);
    $missing = array_values(array_diff($expected, $actual));
    $unexpected = array_values(array_diff($actual, $expected));
    if ($missing !== [] || $unexpected !== []) {
        throw new RuntimeException(
            'Git blob/source stage file-set mismatch: missing=' . implode(',', $missing)
            . '; unexpected=' . implode(',', $unexpected),
        );
    }
    foreach ($expected as $relative) {
        $path = 'sand-iam/' . $relative;
        if (!isset($blobs[$path]) || !hash_equals($blobs[$path], gitObjectHash($stage . '/' . $relative, $algorithm))) {
            throw new RuntimeException('Git blob/source stage mismatch: ' . $relative);
        }
    }
}

/** @return array<string,string> */
function releaseBuildContractPayloadMap(string $stage, string $directory): array
{
    $absolute = $stage . '/' . $directory;
    if (!is_dir($absolute) || is_link($absolute)) {
        throw new RuntimeException('release build contract stage payload directory is invalid: ' . $directory);
    }
    $map = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($absolute) + 1);
        $hash = hash_file('sha256', $file->getPathname());
        if ($relative === '' || !is_string($hash) || isset($map[$relative])) {
            throw new RuntimeException('release build contract stage payload map is invalid: ' . $directory);
        }
        $map[$relative] = $hash;
    }
    ksort($map, SORT_STRING);
    return $map;
}

function assertReleaseBuildContractMatchesGitStage(string $stage): void
{
    $contractPath = $stage . '/release-build-contract.json';
    $contractSource = file_get_contents($contractPath);
    if (!is_string($contractSource)) {
        throw new RuntimeException('release build contract is missing from Git-blob stage');
    }
    try {
        $contract = json_decode($contractSource, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('release build contract is malformed in Git-blob stage', 0, $exception);
    }
    if (!is_array($contract)
        || ($contract['schema'] ?? null) !== 'sand-iam.release-build-contract/v1'
        || ($contract['kind'] ?? null) !== 'reviewed-runtime-payload-inputs'
        || ($contract['source']['formal_builder'] ?? null) !== 'git-blob-only'
        || ($contract['source']['repeat_build'] ?? null) !== 'independent-git-stage') {
        throw new RuntimeException('release build contract has an invalid Git-blob stage schema');
    }
    $toolchain = $contract['toolchain'] ?? null;
    if (!is_array($toolchain)
        || ($toolchain['php'] ?? null) !== PHP_VERSION
        || ($toolchain['zip_extension'] ?? null) !== phpversion('zip')
        || ($toolchain['libzip'] ?? null) !== (defined('ZipArchive::LIBZIP_VERSION') ? ZipArchive::LIBZIP_VERSION : null)) {
        throw new RuntimeException('release build contract toolchain differs from the Git-blob stage build environment');
    }
    $version = static function (array $command): string {
        $output = checkedCommand($command, 'cannot read release build contract toolchain version');
        return trim($output);
    };
    $composer = $version(['composer', '--version']);
    $node = $version(['node', '--version']);
    $pnpm = $version(['pnpm', '--version']);
    if (preg_match('/Composer version ([0-9]+\.[0-9]+\.[0-9]+)/', $composer, $composerMatch) !== 1
        || ($toolchain['composer'] ?? null) !== $composerMatch[1]
        || ($toolchain['node'] ?? null) !== $node
        || ($toolchain['pnpm'] ?? null) !== $pnpm
        || ($toolchain['typescript'] ?? null) !== '5.9.3') {
        throw new RuntimeException('release build contract toolchain differs from the Git-blob stage build environment');
    }
    $composerContract = $contract['composer'] ?? null;
    $typescriptContract = $contract['typescript'] ?? null;
    if (!is_array($composerContract) || !is_array($typescriptContract)
        || ($composerContract['lock_sha256'] ?? null) !== hash_file('sha256', $stage . '/plugin/sand-iam/composer.lock')
        || ($typescriptContract['lock_sha256'] ?? null) !== hash_file('sha256', $stage . '/sdk/typescript/pnpm-lock.yaml')
        || ($typescriptContract['package_integrity'] ?? null) !== 'sha512-jl1vZzPDinLr9eUt3J/t7V6FgNEw9QjvBPdysz9KfQDD41fQrC2Y4vKQdiaUpFT4bXlb1RHhLpp8wtm6M5TgSw==') {
        throw new RuntimeException('release build contract locks differ from the Git-blob stage');
    }
    $generated = $contract['generated_payloads'] ?? null;
    $directories = ['plugin/sand-iam/vendor', 'sdk/typescript/dist'];
    if (!is_array($generated) || array_keys($generated) !== $directories) {
        throw new RuntimeException('release build contract generated payload declarations are invalid');
    }
    foreach ($directories as $directory) {
        $expected = $generated[$directory] ?? null;
        if (!is_array($expected)
            || array_keys($expected) !== ['file_count', 'tree_sha256']
            || !is_int($expected['file_count']) || $expected['file_count'] < 1
            || !is_string($expected['tree_sha256']) || preg_match('/^[0-9a-f]{64}$/', $expected['tree_sha256']) !== 1) {
            throw new RuntimeException('release build contract generated payload declarations are invalid');
        }
        $map = releaseBuildContractPayloadMap($stage, $directory);
        $actual = hash('sha256', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($expected['file_count'] !== count($map) || !hash_equals($expected['tree_sha256'], $actual)) {
            throw new RuntimeException('release build contract does not match Git-blob stage payload: ' . $directory);
        }
    }
}

if ($verifyGitStage !== null) {
    if (preg_match('/^[0-9a-f]{40,64}$/', $sourceCommit) !== 1) {
        throw new InvalidArgumentException('--source-commit must be a full Git object id');
    }
    $commit = checkedCommand(['git', '-C', $workspace, 'rev-parse', $sourceCommit . '^{commit}'], 'cannot resolve Git stage source commit');
    $stage = realpath($verifyGitStage);
    if (!is_string($stage) || !is_dir($stage) || is_link($stage)) {
        throw new RuntimeException('--verify-git-stage must be an existing regular directory');
    }
    assertStageMatchesGitBlobs($workspace, $commit, $stage);
    assertReleaseBuildContractMatchesGitStage($stage);
    echo canonicalJson(['kind' => 'git-stage-verification', 'commit' => $commit, 'stage_matches_git_blobs' => true]) . PHP_EOL;
    exit(0);
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
function validateArchive(string $stage, string $archive, array $baseSnapshotFiles): array
{
    $errors = [];
    $stageFiles = fileMap($stage, payloadFiles($stage, true));
    if ($stageFiles !== $baseSnapshotFiles) {
        $errors[] = 'immutable snapshot parity mismatch';
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

/** @return array{archive:string,archive_sha256:string,archive_bytes:int,entry_files:array<string,array{sha256:string,bytes:int}>,validation:array{ok:bool,errors:list<string>,files:array<string,array{sha256:string,bytes:int}>}} */
function buildFromSnapshot(string $snapshot, string $destination, string $archiveName, array $baseFiles): array
{
    $stage = $destination . '/stage/package';
    createDirectory($stage);
    materializeStage($snapshot, $stage);
    $archive = $destination . '/' . $archiveName;
    $archiveResult = archiveStage($stage, $archive);
    $validation = validateArchive($stage, $archive, $baseFiles);
    if (!$validation['ok']) {
        throw new RuntimeException('Candidate archive validation failed: ' . implode('; ', $validation['errors']));
    }
    return ['archive' => $archive, 'archive_sha256' => $archiveResult['archive_sha256'], 'archive_bytes' => $archiveResult['bytes'], 'entry_files' => $archiveResult['files'], 'validation' => $validation];
}

if ($rebuildFrom !== null) {
    if ($output === null || $output === '' || !is_dir($rebuildFrom) || file_exists($output)) {
        throw new InvalidArgumentException('--rebuild-from needs an existing snapshot directory and a new --output directory');
    }
    createDirectory($output);
    $baseFiles = fileMap($rebuildFrom, payloadFiles($rebuildFrom, false));
    $result = buildFromSnapshot($rebuildFrom, $output, 'sand-iam-rebuild.zip', $baseFiles);
    echo canonicalJson(['kind' => 'candidate-rebuild', 'archive_sha256' => $result['archive_sha256'], 'entries' => count($result['entry_files'])]) . PHP_EOL;
    exit(0);
}

$sourceRevision = $releaseUnsigned ? resolveSourceRevision($workspace, $sourceCommit, assertUnsignedReleasePrerequisites($workspace, $source)) : null;
if ($releaseUnsigned) assertExternalArtifactRoot($artifactRoot, $source);

createDirectory($artifactRoot);
$releaseInfo = parse_ini_file($source . '/info.ini', false, INI_SCANNER_RAW);
$version = is_array($releaseInfo) ? ($releaseInfo['version'] ?? null) : null;
if (!is_string($version) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) !== 1
    || ($releaseInfo['app'] ?? null) !== 'sand-iam') {
    throw new RuntimeException('SandIAM release metadata is missing or invalid');
}
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
$sourceMaterial = $source;
if ($sourceRevision !== null) {
    $sourceMaterial = materializeGitSubtree($workspace, $sourceRevision['commit'], $artifact . '/source-git/primary');
    assertStageMatchesGitBlobs($workspace, $sourceRevision['commit'], $sourceMaterial);
    assertReleaseBuildContractMatchesGitStage($sourceMaterial);
}
$snapshot = $artifact . '/snapshot/package';
createDirectory($snapshot);
$sourceSnapshot = snapshotSource($sourceMaterial, $snapshot);
$gitBlobParity = $sourceRevision !== null;
if ($sourceRevision !== null) {
    assertStageMatchesGitBlobs($workspace, $sourceRevision['commit'], $snapshot);
}
$baseFiles = fileMap($snapshot, payloadFiles($snapshot, false));
$snapshotRecord = [
    'schema' => 'sand-iam.source-snapshot/v2',
    'kind' => $sourceRevision === null ? 'immutable-local-snapshot' : 'git-blob-materialized-snapshot',
    'source_root' => $sourceRevision === null ? $source : 'git:' . $sourceRevision['commit'] . ':sand-iam/',
    'file_count' => count($sourceSnapshot['files']),
    'source_snapshot_sha256' => $sourceSnapshot['digest'],
    'files' => $sourceSnapshot['files'],
];
file_put_contents($artifact . '/source-snapshot.json', json_encode($snapshotRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
$archiveSuffix = $releaseUnsigned ? '-release-unsigned.zip' : '-candidate-dirty-not-release.zip';
$result = buildFromSnapshot($snapshot, $artifact, $artifactName . $archiveSuffix, $baseFiles);

$repeat = $artifact . '/reproducibility/rebuild';
createDirectory($repeat);
$repeatSnapshot = $snapshot;
if ($sourceRevision !== null) {
    $repeatSource = materializeGitSubtree($workspace, $sourceRevision['commit'], $artifact . '/source-git/repeat');
    assertStageMatchesGitBlobs($workspace, $sourceRevision['commit'], $repeatSource);
    assertReleaseBuildContractMatchesGitStage($repeatSource);
    $repeatSnapshot = $repeat . '/snapshot/package';
    createDirectory($repeatSnapshot);
    $repeatSourceSnapshot = snapshotSource($repeatSource, $repeatSnapshot);
    if ($repeatSourceSnapshot['files'] !== $sourceSnapshot['files']) {
        throw new RuntimeException('Independent Git source stages do not have identical payload maps');
    }
    assertStageMatchesGitBlobs($workspace, $sourceRevision['commit'], $repeatSnapshot);
}
$repeatResult = buildFromSnapshot($repeatSnapshot, $repeat, 'sand-iam-rebuild.zip', $baseFiles);
$repeatOk = $repeatResult['archive_sha256'] === $result['archive_sha256']
    && $repeatResult['entry_files'] === $result['entry_files'];
if (!$repeatOk) {
    throw new RuntimeException('Repeat build from the same immutable snapshot was not identical');
}

$manifest = [
    'schema' => 'sand-iam.artifact-manifest/v8',
    'kind' => $releaseUnsigned ? 'release-candidate-unsigned' : 'candidate-review-only',
    'release_state' => $releaseUnsigned ? 'release/unsigned' : 'candidate/dirty-not-release',
    'package' => ['app' => 'sand-iam', 'version' => $version, 'archive' => basename($result['archive']), 'sha256' => $result['archive_sha256'], 'bytes' => $result['archive_bytes'], 'entry_count' => count($result['entry_files'])],
    'source_snapshot' => ['file_count' => count($sourceSnapshot['files']), 'sha256' => $sourceSnapshot['digest'], 'path' => 'snapshot/package'],
    'archive_authority_parity' => ['missing' => [], 'unexpected' => [], 'mismatch' => [], 'normal_package_recovery_descriptors' => 'excluded', 'passed' => true],
    'reproducibility' => ['same_snapshot_archive_sha256' => $repeatResult['archive_sha256'], 'bit_identical_zip' => $repeatOk, 'entry_list_identical' => true],
    'files' => $result['entry_files'],
];
if ($sourceRevision !== null) {
    $manifest['source_revision'] = [
        'vcs' => 'git', 'commit' => $sourceRevision['commit'], 'tree' => $sourceRevision['tree'],
        'subtree' => 'sand-iam/', 'clean' => true,
    ];
    $contractHash = hash_file('sha256', $snapshot . '/release-build-contract.json');
    if (!is_string($contractHash)) {
        throw new RuntimeException('cannot hash release build contract');
    }
    $manifest['source_provenance'] = [
        'mode' => 'git-blob-only',
        'commit' => $sourceRevision['commit'],
        'tree' => $sourceRevision['tree'],
        'stage_matches_git_blobs' => $gitBlobParity,
        'independent_git_stage_rebuild' => true,
        'build_contract_sha256' => $contractHash,
    ];
}
file_put_contents($artifact . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
file_put_contents($artifact . '/SHA256SUMS', $result['archive_sha256'] . '  ' . basename($result['archive']) . PHP_EOL);
$command = 'php sand-iam/tools/build-review-candidate.php --rebuild-from=' . $artifact . '/snapshot/package --output=' . $artifact . '/reproducibility/manual-rebuild';
file_put_contents($artifact . '/REBUILD_COMMAND.txt', $command . PHP_EOL);
$validationReport = "# Candidate build validation\n\n"
    . "- Immutable source snapshot: PASS (" . count($sourceSnapshot['files']) . " files).\n"
    . "- ZIP path, content, CRC/reader, and staged-entry parity: PASS (" . count($result['entry_files']) . " entries).\n"
    . "- Source, immutable snapshot, staged payload, and ZIP payload are byte-identical; normal-package recovery descriptors are excluded: PASS.\n"
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
    . "- Archive-authority parity: 0 missing, 0 unexpected, 0 mismatches; normal-package recovery descriptors are excluded.\n"
    . "- Rebuild proof: a second build from the exact same snapshot produced the same ZIP SHA-256 and entry list.\n\n"
    . "v7 and v9 remain historical evidence only and are obsolete for current-source review; this v{$next} artifact does not promote or repair them.\n";
file_put_contents($artifact . '/package-provenance.md', $provenance);
echo canonicalJson(['artifact' => $artifact, 'archive_sha256' => $result['archive_sha256'], 'entries' => count($result['entry_files']), 'source_snapshot_sha256' => $sourceSnapshot['digest'], 'repeat_bit_identical' => $repeatOk]) . PHP_EOL;
