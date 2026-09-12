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

/** @param array<string,string> $entries */
$writeZip = static function (string $path, array $entries): void {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('cannot create ZIP fixture');
    try {
        foreach ($entries as $name => $contents) {
            if (!$zip->addFromString($name, $contents)) throw new RuntimeException('cannot add ZIP fixture entry');
        }
    } finally {
        if (!$zip->close()) throw new RuntimeException('cannot close ZIP fixture');
    }
};

/** @param array<string,string> $entries */
$rejectZip = static function (string $path, array $entries, string $expected) use ($writeZip): bool {
    $writeZip($path, $entries);
    try {
        sandIamInspectReleaseZip($path);
        return false;
    } catch (RuntimeException $exception) {
        return $exception->getMessage() === $expected;
    }
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
    $fixtureEntries = [
        'LICENSE' => "Apache License fixture\n",
        'SBOM.cdx.json' => "{\"bomFormat\":\"CycloneDX\"}\n",
        'THIRD_PARTY_NOTICES.md' => "# Notices\n",
        'SECURITY.md' => "# Security\n",
        'CONTRIBUTING.md' => "# Contributing\n",
        'README.md' => "# SandIAM\n",
        'update.sql' => "-- immutable lifecycle fixture\n",
        'release-build-contract.json' => "{\"schema\":\"sand-iam.release-build-contract/v1\"}\n",
    ];
    $writeZip($archive, $fixtureEntries);

    $inspection = sandIamInspectReleaseZip($archive);
    $archiveHash = hash_file('sha256', $archive);
    $archiveBytes = filesize($archive);
    if (!is_string($archiveHash) || !is_int($archiveBytes)) throw new RuntimeException('cannot hash fixture ZIP');
    $manifest = [
        'schema' => 'sand-iam.artifact-manifest/v8',
        'kind' => 'release-candidate-unsigned',
        'release_state' => 'release/unsigned',
        'archive_authority_parity' => ['passed' => true, 'normal_package_recovery_descriptors' => 'excluded'],
        'reproducibility' => [
            'bit_identical_zip' => true,
            'entry_list_identical' => true,
        ],
        'package' => [
            'app' => 'sand-iam',
            'version' => '0.7.1',
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
        'source_provenance' => [
            'mode' => 'git-blob-only',
            'commit' => str_repeat('a', 40),
            'tree' => str_repeat('b', 40),
            'stage_matches_git_blobs' => true,
            'independent_git_stage_rebuild' => true,
            'build_contract_sha256' => $inspection['files']['release-build-contract.json']['sha256'],
        ],
        'files' => $inspection['files'],
    ];
    $manifestPath = $seed . '/manifest.json';
    if (!$writeJson($manifestPath, $manifest)) throw new RuntimeException('cannot write fixture manifest');

    $strictPathRules = !sandIamPayloadPathSupported('a/./a')
        && !sandIamPayloadPathSupported('..\\escape')
        && !sandIamPayloadPathSupported('/absolute')
        && !sandIamPayloadPathSupported('C:drive-prefix')
        && !sandIamPayloadPathSupported("control\x01path")
        && !sandIamPayloadPathSupported("utf8-control\u{0085}path")
        && $rejectZip($seed . '/dot-segment-actual.zip', ['a' => 'one', 'a/./a' => 'two'], 'ZIP contains an unsafe path')
        && $rejectZip($seed . '/backslash.zip', ['..\\escape' => 'bad'], 'ZIP contains an unsafe path')
        && $rejectZip($seed . '/absolute.zip', ['/absolute' => 'bad'], 'ZIP contains an unsafe path')
        && $rejectZip($seed . '/drive-prefix.zip', ['C:drive-prefix' => 'bad'], 'ZIP contains an unsafe path')
        && $rejectZip($seed . '/empty-segment.zip', ['empty//segment' => 'bad'], 'ZIP contains an unsafe path')
        && $rejectZip($seed . '/utf8-control.zip', ["utf8-control\u{0085}path" => 'bad'], 'ZIP contains an unsafe path')
        && $rejectZip($seed . '/file-directory-conflict.zip', ['dir' => 'file', 'dir/file' => 'child'], 'ZIP contains a file/directory path conflict')
        && $rejectZip($seed . '/ancestor-forward.zip', ['a' => 'file', 'a/b/' => 'directory'], 'ZIP contains a file/directory path conflict')
        && $rejectZip($seed . '/ancestor-reverse.zip', ['a/b/' => 'directory', 'a' => 'file'], 'ZIP contains a file/directory path conflict')
        && $rejectZip($seed . '/duplicate-canonical.zip', ['dir' => 'file', 'dir/' => 'directory'], 'ZIP contains a duplicate canonical path');

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

    $descriptorArchive = $seed . '/descriptor-sidecars.zip';
    $descriptorEntries = $fixtureEntries;
    foreach (sandIamGeneratedDescriptorPaths() as $descriptor) $descriptorEntries[$descriptor] = "historical descriptor fixture\n";
    $writeZip($descriptorArchive, $descriptorEntries);
    $descriptorManifest = $manifest;
    $descriptorHash = hash_file('sha256', $descriptorArchive);
    $descriptorBytes = filesize($descriptorArchive);
    if (!is_string($descriptorHash) || !is_int($descriptorBytes)) throw new RuntimeException('cannot hash descriptor ZIP');
    // Deliberately omit the descriptor entries from this manifest. Both tools
    // must inspect the archive itself rather than trusting the claim above.
    $descriptorManifest['package']['archive'] = basename($descriptorArchive);
    $descriptorManifest['package']['sha256'] = $descriptorHash;
    $descriptorManifest['package']['bytes'] = $descriptorBytes;
    $descriptorManifestPath = $seed . '/descriptor-manifest.json';
    if (!$writeJson($descriptorManifestPath, $descriptorManifest)) throw new RuntimeException('cannot write descriptor fixture manifest');
    $descriptorArguments = $signArguments;
    $descriptorArguments['artifact-manifest'] = $descriptorManifestPath;
    $descriptorArguments['archive'] = $descriptorArchive;
    $descriptorArguments['output'] = $seed . '/descriptor-attestation.json';
    [$descriptorSignStatus, $descriptorSignOutput] = $run($signer, $descriptorArguments);
    [$descriptorVerifyStatus, $descriptorVerifyOutput] = $run($verifier, [
        'artifact-manifest' => $descriptorManifestPath,
        'archive' => $descriptorArchive,
        'attestation' => $attestation,
        'public-key' => $publicKey,
    ]);

    // Output safety fixtures: a regular target, a target symlink, and a
    // symlinked parent must remain unchanged; four concurrent signers can only
    // publish one no-replace result and must not leave their temporary inodes.
    $existingOutput = $seed . '/existing-attestation.json';
    $existingContents = "existing regular target\n";
    if (file_put_contents($existingOutput, $existingContents) !== strlen($existingContents)) throw new RuntimeException('cannot write existing output fixture');
    $existingArguments = $signArguments;
    $existingArguments['output'] = $existingOutput;
    [$existingStatus, $existingOutputText] = $run($signer, $existingArguments);

    $linkTarget = $seed . '/attestation-link-target.json';
    $linkContents = "existing link target\n";
    if (file_put_contents($linkTarget, $linkContents) !== strlen($linkContents)) throw new RuntimeException('cannot write link target fixture');
    $linkOutput = $seed . '/attestation-link.json';
    if (!symlink($linkTarget, $linkOutput)) throw new RuntimeException('cannot create output link fixture');
    $linkArguments = $signArguments;
    $linkArguments['output'] = $linkOutput;
    [$linkStatus, $linkOutputText] = $run($signer, $linkArguments);

    $parentLink = $seed . '/linked-output-parent';
    if (!symlink($seed, $parentLink)) throw new RuntimeException('cannot create output-parent link fixture');
    $parentLinkArguments = $signArguments;
    $parentLinkArguments['output'] = $parentLink . '/parent-link-attestation.json';
    [$parentLinkStatus, $parentLinkOutput] = $run($signer, $parentLinkArguments);

    $parallelOutput = $seed . '/parallel-attestation.json';
    $parallelCommand = static function (array $arguments) use ($signer): string {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($signer);
        foreach ($arguments as $name => $value) $command .= ' --' . $name . '=' . escapeshellarg($value);
        return $command;
    };
    $parallelArguments = $signArguments;
    $parallelArguments['output'] = $parallelOutput;
    $processes = [];
    for ($index = 0; $index < 4; ++$index) {
        $process = proc_open($parallelCommand($parallelArguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('cannot start concurrent signer fixture');
        $processes[] = [$process, $pipes];
    }
    $parallelStatuses = [];
    foreach ($processes as [$process, $pipes]) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $parallelStatuses[] = proc_close($process);
    }
    $parallelSuccesses = count(array_filter($parallelStatuses, static fn (int $status): bool => $status === 0));
    $temporaryEntries = array_filter(scandir($seed) ?: [], static fn (string $entry): bool => str_starts_with($entry, '.sand-iam-attestation-'));

    // Exercise the production publisher directly with its process-local I/O
    // seams. The collision file belongs to another writer and must survive;
    // all failing publication paths must leave no final that a verifier could
    // consume and no owned temporary inode behind.
    $collisionRandom = [str_repeat('a', 18), str_repeat('b', 18)];
    $collisionTemporary = $seed . '/.sand-iam-attestation-' . bin2hex($collisionRandom[0]) . '.tmp';
    $collisionContents = "other writer temporary\n";
    if (file_put_contents($collisionTemporary, $collisionContents) !== strlen($collisionContents)) throw new RuntimeException('cannot create colliding temporary fixture');
    $collisionOutput = $seed . '/collision-published.json';
    sandIamPublishNewAttestation($seed, $collisionOutput, "non-release helper fixture\n", [
        'random' => static function (int $bytes) use (&$collisionRandom): string {
            $value = array_shift($collisionRandom);
            if (!is_string($value) || strlen($value) !== $bytes) throw new RuntimeException('missing collision random fixture');
            return $value;
        },
    ]);
    $collisionPreserved = file_get_contents($collisionTemporary) === $collisionContents && is_file($collisionOutput);
    if (!unlink($collisionOutput)) throw new RuntimeException('cannot clean successful collision fixture output');

    $linkFailureOutput = $seed . '/link-failure.json';
    $linkFailureMessage = '';
    try {
        sandIamPublishNewAttestation($seed, $linkFailureOutput, "non-release helper fixture\n", [
            'link' => static fn (string $source, string $target): bool => false,
        ]);
    } catch (RuntimeException $exception) {
        $linkFailureMessage = $exception->getMessage();
    }

    $chmodFailureOutput = $seed . '/chmod-failure.json';
    $chmodFailureMessage = '';
    try {
        sandIamPublishNewAttestation($seed, $chmodFailureOutput, "non-release helper fixture\n", [
            'chmod' => static fn (string $path, int $mode): bool => false,
        ]);
    } catch (RuntimeException $exception) {
        $chmodFailureMessage = $exception->getMessage();
    }

    $directoryFsyncFailureOutput = $seed . '/directory-fsync-failure.json';
    $directoryFsyncFailureMessage = '';
    try {
        sandIamPublishNewAttestation($seed, $directoryFsyncFailureOutput, "non-release helper fixture\n", [
            'fsync' => static function ($stream, bool $directory): bool {
                return !$directory && fsync($stream);
            },
        ]);
    } catch (RuntimeException $exception) {
        $directoryFsyncFailureMessage = $exception->getMessage();
    }
    $failureTemporaryEntries = array_values(array_filter(scandir($seed) ?: [], static fn (string $entry): bool => str_starts_with($entry, '.sand-iam-attestation-')));

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

    $missingProvenanceManifest = $manifest;
    unset($missingProvenanceManifest['source_provenance']);
    $missingProvenanceManifestPath = $seed . '/missing-source-provenance-manifest.json';
    if (!$writeJson($missingProvenanceManifestPath, $missingProvenanceManifest)) throw new RuntimeException('cannot write missing provenance fixture manifest');
    $missingProvenanceArguments = $signArguments;
    $missingProvenanceArguments['artifact-manifest'] = $missingProvenanceManifestPath;
    $missingProvenanceArguments['output'] = $seed . '/missing-source-provenance-attestation.json';
    [$missingProvenanceStatus, $missingProvenanceOutput] = $run($signer, $missingProvenanceArguments);

    $missingContractManifest = $manifest;
    unset($missingContractManifest['files']['release-build-contract.json']);
    $missingContractManifestPath = $seed . '/missing-build-contract-manifest.json';
    if (!$writeJson($missingContractManifestPath, $missingContractManifest)) throw new RuntimeException('cannot write missing build-contract fixture manifest');
    $missingContractArguments = $signArguments;
    $missingContractArguments['artifact-manifest'] = $missingContractManifestPath;
    $missingContractArguments['output'] = $seed . '/missing-build-contract-attestation.json';
    [$missingContractStatus, $missingContractOutput] = $run($signer, $missingContractArguments);

    $wrongContractSummaryManifest = $manifest;
    $wrongContractSummaryManifest['source_provenance']['build_contract_sha256'] = str_repeat('d', 64);
    $wrongContractSummaryManifestPath = $seed . '/wrong-build-contract-summary-manifest.json';
    if (!$writeJson($wrongContractSummaryManifestPath, $wrongContractSummaryManifest)) throw new RuntimeException('cannot write wrong build-contract summary fixture manifest');
    $wrongContractSummaryArguments = $signArguments;
    $wrongContractSummaryArguments['artifact-manifest'] = $wrongContractSummaryManifestPath;
    $wrongContractSummaryArguments['output'] = $seed . '/wrong-build-contract-summary-attestation.json';
    [$wrongContractSummaryStatus, $wrongContractSummaryOutput] = $run($signer, $wrongContractSummaryArguments);

    $invalidTimeArguments = $signArguments;
    $invalidTimeArguments['output'] = $seed . '/invalid-time-attestation.json';
    $invalidTimeArguments['approved-at'] = '2026-99-12T00:00:00Z';
    [$invalidTimeStatus, $invalidTimeOutput] = $run($signer, $invalidTimeArguments);

    $passed = $signStatus === 0
        && str_contains($signOutput, 'release bundle attestation written')
        && $verifyStatus === 0
        && str_contains($verifyOutput, 'release bundle verified')
        && str_contains($verifyOutput, 'public_key_sha256=')
        && $strictPathRules
        && $descriptorSignStatus !== 0
        && str_contains($descriptorSignOutput, 'release artifact contains a historical recovery descriptor')
        && !is_file($descriptorArguments['output'])
        && $descriptorVerifyStatus !== 0
        && str_contains($descriptorVerifyOutput, 'release artifact contains a historical recovery descriptor')
        && $existingStatus !== 0
        && str_contains($existingOutputText, 'refusing to replace attestation output')
        && file_get_contents($existingOutput) === $existingContents
        && $linkStatus !== 0
        && str_contains($linkOutputText, 'refusing to replace attestation output')
        && is_link($linkOutput)
        && file_get_contents($linkTarget) === $linkContents
        && $parentLinkStatus !== 0
        && str_contains($parentLinkOutput, 'path must not contain symbolic links')
        && !file_exists($parentLink . '/parent-link-attestation.json')
        && $parallelSuccesses === 1
        && is_file($parallelOutput)
        && (fileperms($parallelOutput) & 0777) === 0644
        && $temporaryEntries === []
        && $collisionPreserved
        && $linkFailureMessage === 'cannot publish signed attestation'
        && !is_file($linkFailureOutput)
        && $chmodFailureMessage === 'cannot publish signed attestation'
        && !is_file($chmodFailureOutput)
        && $directoryFsyncFailureMessage === 'cannot safely clean failed attestation publication'
        && !is_file($directoryFsyncFailureOutput)
        && $failureTemporaryEntries === [basename($collisionTemporary)]
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
        && $missingProvenanceStatus !== 0
        && str_contains($missingProvenanceOutput, 'no verified Git-blob source provenance')
        && !is_file($missingProvenanceArguments['output'])
        && $missingContractStatus !== 0
        && str_contains($missingContractOutput, 'no packaged release build contract')
        && !is_file($missingContractArguments['output'])
        && $wrongContractSummaryStatus !== 0
        && str_contains($wrongContractSummaryOutput, 'no verified Git-blob source provenance')
        && !is_file($wrongContractSummaryArguments['output'])
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
