<?php

declare(strict_types=1);

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

/** @return array<string,mixed> */
function sandIamReadArtifactManifest(string $path): array
{
    $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)
        || ($manifest['schema'] ?? null) !== 'sand-iam.artifact-manifest/v6'
        || ($manifest['kind'] ?? null) !== 'release-candidate-unsigned'
        || ($manifest['release_state'] ?? null) !== 'release/unsigned') {
        throw new RuntimeException('artifact manifest is not an unsigned release candidate');
    }
    if (($manifest['archive_authority_parity']['passed'] ?? false) !== true
        || ($manifest['reproducibility']['bit_identical_zip'] ?? false) !== true
        || ($manifest['reproducibility']['entry_list_identical'] ?? false) !== true
        || ($manifest['reproducibility']['descriptor_identical'] ?? false) !== true) {
        throw new RuntimeException('artifact manifest has incomplete parity or reproducibility evidence');
    }
    $package = $manifest['package'] ?? null;
    $revision = $manifest['source_revision'] ?? null;
    $recovery = $manifest['candidate_recovery_payload'] ?? null;
    if (!is_array($package)
        || ($package['app'] ?? null) !== 'sand-iam'
        || !is_string($package['version'] ?? null)
        || preg_match('/^\d+\.\d+\.\d+$/', $package['version']) !== 1
        || !is_array($revision)
        || ($revision['vcs'] ?? null) !== 'git'
        || preg_match('/^[0-9a-f]{40,64}$/', (string) ($revision['commit'] ?? '')) !== 1
        || preg_match('/^[0-9a-f]{40,64}$/', (string) ($revision['tree'] ?? '')) !== 1
        || ($revision['subtree'] ?? null) !== 'sand-iam/'
        || ($revision['clean'] ?? null) !== true
        || !is_array($recovery)) {
        throw new RuntimeException('artifact manifest has no valid clean SandIAM source revision or package identity');
    }
    foreach (['sha256' => $manifest['source_snapshot']['sha256'] ?? null, 'payload digest' => $recovery['digest'] ?? null, 'descriptor sha256' => $recovery['descriptor_sha256'] ?? null, 'update sql sha256' => $recovery['update_sql_sha256'] ?? null] as $label => $hash) {
        if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) throw new RuntimeException('artifact manifest has invalid ' . $label);
    }
    if (!is_array($manifest['files'] ?? null) || $manifest['files'] === []) throw new RuntimeException('artifact manifest has no ZIP file map');
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
    foreach (['source', 'reference', 'approved_by', 'approved_at'] as $field) {
        if (!is_string($provenance[$field] ?? null) || trim($provenance[$field]) === '') throw new RuntimeException('provenance.' . $field . ' is required');
    }
    $approvedAt = trim($provenance['approved_at']);
    $parsedApprovedAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $approvedAt, new DateTimeZone('UTC'));
    if (!$parsedApprovedAt instanceof DateTimeImmutable || $parsedApprovedAt->format('Y-m-d\TH:i:s\Z') !== $approvedAt) {
        throw new RuntimeException('provenance.approved_at must be a UTC ISO-8601 second timestamp');
    }
    return [
        'schema' => 'sand-iam.release-bundle-attestation/v1',
        'kind' => 'external-reviewed-release-bundle',
        'package' => ['app' => $package['app'], 'version' => $package['version']],
        'archive' => ['name' => $package['archive'], 'sha256' => $archiveHash, 'bytes' => $archiveBytes, 'entry_count' => $package['entry_count']],
        'artifact_manifest' => ['name' => basename($artifactManifestPath), 'sha256' => $manifestHash],
        'source_revision' => $artifact['source_revision'],
        'source_snapshot_sha256' => $artifact['source_snapshot']['sha256'] ?? null,
        'payload_sha256' => $artifact['candidate_recovery_payload']['digest'] ?? null,
        'descriptor_sha256' => $artifact['candidate_recovery_payload']['descriptor_sha256'] ?? null,
        'update_sql_sha256' => $artifact['candidate_recovery_payload']['update_sql_sha256'] ?? null,
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
    $files = [];
    try {
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !is_string($stat['name'] ?? null)) throw new RuntimeException('cannot inspect ZIP entry');
            $name = $stat['name'];
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $name) === 1) throw new RuntimeException('ZIP contains an unsafe path: ' . $name);
            if (isset($files[$name])) throw new RuntimeException('ZIP contains a duplicate entry: ' . $name);
            $attributes = $zip->getExternalAttributesIndex($index, $opsys, $externalAttributes);
            if ($attributes && (($externalAttributes >> 16) & 0170000) === 0120000) throw new RuntimeException('ZIP contains a symbolic link: ' . $name);
            if (str_ends_with($name, '/')) continue;
            $content = $zip->getFromIndex($index);
            if (!is_string($content)) throw new RuntimeException('cannot read ZIP entry: ' . $name);
            $files[$name] = ['sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
        }
    } finally {
        $zip->close();
    }
    ksort($files, SORT_STRING);
    return ['entry_count' => count($files), 'files' => $files];
}
