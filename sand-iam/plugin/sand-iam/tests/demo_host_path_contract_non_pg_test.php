<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/**
 * Normal SandIAM demonstrations use the configured sand_plugins demo host.
 * Historical execution evidence and cross-plugin publication paths are not
 * scanned here: they deliberately retain their own provenance contracts.
 */
$sandIamRoot = dirname(__DIR__, 3);
$repositoryRoot = dirname($sandIamRoot);
$demoHostRoot = $repositoryRoot . '/sandadmin-demo-host';
$demoServerRoot = $demoHostRoot . '/server';
$pureHostRoot = '/Users/code/project/sandadmin';
$hostRootVariable = 'SAND_IAM_T01_' . 'HOST_ROOT';

function demoHostPathFail(string $message): never
{
    fwrite(STDERR, "SandIAM demo-host path contract failed: {$message}\n");
    exit(1);
}

$testsWithHostFallback = [];
foreach (glob($sandIamRoot . '/plugin/sand-iam/tests/*.php') ?: [] as $testFile) {
    if (realpath($testFile) === __FILE__) {
        continue;
    }
    $source = file_get_contents($testFile);
    if ($source === false) {
        demoHostPathFail('cannot read test: ' . $testFile);
    }
    if (!str_contains($source, "getenv('{$hostRootVariable}')")) {
        continue;
    }
    $testsWithHostFallback[] = $testFile;
    if (!str_contains($source, "?: '{$demoServerRoot}'")) {
        demoHostPathFail('test fallback is not the demo host: ' . basename($testFile));
    }
    if (str_contains($source, $pureHostRoot . '/server')) {
        demoHostPathFail('test falls back to the pure host: ' . basename($testFile));
    }
}

if (count($testsWithHostFallback) !== 36) {
    demoHostPathFail('unexpected host-fallback test count: ' . count($testsWithHostFallback));
}

$lifecycleVerifier = $sandIamRoot . '/tools/verify-human-auth-lifecycle.sh';
$lifecycleSource = file_get_contents($lifecycleVerifier);
if ($lifecycleSource === false
    || !str_contains($lifecycleSource, $hostRootVariable)
    || !str_contains($lifecycleSource, $demoServerRoot)
    || str_contains($lifecycleSource, $pureHostRoot . '/server')) {
    demoHostPathFail('human-auth lifecycle verifier has an unsafe host default');
}

$currentArtifacts = [
    $repositoryRoot . '/AGENTS.md',
    $repositoryRoot . '/.codex/autopilot/tasks.md',
    $sandIamRoot . '/docs/development/sand-iam-development-entry.md',
    $sandIamRoot . '/docs/development/sand-iam-p0-contract.md',
    $sandIamRoot . '/docs/development/sand-iam-pg-collaboration.md',
    $sandIamRoot . '/docs/development/sand-iam-task-board.md',
    $sandIamRoot . '/docs/development/sand-iam-terminal-release-runbook.md',
];
foreach ($currentArtifacts as $artifact) {
    $source = file_get_contents($artifact);
    if ($source === false || !str_contains($source, $demoHostRoot)) {
        demoHostPathFail('current artifact omits the demo host: ' . basename($artifact));
    }
    if (!str_contains($source, $pureHostRoot) || !str_contains($source, '纯净')) {
        demoHostPathFail('current artifact does not preserve the pure-host boundary: ' . basename($artifact));
    }
}

fwrite(STDOUT, "SandIAM demo-host path contract passed (36 test fallbacks).\n");
