<?php

declare(strict_types=1);

/**
 * Native-platform release regression: real Git blobs and real ZIP entries.
 * Uses and removes only a newly created temporary fixture. No host or DB access.
 */
require_once __DIR__ . '/package-payload-policy.php';

function runReleaseTestCommand(array $args, bool $expectSuccess = true): string
{
    $stdout = tmpfile();
    $stderr = tmpfile();
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr],
        $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start regression command');
    fclose($pipes[0]);
    $status = proc_close($process);
    rewind($stdout);
    rewind($stderr);
    $output = (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);
    if (($status === 0) !== $expectSuccess) {
        throw new RuntimeException('Unexpected regression command exit: ' . $status . "\n" . $output);
    }
    if ($expectSuccess) echo $output;
    return $output;
}

function removeReleaseFixture(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        if (file_exists($path) || is_link($path)) {
            // Git marks loose objects read-only on Windows. Only this new
            // fixture's regular files are writable again; never follow links.
            if (DIRECTORY_SEPARATOR === '\\' && is_file($path) && !is_link($path)
                && !chmod($path, 0666)) {
                throw new RuntimeException('Cannot clear temporary file read-only flag: ' . $path);
            }
            if (!unlink($path)) throw new RuntimeException('Cannot remove temporary file: ' . $path);
        }
        return;
    }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file) {
        removeReleaseFixture($file->getPathname());
    }
    if (!rmdir($path)) throw new RuntimeException('Cannot remove temporary directory: ' . $path);
}

$started = microtime(true);
$temp = realpath(sys_get_temp_dir());
if ($temp === false) throw new RuntimeException('Cannot resolve temporary directory');
$fixture = $temp . '/sand-iam-cross-platform-' . bin2hex(random_bytes(8)) . ' 中文 % !';
if (!mkdir($fixture, 0700)) throw new RuntimeException('Cannot create isolated fixture');
try {
    $source = $fixture . '/源码 repo % !';
    mkdir($source);
    mkdir($source . '/tools');
    foreach (['build-independent-release.php', 'package-payload-policy.php'] as $tool) {
        copy(__DIR__ . '/' . $tool, $source . '/tools/' . $tool);
    }
    $directories = [
        'migrations', 'lifecycle', 'plugin/sand-iam', 'sandadmin-artd/src/views/plugin/sand-iam',
        'portal', 'sdk', 'docs/user-guide', 'examples',
    ];
    foreach (sandIamPayloadRoots() as $root) {
        if (in_array($root, $directories, true)) {
            mkdir($source . '/' . $root, 0700, true);
            file_put_contents($source . '/' . $root . '/fixture.txt', "fixture\n");
        } else {
            file_put_contents($source . '/' . $root, "fixture\n");
        }
    }
    file_put_contents($source . '/.gitattributes', "* -text\n");
    file_put_contents($source . '/info.ini', "app = sand-iam\nversion = 0.7.6\n");
    $special = "\xEF\xBB\xBF-- 中文；：反斜杠 \\ 和 / 保留\r\nDO \$test\$ BEGIN PERFORM 'a;b:c\\d/e'; END \$test\$;\r\n";
    $binary = "\0\xFFbinary\r\nunchanged\n";
    file_put_contents($source . '/update.sql', $special);
    file_put_contents($source . '/sdk/中文 文件 % !.bin', $binary);
    $generated = [];
    foreach (['plugin/sand-iam/vendor', 'sdk/typescript/dist'] as $directory) {
        mkdir($source . '/' . $directory, 0700, true);
        file_put_contents($source . '/' . $directory . '/fixture.txt', "frozen\n");
        $map = ['fixture.txt' => hash('sha256', "frozen\n")];
        $generated[$directory] = ['file_count' => 1, 'tree_sha256' =>
            hash('sha256', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
    }
    file_put_contents($source . '/plugin/sand-iam/composer.lock', "{}\n");
    file_put_contents($source . '/sdk/typescript/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
    file_put_contents($source . '/release-build-contract.json', json_encode([
        'generated_payloads' => $generated,
        'composer' => ['lock_sha256' => hash_file('sha256', $source . '/plugin/sand-iam/composer.lock')],
        'typescript' => ['lock_sha256' => hash_file('sha256', $source . '/sdk/typescript/pnpm-lock.yaml')],
    ], JSON_THROW_ON_ERROR));
    echo "Step 1: create committed native Git fixture in Unicode/space/%/! path\n";
    runReleaseTestCommand(['git', 'init', $source]);
    runReleaseTestCommand(['git', '-C', $source, 'add', '.']);
    runReleaseTestCommand(['git', '-C', $source, '-c', 'user.name=Release regression',
        '-c', 'user.email=regression@example.invalid', 'commit', '-m', 'Isolated release fixture']);
    $destination = $fixture . '/产物 artifacts % !';
    echo "Step 2: construct two real ZIP archives from committed bytes\n";
    runReleaseTestCommand([PHP_BINARY, $source . '/tools/build-independent-release.php', $destination]);
    $zip = new ZipArchive();
    if ($zip->open($destination . '/primary/sand-iam-0.7.6.zip') !== true) throw new RuntimeException('Cannot read fixture ZIP');
    if ($zip->getFromName('update.sql') !== $special
        || $zip->getFromName('sdk/中文 文件 % !.bin') !== $binary) {
        throw new RuntimeException('Git/ZIP changed CRLF/BOM/binary/Unicode/punctuation bytes');
    }
    for ($index = 0; $index < $zip->numFiles; $index++) {
        sandIamCanonicalPayloadPath((string) $zip->getNameIndex($index));
    }
    $zip->close();
    echo "PASS: canonical / ZIP paths and exact CRLF/BOM/binary/Unicode bytes\n";
    echo "Step 3: reject unsafe destination and dirty source before writing artifacts\n";
    $rejections = [
        'relative' => 'relative-artifacts',
        'existing' => $destination,
        'inside source' => $source . '/artifacts',
        'missing parent' => $fixture . '/missing-parent/artifacts',
    ];
    if (DIRECTORY_SEPARATOR === '\\') {
        $rejections['drive relative'] = substr($source, 0, 2) . 'relative-artifacts';
        $rejections['current drive rooted'] = '\\relative-artifacts';
    }
    foreach ($rejections as $label => $path) {
        runReleaseTestCommand([PHP_BINARY, $source . '/tools/build-independent-release.php', $path], false);
        echo "PASS: reject {$label}\n";
    }
    file_put_contents($source . '/README.md', "dirty\n");
    $dirtyDestination = $fixture . '/dirty-artifacts';
    runReleaseTestCommand([PHP_BINARY, $source . '/tools/build-independent-release.php', $dirtyDestination], false);
    if (file_exists($dirtyDestination)) throw new RuntimeException('Dirty source created artifacts');
    echo "PASS: reject dirty source without creating destination\n";
} finally {
    removeReleaseFixture($fixture);
    if (file_exists($fixture)) throw new RuntimeException('Temporary fixture cleanup failed');
}
echo 'Success: native ' . PHP_OS_FAMILY . ' regression, fixture cleaned, ' .
    round(microtime(true) - $started, 2) . " seconds\n";
