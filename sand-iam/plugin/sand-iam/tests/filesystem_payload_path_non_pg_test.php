<?php

declare(strict_types=1);

// behavior-test-gate: static-rule
$root = dirname(__DIR__, 3);
require_once $root . '/tools/package-payload-policy.php';
require_once __DIR__ . '/isolated_sandiam_fixture.php';

foreach (['../file', 'a/../file', 'a/./file', '/absolute', 'C:/absolute', "a/\0file"] as $path) {
    $rejected = false;
    try { sandIamFilesystemRelativePath($path); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Unsafe filesystem name accepted');
}
echo "[PASS] filesystem relative paths still reject traversal, absolute and control names\n";
foreach (['a\\b', 'a\\..\\b'] as $path) {
    $rejected = false;
    try { sandIamCanonicalPayloadPath($path); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Strict Git/ZIP path accepted backslash');
}
echo "[PASS] strict Git/ZIP path boundary never normalizes backslashes\n";
if (DIRECTORY_SEPARATOR === '\\') {
    if (sandIamFilesystemRelativePath('a\\b\\file') !== 'a/b/file') {
        throw new RuntimeException('Native Windows separators not normalized');
    }
} else {
    $rejected = false;
    try { sandIamFilesystemRelativePath('a\\b'); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Literal POSIX backslash filename accepted');
}
echo "[PASS] native separator conversion preserves the POSIX literal-name restriction\n";
$files = sandIamPayloadFiles($root, true);
if (!in_array('sdk/typescript/dist/index.js', $files, true)) throw new RuntimeException('SDK dist missing from payload');
foreach ($files as $name) {
    if (str_contains($name, '\\') || str_contains($name, '/tests/')) throw new RuntimeException('Noncanonical/excluded payload key');
}
echo "[PASS] real filesystem payload keys use forward slashes and exclude tests\n";
sandIamWithIsolatedFixture($root, static function (string $fixture): void {
    if (!is_file($fixture . '/sdk/typescript/dist/index.js')
        || is_dir($fixture . '/plugin/sand-iam/tests')) {
        throw new RuntimeException('Fixture copy lost SDK dist or retained excluded tests');
    }
});
echo "[PASS] native fixture copy retains SDK dist while excluding test trees\n";
