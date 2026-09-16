<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
require_once __DIR__ . '/isolated_sandiam_fixture.php';
require_once $root . '/tools/release-bundle-attestation.php';

/** @return array{0:int,1:string} */
$run = static function (string $tool, array $arguments): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool);
    foreach ($arguments as $argument) $command .= ' ' . escapeshellarg($argument);
    $output = [];
    exec($command . ' 2>&1', $output, $status);
    return [$status, implode(PHP_EOL, $output)];
};

/** @return bool */
$git = static function (string $workspace, array $arguments): bool {
    $command = 'git -C ' . escapeshellarg($workspace) . ' ' . implode(' ', array_map('escapeshellarg', $arguments));
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, 'release provenance Git command failed: ' . implode(' ', $arguments) . PHP_EOL);
    }
    return $status === 0;
};

$passed = sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($run, $git): bool {
    $workspace = dirname($fixtureRoot);
    $writeGitBlob = static function (string $commit, string $relative, string $destination) use ($workspace): bool {
        $blob = shell_exec('git -C ' . escapeshellarg($workspace) . ' show ' . escapeshellarg($commit . ':sand-iam/' . $relative));
        return is_string($blob) && file_put_contents($destination, $blob) !== false;
    };
    $crlfRelative = 'docs/user-guide/configuration-reference.md';
    $attributes = $fixtureRoot . '/.gitattributes';
    if (file_put_contents($attributes, $crlfRelative . " text eol=crlf\n") === false
        || !$git($workspace, ['init', '--quiet'])
        || !$git($workspace, ['config', 'core.autocrlf', 'false'])
        || !$git($workspace, ['add', 'sand-iam'])
        || !$git($workspace, ['-c', 'user.name=SandIAM fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '-m', 'fixture'])) {
        fwrite(STDERR, "release provenance setup failed: initial fixture commit\n");
        return false;
    }
    $controllerRelative = 'plugin/sand-iam/app/api/controller/AuthController.php';
    if (file_put_contents($attributes, $crlfRelative . " text eol=crlf\n" . $controllerRelative . " export-ignore\n") === false
        || !$git($workspace, ['add', 'sand-iam/.gitattributes'])
        || !$git($workspace, ['-c', 'user.name=SandIAM fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '-m', 'export-ignore-controller'])) {
        fwrite(STDERR, "release provenance setup failed: export-ignore commit\n");
        return false;
    }
    if (!unlink($fixtureRoot . '/' . $crlfRelative)
        || !$git($workspace, ['checkout-index', '--', 'sand-iam/' . $crlfRelative])
        || !$git($workspace, ['add', 'sand-iam/' . $crlfRelative])) {
        fwrite(STDERR, "release provenance setup failed: CRLF checkout\n");
        return false;
    }
    $commit = trim((string) shell_exec('git -C ' . escapeshellarg($workspace) . ' rev-parse HEAD'));
    if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
        fwrite(STDERR, "release provenance setup failed: resolve fixture commit\n");
        return false;
    }
    $eol = (string) shell_exec('git -C ' . escapeshellarg($workspace) . ' ls-files --eol -- ' . escapeshellarg('sand-iam/' . $crlfRelative));
    $cleanCrlfWorktree = str_contains($eol, 'i/lf') && str_contains($eol, 'w/crlf');
    $integrity = $fixtureRoot . '/tools/check-package-integrity.php';
    $builder = $fixtureRoot . '/tools/build-review-candidate.php';
    [$baselineStatus, $baselineOutput] = $run($integrity, []);
    if ($baselineStatus !== 0 || !str_contains($baselineOutput, '[PASS] eligible release payload is clean, tracked, and matches HEAD Git blobs')) {
        fwrite(STDERR, "release provenance setup failed: clean fixture baseline\n{$baselineOutput}\n");
        return false;
    }
    $contractPath = $fixtureRoot . '/release-build-contract.json';
    $correctContract = file_get_contents($contractPath);
    try {
        $mismatchedContract = is_string($correctContract)
            ? json_decode($correctContract, true, 512, JSON_THROW_ON_ERROR)
            : null;
    } catch (JsonException) {
        return false;
    }
    if (!is_array($mismatchedContract)) return false;
    $actualVendorHash = $mismatchedContract['generated_payloads']['plugin/sand-iam/vendor']['tree_sha256'] ?? null;
    if (!is_string($actualVendorHash) || preg_match('/^[0-9a-f]{64}$/', $actualVendorHash) !== 1) return false;
    $mismatchedContract['generated_payloads']['plugin/sand-iam/vendor']['tree_sha256'] =
        ($actualVendorHash[0] === '0' ? '1' : '0') . substr($actualVendorHash, 1);
    $mismatchedContractJson = json_encode(
        $mismatchedContract,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . "\n";
    if (file_put_contents($contractPath, $mismatchedContractJson) === false
        || !$git($workspace, ['add', 'sand-iam/release-build-contract.json'])
        || !$git($workspace, ['-c', 'user.name=SandIAM fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '-m', 'mismatched-vendor-contract'])) {
        return false;
    }
    [$mismatchedContractStatus, $mismatchedContractOutput] = $run($integrity, []);
    if (file_put_contents($contractPath, $correctContract) === false
        || !$git($workspace, ['add', 'sand-iam/release-build-contract.json'])
        || !$git($workspace, ['-c', 'user.name=SandIAM fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '-m', 'restore-git-blob-contract'])) {
        return false;
    }
    $commit = trim((string) shell_exec('git -C ' . escapeshellarg($workspace) . ' rev-parse HEAD'));
    if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) return false;
    $mismatchedContractRejected = $mismatchedContractStatus !== 0
        && str_contains($mismatchedContractOutput, 'release build contract locks toolchain and reviewed runtime payloads');

    $negative = static function (string $path, string $replacement, string $expected) use ($run, $integrity): bool {
        $original = file_get_contents($path);
        if (!is_string($original) || file_put_contents($path, $replacement) === false) return false;
        try {
            [$status, $output] = $run($integrity, []);
            return $status !== 0 && str_contains($output, $expected);
        } finally {
            file_put_contents($path, $original);
        }
    };
    $vendor = $negative($fixtureRoot . '/plugin/sand-iam/vendor/autoload.php', "<?php // tampered\n", 'release build contract locks toolchain and reviewed runtime payloads');
    $dist = $negative($fixtureRoot . '/sdk/typescript/dist/index.js', "export {};\n", 'release build contract locks toolchain and reviewed runtime payloads');
    $lock = $negative($fixtureRoot . '/sdk/typescript/pnpm-lock.yaml', "lockfileVersion: '9.0'\n", 'release build contract locks toolchain and reviewed runtime payloads');
    $contract = $negative($fixtureRoot . '/release-build-contract.json', '{"schema":"sand-iam.release-build-contract/v1","kind":"reviewed-runtime-payload-inputs","source":{"formal_builder":"git-blob-only","repeat_build":"independent-git-stage"},"toolchain":{"php":"0"}}', 'release build contract locks toolchain and reviewed runtime payloads');
    $injected = $fixtureRoot . '/plugin/sand-iam/vendor/provenance-injection.php';
    if (file_put_contents($injected, "<?php // untracked injection\n") === false) return false;
    [$injectedStatus, $injectedOutput] = $run($integrity, []);
    unlink($injected);
    $injection = $injectedStatus !== 0 && str_contains($injectedOutput, 'eligible release payload is clean, tracked, and matches HEAD Git blobs');

    $stageRoot = $workspace . '/stage';
    if (!mkdir($stageRoot, 0700)) return false;
    $archiveCommand = 'git -C ' . escapeshellarg($workspace) . ' archive --format=tar ' . escapeshellarg($commit) . ' sand-iam | tar -xf - -C ' . escapeshellarg($stageRoot);
    exec($archiveCommand . ' 2>&1', $archiveOutput, $archiveStatus);
    if ($archiveStatus !== 0) return false;
    [$exportIgnoredStatus, $exportIgnoredOutput] = $run($builder, ['--verify-git-stage=' . $stageRoot . '/sand-iam', '--source-commit=' . $commit]);
    if (!$writeGitBlob($commit, $crlfRelative, $stageRoot . '/sand-iam/' . $crlfRelative)
        || !copy($fixtureRoot . '/' . $controllerRelative, $stageRoot . '/sand-iam/' . $controllerRelative)) return false;
    [$stageStatus, $stageOutput] = $run($builder, ['--verify-git-stage=' . $stageRoot . '/sand-iam', '--source-commit=' . $commit]);
    $stageInjected = $stageRoot . '/sand-iam/plugin/sand-iam/vendor/provenance-injection.php';
    if (file_put_contents($stageInjected, "<?php // unexpected stage file\n") === false) return false;
    [$stageInjectionStatus, $stageInjectionOutput] = $run($builder, ['--verify-git-stage=' . $stageRoot . '/sand-iam', '--source-commit=' . $commit]);
    unlink($stageInjected);
    if (file_put_contents($stageRoot . '/sand-iam/plugin/sand-iam/vendor/autoload.php', "<?php // stage tamper\n") === false) return false;
    [$stageTamperStatus, $stageTamperOutput] = $run($builder, ['--verify-git-stage=' . $stageRoot . '/sand-iam', '--source-commit=' . $commit]);
    $stageParity = $exportIgnoredStatus !== 0
        && str_contains($exportIgnoredOutput, 'Git blob/source stage file-set mismatch: missing=' . $controllerRelative)
        && $stageStatus === 0 && str_contains($stageOutput, 'stage_matches_git_blobs')
        && $stageInjectionStatus !== 0 && str_contains($stageInjectionOutput, 'Git blob/source stage file-set mismatch: missing=; unexpected=plugin/sand-iam/vendor/provenance-injection.php')
        && $stageTamperStatus !== 0 && str_contains($stageTamperOutput, 'Git blob/source stage mismatch');

    [$buildStatus, $buildOutput] = $run($builder, ['--release-unsigned', '--source-commit=' . $commit]);
    $artifact = json_decode($buildOutput, true);
    $manifestPath = is_array($artifact) && is_string($artifact['artifact'] ?? null) ? $artifact['artifact'] . '/manifest.json' : '';
    $manifest = $manifestPath !== '' && is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
    $formalBuild = $buildStatus === 0 && is_array($manifest)
        && ($manifest['schema'] ?? null) === 'sand-iam.artifact-manifest/v8'
        && ($manifest['source_provenance']['mode'] ?? null) === 'git-blob-only'
        && ($manifest['source_provenance']['stage_matches_git_blobs'] ?? null) === true
        && ($manifest['source_provenance']['independent_git_stage_rebuild'] ?? null) === true
        && isset($manifest['files'][$controllerRelative]);
    $normalBuilderZip = false;
    if ($formalBuild && is_string($manifest['package']['archive'] ?? null)) {
        try {
            $builtArchive = dirname($manifestPath) . '/' . $manifest['package']['archive'];
            $builtInspection = sandIamInspectReleaseZip($builtArchive);
            $normalBuilderZip = $builtInspection['files'] === $manifest['files'];
        } catch (RuntimeException) {
            $normalBuilderZip = false;
        }
    }

    $vendorPath = $fixtureRoot . '/plugin/sand-iam/vendor/autoload.php';
    $vendorSource = file_get_contents($vendorPath);
    if (!is_string($vendorSource)
        || file_put_contents($vendorPath, $vendorSource . "// committed vendor change\n") === false
        || !$git($workspace, ['add', 'sand-iam/plugin/sand-iam/vendor/autoload.php'])
        || !$git($workspace, ['-c', 'user.name=SandIAM fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '-m', 'committed-vendor-change'])) {
        return false;
    }
    $changedVendorCommit = trim((string) shell_exec('git -C ' . escapeshellarg($workspace) . ' rev-parse HEAD'));
    $changedStageRoot = $workspace . '/changed-stage';
    if (!mkdir($changedStageRoot, 0700)) return false;
    $changedArchiveCommand = 'git -C ' . escapeshellarg($workspace) . ' archive --format=tar ' . escapeshellarg($changedVendorCommit) . ' sand-iam | tar -xf - -C ' . escapeshellarg($changedStageRoot);
    exec($changedArchiveCommand . ' 2>&1', $changedArchiveOutput, $changedArchiveStatus);
    if ($changedArchiveStatus === 0
        && (!$writeGitBlob($changedVendorCommit, $crlfRelative, $changedStageRoot . '/sand-iam/' . $crlfRelative)
            || !copy($fixtureRoot . '/' . $controllerRelative, $changedStageRoot . '/sand-iam/' . $controllerRelative))) {
        return false;
    }
    [$changedVendorStatus, $changedVendorOutput] = $changedArchiveStatus === 0
        ? $run($builder, ['--verify-git-stage=' . $changedStageRoot . '/sand-iam', '--source-commit=' . $changedVendorCommit])
        : [1, 'cannot create committed vendor stage'];
    if (file_put_contents($vendorPath, $vendorSource) === false
        || !$git($workspace, ['add', 'sand-iam/plugin/sand-iam/vendor/autoload.php'])
        || !$git($workspace, ['-c', 'user.name=SandIAM fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '-m', 'restore-vendor'])) {
        return false;
    }
    $committedVendorMismatch = $changedVendorStatus !== 0
        && str_contains($changedVendorOutput, 'release build contract does not match Git-blob stage payload: plugin/sand-iam/vendor');

    $unsupportedLink = $fixtureRoot . '/docs/user-guide/unsupported-git-mode.md';
    if (!symlink('../README.md', $unsupportedLink)
        || !$git($workspace, ['add', 'sand-iam/docs/user-guide/unsupported-git-mode.md'])
        || !$git($workspace, ['-c', 'user.name=SandIAM fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '-m', 'unsupported-git-mode'])) {
        return false;
    }
    $unsupportedCommit = trim((string) shell_exec('git -C ' . escapeshellarg($workspace) . ' rev-parse HEAD'));
    [$unsupportedStatus, $unsupportedOutput] = $run($builder, ['--release-unsigned', '--source-commit=' . $unsupportedCommit]);
    if (!$git($workspace, ['rm', '--quiet', 'sand-iam/docs/user-guide/unsupported-git-mode.md'])
        || !$git($workspace, ['-c', 'user.name=SandIAM fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '-m', 'remove-unsupported-git-mode'])) {
        return false;
    }

    $newlineRelative = "docs/user-guide/eligible-newline\nname.md";
    if (file_put_contents($fixtureRoot . '/' . $newlineRelative, "# newline fixture\n") === false
        || !$git($workspace, ['add', 'sand-iam/' . $newlineRelative])
        || !$git($workspace, ['-c', 'user.name=SandIAM fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--quiet', '-m', 'newline-eligible-path'])) {
        return false;
    }
    $newlineCommit = trim((string) shell_exec('git -C ' . escapeshellarg($workspace) . ' rev-parse HEAD'));
    [$newlineStatus, $newlineOutput] = $run($builder, ['--release-unsigned', '--source-commit=' . $newlineCommit]);
    $unsupportedTreeInputs = $unsupportedStatus !== 0
        && str_contains($unsupportedOutput, 'unsupported Git tree mode: 120000')
        && $newlineStatus !== 0
        && str_contains($newlineOutput, 'unsupported Git tree path characters');
    $results = [
        'clean_crlf_worktree' => $cleanCrlfWorktree,
        'mismatched_contract_rejected' => $mismatchedContractRejected,
        'vendor_tamper_rejected' => $vendor,
        'dist_tamper_rejected' => $dist,
        'lock_tamper_rejected' => $lock,
        'contract_tamper_rejected' => $contract,
        'untracked_injection_rejected' => $injection,
        'git_stage_parity' => $stageParity,
        'formal_git_blob_build' => $formalBuild,
        'normal_builder_zip' => $normalBuilderZip,
        'committed_vendor_mismatch_rejected' => $committedVendorMismatch,
        'unsupported_tree_inputs_rejected' => $unsupportedTreeInputs,
    ];
    foreach ($results as $name => $ok) {
        if (!$ok) {
            fwrite(STDERR, "release provenance subcheck failed: {$name}\n");
        }
    }
    return !in_array(false, $results, true);
});

if (!$passed) {
    fwrite(STDERR, "release provenance gates did not reject altered, unsupported, or untracked runtime inputs, or could not produce a Git-blob-only candidate\n");
    exit(1);
}

echo "SandIAM release provenance checks passed\n";
