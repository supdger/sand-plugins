<?php

declare(strict_types=1);

/**
 * Independent-repository release construction. Reads committed Git blobs only.
 * This does not install, migrate, sign, upload, or activate frontend assets.
 */
require_once __DIR__ . '/package-payload-policy.php';

function command(array $args): string
{
    $output = [];
    exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException(implode("\n", $output));
    }
    return implode("\n", $output);
}

$root = dirname(__DIR__);
$destination = $argv[1] ?? '';
if ($destination === '' || !str_starts_with($destination, '/')
    || file_exists($destination) || realpath(dirname($destination)) === false) {
    throw new RuntimeException('Provide a new absolute artifact directory with an existing parent');
}
if (str_starts_with(realpath(dirname($destination)) . '/', $root . '/')) {
    throw new RuntimeException('Artifacts must stay outside the source repository');
}
if (command(['git', '-C', $root, 'status', '--porcelain=v1', '--untracked-files=all']) !== '') {
    throw new RuntimeException('Release source must be committed and clean');
}
$commit = command(['git', '-C', $root, 'rev-parse', 'HEAD']);
$tree = command(['git', '-C', $root, 'rev-parse', 'HEAD^{tree}']);
$listed = command(['git', '-C', $root, 'ls-tree', '-r', 'HEAD']);
$entries = [];
foreach (explode("\n", $listed) as $line) {
    if (preg_match('/^([0-9]+) blob ([a-f0-9]+)\t(.+)$/D', $line, $match) !== 1) {
        throw new RuntimeException('Unsupported Git tree entry: ' . $line);
    }
    [$all, $mode, $object, $path] = $match;
    sandIamCanonicalPayloadPath($path);
    if (!in_array($mode, ['100644', '100755'], true)) {
        throw new RuntimeException('Symlinks are forbidden in release source');
    }
    $eligible = false;
    foreach (sandIamPayloadRoots() as $allowed) {
        if ($path === $allowed || str_starts_with($path, $allowed . '/')) {
            $eligible = true;
            break;
        }
    }
    if ($eligible && !sandIamPayloadExcluded($path)) {
        $entries[$path] = $object;
    }
}
ksort($entries, SORT_STRING);
foreach (sandIamPayloadRoots() as $required) {
    if (!isset($entries[$required])
        && !array_filter(array_keys($entries), static fn(string $path): bool => str_starts_with($path, $required . '/'))) {
        throw new RuntimeException('Required committed payload missing: ' . $required);
    }
}
$info = parse_ini_string(command(['git', '-C', $root, 'show', 'HEAD:info.ini']));
$version = $info['version'] ?? '';
if (($info['app'] ?? '') !== 'sand-iam' || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
    throw new RuntimeException('Invalid committed package identity');
}
mkdir($destination, 0700);
$archives = [];
$maps = [];
foreach (['primary', 'repeat'] as $stage) {
    echo "Building {$stage} from committed Git objects\n";
    $directory = $destination . '/' . $stage;
    mkdir($directory, 0700);
    $archive = $directory . '/sand-iam-' . $version . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Cannot create release archive');
    }
    $map = [];
    foreach ($entries as $path => $object) {
        // Binary blobs must be read without line splitting or newline trimming.
        $pipes = [];
        $process = proc_open(['git', '-C', $root, 'cat-file', 'blob', $object],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot read committed blob');
        }
        fclose($pipes[0]);
        $bytes = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $bytes === false) {
            throw new RuntimeException('Cannot read blob: ' . $error);
        }
        $target = $directory . '/source/' . $path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        file_put_contents($target, $bytes);
        $map[$path] = hash('sha256', $bytes);
        if (!$zip->addFromString($path, $bytes)
            || !$zip->setMtimeName($path, 946684800)
            || !$zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0100644 << 16)
            || !$zip->setCompressionName($path, ZipArchive::CM_DEFLATE, 9)) {
            throw new RuntimeException('Cannot write canonical ZIP entry');
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException('Cannot finish ZIP');
    }
    $archives[$stage] = hash_file('sha256', $archive);
    $maps[$stage] = $map;
}
if ($maps['primary'] !== $maps['repeat'] || $archives['primary'] !== $archives['repeat']) {
    throw new RuntimeException('Independent Git-object stages are not identical');
}
$contract = json_decode(file_get_contents($destination . '/primary/source/release-build-contract.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['plugin/sand-iam/vendor', 'sdk/typescript/dist'] as $directory) {
    $generated = [];
    foreach ($maps['primary'] as $path => $hash) {
        if (str_starts_with($path, $directory . '/')) {
            $generated[substr($path, strlen($directory) + 1)] = $hash;
        }
    }
    $expected = $contract['generated_payloads'][$directory] ?? [];
    if (($expected['file_count'] ?? null) !== count($generated)
        || ($expected['tree_sha256'] ?? null) !== hash('sha256', json_encode($generated, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
        throw new RuntimeException('Frozen dependency contract differs from Git blobs: ' . $directory);
    }
}
foreach (['composer' => 'plugin/sand-iam/composer.lock', 'typescript' => 'sdk/typescript/pnpm-lock.yaml'] as $key => $lock) {
    if (($contract[$key]['lock_sha256'] ?? null) !== ($maps['primary'][$lock] ?? null)) {
        throw new RuntimeException('Frozen dependency lock differs from Git blobs: ' . $lock);
    }
}
$zip = new ZipArchive();
$zip->open($destination . '/primary/sand-iam-' . $version . '.zip');
if ($zip->numFiles !== count($entries)) {
    throw new RuntimeException('ZIP count differs from committed payload');
}
foreach ($maps['primary'] as $path => $hash) {
    if (!hash_equals($hash, hash('sha256', (string) $zip->getFromName($path)))) {
        throw new RuntimeException('ZIP entry differs from source: ' . $path);
    }
}
$zip->close();
$manifest = [
    'schema' => 'sand-iam.independent-release-manifest/v1',
    'source_commit' => $commit, 'source_tree' => $tree, 'source_clean' => true,
    'builder_sha256' => hash_file('sha256', __FILE__),
    'payload_policy_sha256' => hash_file('sha256', __DIR__ . '/package-payload-policy.php'),
    'version' => $version, 'sha256' => $archives['primary'],
    'entries' => count($entries), 'repeat_bit_identical' => true,
    'toolchain' => ['php' => PHP_VERSION, 'zip' => phpversion('zip'), 'libzip' => ZipArchive::LIBZIP_VERSION],
    'files' => $maps['primary'],
    'not_performed' => ['signature', 'installation', 'upgrade', 'frontend_activation', 'publication', 'deployment'],
];
file_put_contents($destination . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($destination . '/SHA256SUMS', $archives['primary'] . '  sand-iam-' . $version . ".zip\n");
echo "Success: {$version}, " . count($entries) . " entries; independent builds identical\n";
echo "SHA-256: {$archives['primary']}\n";
