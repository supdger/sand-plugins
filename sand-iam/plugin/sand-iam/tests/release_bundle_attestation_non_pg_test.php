<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
$signer = $root . '/tools/sign-release-bundle.php';
$verifier = $root . '/tools/verify-release-bundle.php';
require_once $root . '/tools/release-bundle-attestation.php';

/** @return array{0:int,1:string} */
$run = static function (string $tool, array $arguments): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool);
    foreach ($arguments as $name => $value) {
        $command .= ' --' . $name . '=' . escapeshellarg($value);
    }
    $output = [];
    exec($command . ' 2>&1', $output, $status);
    return [$status, implode("\n", $output)];
};

/** @param array<string,mixed> $value */
$writeJson = static function (string $path, array $value): bool {
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    return file_put_contents($path, $encoded) === strlen($encoded);
};

$removeTree = null;
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    $entries = scandir($path);
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') $removeTree($path . '/' . $entry);
        }
    }
    rmdir($path);
};

$seed = tempnam('/private/tmp', 'sand-iam-bundle-');
if ($seed === false || !unlink($seed) || !mkdir($seed, 0700)) {
    fwrite(STDERR, "cannot create isolated release bundle fixture\n");
    exit(1);
}

$passed = false;
try {
    $archive = $seed . '/sand-iam-0.7.0-release-unsigned.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('cannot create fixture ZIP');
    foreach ([
        'LICENSE' => "Apache License fixture\n",
        'SBOM.cdx.json' => "{\"bomFormat\":\"CycloneDX\"}\n",
        'THIRD_PARTY_NOTICES.md' => "# Notices\n",
        'SECURITY.md' => "# Security\n",
        'CONTRIBUTING.md' => "# Contributing\n",
        'README.md' => "# SandIAM\n",
    ] as $name => $content) {
        if (!$zip->addFromString($name, $content)) throw new RuntimeException('cannot add fixture ZIP entry');
    }
    if (!$zip->close()) throw new RuntimeException('cannot close fixture ZIP');

    $inspection = sandIamInspectReleaseZip($archive);
    $archiveHash = hash_file('sha256', $archive);
    $archiveBytes = filesize($archive);
    if (!is_string($archiveHash) || !is_int($archiveBytes)) throw new RuntimeException('cannot hash fixture ZIP');
    $manifest = [
        'schema' => 'sand-iam.artifact-manifest/v6',
        'kind' => 'release-candidate-unsigned',
        'release_state' => 'release/unsigned',
        'archive_authority_parity' => ['passed' => true],
        'reproducibility' => [
            'bit_identical_zip' => true,
            'entry_list_identical' => true,
            'descriptor_identical' => true,
        ],
        'package' => [
            'app' => 'sand-iam',
            'version' => '0.7.0',
            'archive' => basename($archive),
            'sha256' => $archiveHash,
            'bytes' => $archiveBytes,
            'entry_count' => $inspection['entry_count'],
        ],
        'source_snapshot' => ['sha256' => str_repeat('1', 64)],
        'source_revision' => [
            'vcs' => 'git', 'commit' => str_repeat('a', 40), 'tree' => str_repeat('b', 40),
            'subtree' => 'sand-iam/', 'clean' => true,
        ],
        'candidate_recovery_payload' => [
            'digest' => str_repeat('2', 64),
            'descriptor_sha256' => str_repeat('3', 64),
            'update_sql_sha256' => str_repeat('4', 64),
        ],
        'files' => $inspection['files'],
    ];
    $manifestPath = $seed . '/manifest.json';
    if (!$writeJson($manifestPath, $manifest)) throw new RuntimeException('cannot write fixture manifest');

    $keypair = sodium_crypto_sign_keypair();
    $privateKey = $seed . '/release.ed25519.key';
    $publicKey = $seed . '/release.ed25519.pub';
    if (file_put_contents($privateKey, base64_encode(sodium_crypto_sign_secretkey($keypair)) . "\n") === false
        || !chmod($privateKey, 0600)
        || file_put_contents($publicKey, base64_encode(sodium_crypto_sign_publickey($keypair)) . "\n") === false
        || !chmod($publicKey, 0644)) {
        throw new RuntimeException('cannot write fixture keys');
    }

    $attestation = $seed . '/sand-iam.release-attestation.json';
    $signArguments = [
        'artifact-manifest' => $manifestPath,
        'archive' => $archive,
        'private-key' => $privateKey,
        'output' => $attestation,
        'source' => 'isolated-test',
        'reference' => 'release-bundle-attestation-test',
        'approved-by' => 'independent-test-reviewer',
        'approved-at' => '2026-09-12T00:00:00Z',
    ];
    [$signStatus, $signOutput] = $run($signer, $signArguments);
    [$verifyStatus, $verifyOutput] = $run($verifier, [
        'artifact-manifest' => $manifestPath,
        'archive' => $archive,
        'attestation' => $attestation,
        'public-key' => $publicKey,
    ]);

    $keyDirectoryLink = $seed . '/linked-key-directory';
    if (!symlink($seed, $keyDirectoryLink)) throw new RuntimeException('cannot create fixture key-directory link');
    [$linkedKeyStatus, $linkedKeyOutput] = $run($verifier, [
        'artifact-manifest' => $manifestPath,
        'archive' => $archive,
        'attestation' => $attestation,
        'public-key' => $keyDirectoryLink . '/' . basename($publicKey),
    ]);

    $originalArchive = file_get_contents($archive);
    if (!is_string($originalArchive) || file_put_contents($archive, "tamper", FILE_APPEND) === false) throw new RuntimeException('cannot tamper fixture ZIP');
    [$tamperStatus, $tamperOutput] = $run($verifier, [
        'artifact-manifest' => $manifestPath,
        'archive' => $archive,
        'attestation' => $attestation,
        'public-key' => $publicKey,
    ]);
    if (file_put_contents($archive, $originalArchive) !== strlen($originalArchive)) throw new RuntimeException('cannot restore fixture ZIP');

    $dirtyManifest = $manifest;
    $dirtyManifest['kind'] = 'review-candidate';
    $dirtyManifest['release_state'] = 'candidate/dirty-not-release';
    $dirtyManifestPath = $seed . '/dirty-manifest.json';
    if (!$writeJson($dirtyManifestPath, $dirtyManifest)) throw new RuntimeException('cannot write dirty fixture manifest');
    $dirtyArguments = $signArguments;
    $dirtyArguments['artifact-manifest'] = $dirtyManifestPath;
    $dirtyArguments['output'] = $seed . '/dirty-attestation.json';
    [$dirtyStatus, $dirtyOutput] = $run($signer, $dirtyArguments);

    $missingTreeManifest = $manifest;
    unset($missingTreeManifest['source_revision']['tree']);
    $missingTreeManifestPath = $seed . '/missing-tree-manifest.json';
    if (!$writeJson($missingTreeManifestPath, $missingTreeManifest)) throw new RuntimeException('cannot write missing-tree fixture manifest');
    $missingTreeArguments = $signArguments;
    $missingTreeArguments['artifact-manifest'] = $missingTreeManifestPath;
    $missingTreeArguments['output'] = $seed . '/missing-tree-attestation.json';
    [$missingTreeStatus, $missingTreeOutput] = $run($signer, $missingTreeArguments);

    $invalidTimeArguments = $signArguments;
    $invalidTimeArguments['output'] = $seed . '/invalid-time-attestation.json';
    $invalidTimeArguments['approved-at'] = '2026-99-12T00:00:00Z';
    [$invalidTimeStatus, $invalidTimeOutput] = $run($signer, $invalidTimeArguments);

    $passed = $signStatus === 0
        && str_contains($signOutput, 'release bundle attestation written')
        && $verifyStatus === 0
        && str_contains($verifyOutput, 'release bundle verified')
        && str_contains($verifyOutput, 'public_key_sha256=')
        && $linkedKeyStatus !== 0
        && str_contains($linkedKeyOutput, 'path must not contain symbolic links')
        && $tamperStatus !== 0
        && str_contains($tamperOutput, 'archive does not match the artifact manifest')
        && $dirtyStatus !== 0
        && str_contains($dirtyOutput, 'artifact manifest is not an unsigned release candidate')
        && !is_file($dirtyArguments['output'])
        && $missingTreeStatus !== 0
        && str_contains($missingTreeOutput, 'no valid clean SandIAM source revision')
        && !is_file($missingTreeArguments['output'])
        && $invalidTimeStatus !== 0
        && str_contains($invalidTimeOutput, 'approved_at must be a UTC ISO-8601 second timestamp')
        && !is_file($invalidTimeArguments['output']);
} finally {
    $removeTree($seed);
}

if (!$passed) {
    fwrite(STDERR, "release bundle signer/verifier did not enforce signing, tamper rejection, and dirty-candidate refusal\n");
    exit(1);
}

echo "SandIAM release bundle attestation checks passed\n";
