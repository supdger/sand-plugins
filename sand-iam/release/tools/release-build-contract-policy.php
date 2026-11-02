<?php

declare(strict_types=1);

require_once __DIR__ . '/package-payload-policy.php';

/**
 * Verify frozen dependency inputs without rebuilding them. Historical
 * reviewed-runtime contracts and explicit reproduction additionally require
 * the original toolchain; this distinction never relaxes Git source gates.
 */
function sandIamCheckReleaseBuildContract(string $root, bool $reproduceToolchain = false): void
{
    $contract = json_decode((string) file_get_contents($root . '/release-build-contract.json'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($contract) || ($contract['schema'] ?? null) !== 'sand-iam.release-build-contract/v1'
        || !in_array($contract['kind'] ?? null, ['reviewed-runtime-payload-inputs', 'committed-runtime-payload-inputs'], true)
        || ($contract['source']['formal_builder'] ?? null) !== 'git-blob-only'
        || ($contract['source']['repeat_build'] ?? null) !== 'independent-git-stage') {
        throw new RuntimeException('Unsupported frozen/rebuild dependency contract');
    }
    $version = parse_ini_file($root . '/info.ini')['version'] ?? '';
    $composer = $contract['composer'] ?? [];
    $typescript = $contract['typescript'] ?? [];
    $installed = (string) file_get_contents($root . '/plugin/sand-iam/vendor/composer/installed.php');
    if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1
        || ($composer['environment'] ?? null) !== ['COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_ROOT_VERSION' => $version]
        || ($composer['arguments'] ?? null) !== ['install', '--no-dev', '--prefer-dist', '--optimize-autoloader',
            '--classmap-authoritative', '--no-interaction', '--no-plugins', '--no-scripts']
        || substr_count($installed, "'pretty_version' => '{$version}'") !== 2
        || substr_count($installed, "'version' => '{$version}.0'") !== 2
        || ($composer['lock_sha256'] ?? null) !== hash_file('sha256', $root . '/plugin/sand-iam/composer.lock')
        || ($typescript['lock_sha256'] ?? null) !== hash_file('sha256', $root . '/sdk/typescript/pnpm-lock.yaml')
        || ($typescript['package_integrity'] ?? null) !== 'sha512-jl1vZzPDinLr9eUt3J/t7V6FgNEw9QjvBPdysz9KfQDD41fQrC2Y4vKQdiaUpFT4bXlb1RHhLpp8wtm6M5TgSw==') {
        throw new RuntimeException('Frozen dependency identity, version, or lock mismatch');
    }
    $generated = $contract['generated_payloads'] ?? null;
    $directories = ['plugin/sand-iam/vendor', 'sdk/typescript/dist'];
    $keys = is_array($generated) ? array_keys($generated) : [];
    sort($keys, SORT_STRING);
    if ($keys !== $directories) throw new RuntimeException('Generated payload declarations are incomplete or unknown');
    foreach ($directories as $directory) {
        $expected = $generated[$directory];
        $fields = is_array($expected) ? array_keys($expected) : [];
        sort($fields, SORT_STRING);
        if ($fields !== ['file_count', 'tree_sha256'] || !is_int($expected['file_count']) || $expected['file_count'] < 1
            || !is_string($expected['tree_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $expected['tree_sha256']) !== 1) {
            throw new RuntimeException('Malformed generated payload declaration');
        }
        $absolute = $root . '/' . $directory;
        if (!is_dir($absolute) || is_link($absolute)) throw new RuntimeException('Missing generated payload');
        $map = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) throw new RuntimeException('Symlink in generated payload');
            if ($file->isFile()) {
                $relative = sandIamFilesystemRelativePath(substr($file->getPathname(), strlen($absolute) + 1));
                if (sandIamPayloadExcluded($directory . '/' . $relative)) continue;
                if (isset($map[$relative])) throw new RuntimeException('Duplicate frozen dependency path');
                $map[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($map, SORT_STRING);
        if ($expected['file_count'] !== count($map)
            || !hash_equals($expected['tree_sha256'], hash('sha256', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('Frozen dependency bytes differ: ' . $directory);
        }
    }
    $toolchain = $contract['toolchain'] ?? null;
    $required = ['php', 'composer', 'node', 'pnpm', 'typescript', 'zip_extension', 'libzip'];
    $keys = is_array($toolchain) ? array_keys($toolchain) : [];
    sort($keys);
    sort($required);
    if ($keys !== $required) throw new RuntimeException('Missing frozen toolchain provenance');
    foreach ($toolchain as $value) {
        if (!is_string($value) || $value === '' || strpbrk($value, "\r\n") !== false) {
            throw new RuntimeException('Malformed frozen toolchain provenance');
        }
    }
    if (!$reproduceToolchain && $contract['kind'] === 'committed-runtime-payload-inputs') return;
    if ($toolchain['php'] !== PHP_VERSION || $toolchain['zip_extension'] !== phpversion('zip')
        || $toolchain['libzip'] !== ZipArchive::LIBZIP_VERSION) {
        throw new RuntimeException('Rebuild reproduction requires the recorded PHP/zip toolchain');
    }
    $versionCommand = static function (array $arguments): string {
        $output = [];
        exec(implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $status);
        if ($status !== 0) throw new RuntimeException('Cannot read rebuild toolchain version');
        return trim(implode("\n", $output));
    };
    preg_match('/Composer version ([0-9]+\.[0-9]+\.[0-9]+)/', $versionCommand(['composer', '--version']), $composerVersion);
    if ($toolchain['composer'] !== ($composerVersion[1] ?? null) || $toolchain['node'] !== $versionCommand(['node', '--version'])
        || $toolchain['pnpm'] !== $versionCommand(['pnpm', '--version']) || $toolchain['typescript'] !== '5.9.3') {
        throw new RuntimeException('Rebuild reproduction requires the recorded Composer/Node/pnpm toolchain');
    }
}
