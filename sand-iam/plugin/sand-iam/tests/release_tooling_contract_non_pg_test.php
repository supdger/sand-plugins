<?php

declare(strict_types=1);

// behavior-test-gate: static-rule
$root = dirname(__DIR__, 3);
require_once __DIR__ . '/isolated_sandiam_fixture.php';
require_once $root . '/tools/release-build-contract-policy.php';
$run = static function (array $arguments, bool $success = true): string {
    $output = [];
    exec(implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $status);
    if (($status === 0) !== $success) throw new RuntimeException(implode("\n", $output));
    return implode("\n", $output);
};
$run([PHP_BINARY, $root . '/tools/build-lifecycle.php', '--check']);
echo "[PASS] current076 generation matches frozen source without writes\n";
sandIamCheckReleaseBuildContract($root);
echo "[PASS] committed dependency byte verification uses current package version\n";
sandIamWithIsolatedFixture($root, static function (string $fixture) use ($run): void {
    $output = dirname($fixture) . '/legacy';
    $run([PHP_BINARY, $fixture . '/tools/build-lifecycle.php', '--profile=legacy-0.7.3', '--output=' . $output]);
    // Captured from the unchanged historical builder, in an isolated fixture.
    foreach (['install' => '4afc0964e5af4b15ff7a61289eddcaafd7e981960cb278d878d0dc7cfacb8054',
        'update' => '4a541983b566c279b7538a8245e3c972966696d9ff4d76a161924b59475bdaf3',
        'uninstall' => '044694a30a93e2010c13eaaaa7bd0ff0bfbfd3578a19f70bbeb2b3369a74997d'] as $name => $sha256) {
        if (hash_file('sha256', $output . '/' . $name . '.sql') !== $sha256) {
            throw new RuntimeException('Historical embedded073 bytes changed: ' . $name);
        }
    }
    echo "[PASS] legacy073 retains original3 SQL digests and42-file manifest\n";
    $run([PHP_BINARY, $fixture . '/tools/build-lifecycle.php', '--output=' . $output], false);
    $run([PHP_BINARY, $fixture . '/tools/build-lifecycle.php', '--output=' . $fixture . '/unsafe'], false);
    echo "[PASS] existing and source-internal output destinations rejected\n";
    $contractPath = $fixture . '/release-build-contract.json';
    $original = file_get_contents($contractPath);
    $contract = json_decode($original, true, 32, JSON_THROW_ON_ERROR);
    $contract['toolchain']['php'] = '0.0.0';
    file_put_contents($contractPath, json_encode($contract, JSON_THROW_ON_ERROR));
    sandIamCheckReleaseBuildContract($fixture);
    $rejected = false;
    try { sandIamCheckReleaseBuildContract($fixture, true); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Explicit toolchain reproduction accepted mismatched PHP');
    echo "[PASS] frozen byte validation and explicit toolchain reproduction remain distinct\n";
    $contract['kind'] = 'reviewed-runtime-payload-inputs';
    file_put_contents($contractPath, json_encode($contract, JSON_THROW_ON_ERROR));
    $rejected = false;
    try { sandIamCheckReleaseBuildContract($fixture); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Historical rebuild contract bypassed original toolchain');
    echo "[PASS] historical reviewed-runtime contract still requires recorded toolchain\n";
    file_put_contents($contractPath, $original);
    $contract = json_decode($original, true, 32, JSON_THROW_ON_ERROR);
    $contract['composer']['environment']['COMPOSER_ROOT_VERSION'] = '0.7.3';
    file_put_contents($contractPath, json_encode($contract, JSON_THROW_ON_ERROR));
    $rejected = false;
    try { sandIamCheckReleaseBuildContract($fixture); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Stale dependency root version accepted');
    echo "[PASS] stale073 dependency root version rejected on076\n";
    file_put_contents($contractPath, $original);
    file_put_contents($fixture . '/sdk/typescript/dist/index.js', "\n// fixture tamper\n", FILE_APPEND);
    $rejected = false;
    try { sandIamCheckReleaseBuildContract($fixture); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Frozen dependency tamper accepted');
    echo "[PASS] modified frozen SDK bytes rejected\n";
});
