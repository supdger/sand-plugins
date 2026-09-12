<?php

declare(strict_types=1);

require_once __DIR__ . '/package-payload-policy.php';

/** @return mixed */
function sandIamCanonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('sandIamCanonicalize', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = sandIamCanonicalize($item);
    return $value;
}

function sandIamCanonicalJson(array $value): string
{
    return json_encode(sandIamCanonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
}

function sandIamStrictBase64(string $encoded, int $bytes, string $label): string
{
    $value = base64_decode(trim($encoded), true);
    if (!is_string($value) || base64_encode($value) !== trim($encoded) || strlen($value) !== $bytes) {
        throw new RuntimeException($label . ' must be strict base64 for exactly ' . $bytes . ' bytes');
    }
    return $value;
}

function sandIamLexicalAbsolutePath(string $path, string $label): string
{
    if ($path === '' || str_contains($path, "\0")) throw new RuntimeException($label . ' path is invalid');
    if (!str_starts_with($path, '/')) {
        $cwd = getcwd();
        if (!is_string($cwd)) throw new RuntimeException('cannot resolve current directory');
        $path = $cwd . '/' . $path;
    }
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            if ($parts === []) throw new RuntimeException($label . ' path escapes the filesystem root');
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return '/' . implode('/', $parts);
}

function sandIamAssertNoSymbolicPathComponents(string $path, string $label): string
{
    $absolute = sandIamLexicalAbsolutePath($path, $label);
    $current = '';
    foreach (array_values(array_filter(explode('/', $absolute), static fn (string $part): bool => $part !== '')) as $part) {
        $current .= '/' . $part;
        $stat = lstat($current);
        if ($stat === false) throw new RuntimeException($label . ' path does not exist');
        if (($stat['mode'] & 0170000) === 0120000) throw new RuntimeException($label . ' path must not contain symbolic links');
    }
    return $absolute;
}

function sandIamAssertRegularPath(string $path, string $label): string
{
    $absolute = sandIamAssertNoSymbolicPathComponents($path, $label);
    if (!is_file($absolute)) throw new RuntimeException($label . ' must be an existing regular file');
    return $absolute;
}

function sandIamAssertDirectoryPath(string $path, string $label): string
{
    $absolute = sandIamAssertNoSymbolicPathComponents($path, $label);
    if (!is_dir($absolute)) throw new RuntimeException($label . ' must be an existing directory');
    return $absolute;
}

function sandIamAssertExternalPath(string $path, string $packageRoot, string $label): string
{
    $absolute = sandIamAssertRegularPath($path, $label);
    $root = realpath($packageRoot);
    if (!is_string($root) || $absolute === $root || str_starts_with($absolute, $root . '/')) {
        throw new RuntimeException($label . ' must remain outside the SandIAM package root');
    }
    return $absolute;
}

/** @return array{dev:int,ino:int,uid:int,mode:int} */
function sandIamRegularFileIdentity(string $path, string $label): array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0100000)) {
        throw new RuntimeException($label . ' must be a regular file');
    }
    return ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'uid' => $stat['uid'], 'mode' => $stat['mode']];
}

/** @return array{dev:int,ino:int,uid:int,mode:int} */
function sandIamDirectoryIdentity(string $path, string $label): array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0040000)) {
        throw new RuntimeException($label . ' must be an existing directory');
    }
    return ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'uid' => $stat['uid'], 'mode' => $stat['mode']];
}

/** @param array{dev:int,ino:int,uid:int,mode:int} $expected */
function sandIamSameFileIdentity(string $path, array $expected): bool
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    return is_array($stat)
        && ($stat['dev'] ?? null) === $expected['dev']
        && ($stat['ino'] ?? null) === $expected['ino']
        && ($stat['uid'] ?? null) === $expected['uid'];
}

function sandIamAssertOutputAbsent(string $path): void
{
    clearstatcache(true, $path);
    if (@lstat($path) !== false) {
        throw new RuntimeException('refusing to replace attestation output');
    }
}

/** @return array{dev:int,ino:int,uid:int,mode:int} */
function sandIamStreamFileIdentity($stream): array
{
    $stat = fstat($stream);
    if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0100000)) {
        throw new RuntimeException('attestation temporary must be a regular file');
    }
    return ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'uid' => $stat['uid'], 'mode' => $stat['mode']];
}

/**
 * Keep publication I/O in one production helper. The optional overrides are
 * intentionally process-local test seams, never command-line configuration.
 *
 * @param array<string,callable> $overrides
 * @return array<string,callable>
 */
function sandIamPublicationIo(array $overrides = []): array
{
    $io = [
        'random' => static fn (int $bytes): string => random_bytes($bytes),
        'open_temp' => static fn (string $path) => @fopen($path, 'x+b'),
        'chmod' => static fn (string $path, int $mode): bool => @chmod($path, $mode),
        'write' => static fn ($stream, string $contents) => fwrite($stream, $contents),
        'flush' => static fn ($stream): bool => fflush($stream),
        'fsync' => static fn ($stream, bool $directory): bool => function_exists('fsync') && fsync($stream),
        'close' => static fn ($stream): bool => fclose($stream),
        'link' => static fn (string $source, string $target): bool => @link($source, $target),
        'unlink' => static fn (string $path): bool => @unlink($path),
        'open_directory' => static fn (string $path) => @fopen($path, 'r'),
    ];
    foreach ($overrides as $name => $operation) {
        if (!array_key_exists($name, $io) || !is_callable($operation)) {
            throw new InvalidArgumentException('invalid attestation publication I/O override');
        }
        $io[$name] = $operation;
    }
    return $io;
}

function sandIamWriteAll($stream, string $contents, callable $write): void
{
    $offset = 0;
    $length = strlen($contents);
    while ($offset < $length) {
        $written = $write($stream, substr($contents, $offset));
        if (!is_int($written) || $written < 1) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        $offset += $written;
    }
}

function sandIamFsync($stream, bool $directory, callable $fsync): void
{
    if (!$fsync($stream, $directory)) {
        throw new RuntimeException('cannot publish signed attestation');
    }
}

/**
 * @param array{dev:int,ino:int,uid:int,mode:int} $parentIdentity
 * @param array{dev:int,ino:int,uid:int,mode:int}|null $publicationIdentity
 * @param array<string,callable> $io
 */
function sandIamCleanupFailedAttestationPublication(string $parent, array $parentIdentity, string $output, ?string $temporary, ?array $publicationIdentity, array $io): bool
{
    try {
        $currentParent = sandIamDirectoryIdentity($parent, 'output directory');
        if ($currentParent !== $parentIdentity) {
            return false;
        }
        $clean = true;
        if (is_array($publicationIdentity)) {
            // Unlink the public name first. Only our dev/inode identity is ever
            // removed, so a racing replacement cannot be deleted by this cleanup.
            foreach ([$output, $temporary] as $path) {
                if (is_string($path) && sandIamSameFileIdentity($path, $publicationIdentity)
                    && !$io['unlink']($path)) {
                    $clean = false;
                }
            }
        }
        $directory = $io['open_directory']($parent);
        if (!is_resource($directory)) {
            return false;
        }
        try {
            if (!$io['fsync']($directory, true)) {
                $clean = false;
            }
        } finally {
            $io['close']($directory);
        }
        return $clean;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Publish a new attestation without ever replacing a pre-existing path. The
 * temporary file is created in the target directory so link(2) is an atomic
 * no-replace publish on the same filesystem.
 */
function sandIamPublishNewAttestation(string $parent, string $output, string $contents, array $ioOverrides = []): void
{
    $parentIdentity = sandIamDirectoryIdentity($parent, 'output directory');
    sandIamAssertOutputAbsent($output);
    $io = sandIamPublicationIo($ioOverrides);
    $temporary = null;
    $temporaryIdentity = null;
    $stream = null;
    try {
        for ($attempt = 0; $attempt < 32; ++$attempt) {
            $random = $io['random'](18);
            if (!is_string($random) || strlen($random) !== 18) {
                throw new RuntimeException('cannot publish signed attestation');
            }
            $candidate = $parent . '/.sand-iam-attestation-' . bin2hex($random) . '.tmp';
            $stream = $io['open_temp']($candidate);
            if (is_resource($stream)) {
                $temporary = $candidate;
                break;
            }
        }
        if (!is_resource($stream ?? null) || !is_string($temporary)) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        // fstat immediately after O_EXCL success: later cleanup can prove it
        // still owns the same inode even if chmod or a directory operation fails.
        $temporaryIdentity = sandIamStreamFileIdentity($stream);
        if ($temporaryIdentity['dev'] !== $parentIdentity['dev']) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if ($temporaryIdentity['uid'] !== $effectiveUid) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        if (!$io['chmod']($temporary, 0600)) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        sandIamWriteAll($stream, $contents, $io['write']);
        if (!$io['flush']($stream)) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        if (!$io['chmod']($temporary, 0644)) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        sandIamFsync($stream, false, $io['fsync']);
        if (!$io['close']($stream)) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        $stream = null;
        if (!sandIamSameFileIdentity($temporary, $temporaryIdentity)
            || sandIamDirectoryIdentity($parent, 'output directory') !== $parentIdentity) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        sandIamAssertOutputAbsent($output);
        if (!$io['link']($temporary, $output)) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        if (!sandIamSameFileIdentity($output, $temporaryIdentity)) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        $directory = $io['open_directory']($parent);
        if (!is_resource($directory)) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        try {
            sandIamFsync($directory, true, $io['fsync']);
        } finally {
            $io['close']($directory);
        }
        if (!sandIamSameFileIdentity($temporary, $temporaryIdentity) || !$io['unlink']($temporary)) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        $temporary = null;
        $directory = $io['open_directory']($parent);
        if (!is_resource($directory)) {
            throw new RuntimeException('cannot publish signed attestation');
        }
        try {
            sandIamFsync($directory, true, $io['fsync']);
        } finally {
            $io['close']($directory);
        }
    } catch (Throwable $exception) {
        if (is_resource($stream ?? null)) {
            $io['close']($stream);
        }
        if (!sandIamCleanupFailedAttestationPublication($parent, $parentIdentity, $output, $temporary, $temporaryIdentity, $io)) {
            throw new RuntimeException('cannot safely clean failed attestation publication', 0, $exception);
        }
        throw new RuntimeException('cannot publish signed attestation', 0, $exception);
    }
}

/** @return array<string,mixed> */
function sandIamReadArtifactManifest(string $path): array
{
    $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)
        || ($manifest['schema'] ?? null) !== 'sand-iam.artifact-manifest/v8'
        || ($manifest['kind'] ?? null) !== 'release-candidate-unsigned'
        || ($manifest['release_state'] ?? null) !== 'release/unsigned') {
        throw new RuntimeException('artifact manifest is not an unsigned release candidate');
    }
    if (($manifest['archive_authority_parity']['passed'] ?? false) !== true
        || ($manifest['reproducibility']['bit_identical_zip'] ?? false) !== true
        || ($manifest['reproducibility']['entry_list_identical'] ?? false) !== true
        || ($manifest['archive_authority_parity']['normal_package_recovery_descriptors'] ?? null) !== 'excluded') {
        throw new RuntimeException('artifact manifest has incomplete parity or reproducibility evidence');
    }
    $package = $manifest['package'] ?? null;
    $revision = $manifest['source_revision'] ?? null;
    if (!is_array($package)
        || ($package['app'] ?? null) !== 'sand-iam'
        || !is_string($package['version'] ?? null)
        || preg_match('/^\d+\.\d+\.\d+$/', $package['version']) !== 1
        || !is_array($revision)
        || ($revision['vcs'] ?? null) !== 'git'
        || preg_match('/^[0-9a-f]{40,64}$/', (string) ($revision['commit'] ?? '')) !== 1
        || preg_match('/^[0-9a-f]{40,64}$/', (string) ($revision['tree'] ?? '')) !== 1
        || ($revision['subtree'] ?? null) !== 'sand-iam/'
        || ($revision['clean'] ?? null) !== true) {
        throw new RuntimeException('artifact manifest has no valid clean SandIAM source revision or package identity');
    }
    foreach (['sha256' => $manifest['source_snapshot']['sha256'] ?? null, 'update sql sha256' => $manifest['files']['update.sql']['sha256'] ?? null] as $label => $hash) {
        if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) throw new RuntimeException('artifact manifest has invalid ' . $label);
    }
    if (!is_array($manifest['files'] ?? null) || $manifest['files'] === []) throw new RuntimeException('artifact manifest has no ZIP file map');
    sandIamAssertNoHistoricalRecoveryDescriptors($manifest['files']);
    $sourceProvenance = $manifest['source_provenance'] ?? null;
    $contractFile = $manifest['files']['release-build-contract.json'] ?? null;
    if (!is_array($contractFile)
        || !is_string($contractFile['sha256'] ?? null)
        || preg_match('/^[0-9a-f]{64}$/', $contractFile['sha256']) !== 1
        || !is_int($contractFile['bytes'] ?? null) || $contractFile['bytes'] < 1) {
        throw new RuntimeException('artifact manifest has no packaged release build contract');
    }
    if (!is_array($sourceProvenance)
        || ($sourceProvenance['mode'] ?? null) !== 'git-blob-only'
        || ($sourceProvenance['commit'] ?? null) !== $revision['commit']
        || ($sourceProvenance['tree'] ?? null) !== $revision['tree']
        || ($sourceProvenance['stage_matches_git_blobs'] ?? null) !== true
        || ($sourceProvenance['independent_git_stage_rebuild'] ?? null) !== true
        || preg_match('/^[0-9a-f]{64}$/', (string) ($sourceProvenance['build_contract_sha256'] ?? '')) !== 1
        || !hash_equals($contractFile['sha256'], $sourceProvenance['build_contract_sha256'])) {
        throw new RuntimeException('artifact manifest has no verified Git-blob source provenance');
    }
    return $manifest;
}

/** @return array<string,mixed> */
function sandIamUnsignedBundleAttestation(array $artifact, string $artifactManifestPath, string $archivePath, array $provenance): array
{
    $archiveHash = hash_file('sha256', $archivePath);
    $archiveBytes = filesize($archivePath);
    $manifestHash = hash_file('sha256', $artifactManifestPath);
    if (!is_string($archiveHash) || !is_int($archiveBytes) || !is_string($manifestHash)) throw new RuntimeException('cannot hash release artifact inputs');
    $package = $artifact['package'] ?? null;
    if (!is_array($package)
        || ($package['archive'] ?? null) !== basename($archivePath)
        || ($package['sha256'] ?? null) !== $archiveHash
        || ($package['bytes'] ?? null) !== $archiveBytes
        || !is_int($package['entry_count'] ?? null)) {
        throw new RuntimeException('archive does not match the artifact manifest');
    }
    $contractFile = $artifact['files']['release-build-contract.json'] ?? null;
    $archiveFiles = sandIamInspectReleaseZip($archivePath)['files'];
    if (!is_array($contractFile) || !isset($archiveFiles['release-build-contract.json'])
        || $archiveFiles['release-build-contract.json'] !== $contractFile) {
        throw new RuntimeException('release archive has no verified packaged build contract');
    }
    foreach (['source', 'reference', 'approved_by', 'approved_at'] as $field) {
        if (!is_string($provenance[$field] ?? null) || trim($provenance[$field]) === '') throw new RuntimeException('provenance.' . $field . ' is required');
    }
    $approvedAt = trim($provenance['approved_at']);
    $parsedApprovedAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $approvedAt, new DateTimeZone('UTC'));
    if (!$parsedApprovedAt instanceof DateTimeImmutable || $parsedApprovedAt->format('Y-m-d\TH:i:s\Z') !== $approvedAt) {
        throw new RuntimeException('provenance.approved_at must be a UTC ISO-8601 second timestamp');
    }
    return [
        'schema' => 'sand-iam.release-bundle-attestation/v2',
        'kind' => 'external-reviewed-release-bundle',
        'package' => ['app' => $package['app'], 'version' => $package['version']],
        'archive' => ['name' => $package['archive'], 'sha256' => $archiveHash, 'bytes' => $archiveBytes, 'entry_count' => $package['entry_count']],
        'artifact_manifest' => ['name' => basename($artifactManifestPath), 'sha256' => $manifestHash],
        'source_revision' => $artifact['source_revision'],
        'source_snapshot_sha256' => $artifact['source_snapshot']['sha256'] ?? null,
        'source_provenance' => $artifact['source_provenance'],
        'normal_package_recovery_descriptors' => 'excluded',
        'update_sql_sha256' => $artifact['files']['update.sql']['sha256'] ?? null,
        'provenance' => [
            'source' => trim($provenance['source']),
            'reference' => trim($provenance['reference']),
            'approved_by' => trim($provenance['approved_by']),
            'approved_at' => trim($provenance['approved_at']),
        ],
    ];
}

/** @return array{entry_count:int,files:array<string,array{sha256:string,bytes:int}>} */
function sandIamInspectReleaseZip(string $archivePath): array
{
    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) throw new RuntimeException('cannot open release ZIP');
    $entries = [];
    $files = [];
    try {
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !is_string($stat['name'] ?? null)) throw new RuntimeException('cannot inspect ZIP entry');
            $name = $stat['name'];
            $directory = str_ends_with($name, '/');
            $logicalName = $directory ? substr($name, 0, -1) : $name;
            try {
                $canonicalName = sandIamCanonicalPayloadPath($logicalName);
            } catch (RuntimeException) {
                throw new RuntimeException('ZIP contains an unsafe path');
            }
            if (isset($entries[$canonicalName])) throw new RuntimeException('ZIP contains a duplicate canonical path');
            $attributes = $zip->getExternalAttributesIndex($index, $opsys, $externalAttributes);
            if ($attributes && (($externalAttributes >> 16) & 0170000) === 0120000) throw new RuntimeException('ZIP contains a symbolic link');
            $entries[$canonicalName] = ['directory' => $directory];
            if ($directory) continue;
            $content = $zip->getFromIndex($index);
            if (!is_string($content)) throw new RuntimeException('cannot read ZIP entry');
            $files[$canonicalName] = ['sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
        }
    } finally {
        $zip->close();
    }
    foreach ($entries as $path => $_entry) {
        $segments = explode('/', $path);
        array_pop($segments);
        while ($segments !== []) {
            $prefix = implode('/', $segments);
            if (($entries[$prefix]['directory'] ?? true) !== true) {
                throw new RuntimeException('ZIP contains a file/directory path conflict');
            }
            array_pop($segments);
        }
    }
    sandIamAssertNoHistoricalRecoveryDescriptors($entries);
    ksort($files, SORT_STRING);
    return ['entry_count' => count($files), 'files' => $files];
}

/** @param array<string,mixed> $entries */
function sandIamAssertNoHistoricalRecoveryDescriptors(array $entries): void
{
    foreach (sandIamGeneratedDescriptorPaths() as $path) {
        if (array_key_exists($path, $entries)) {
            throw new RuntimeException('release artifact contains a historical recovery descriptor');
        }
    }
}
