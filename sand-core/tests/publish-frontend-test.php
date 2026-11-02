<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$publisher = $packageRoot . '/tools/publish-frontend.php';
$root = sys_get_temp_dir() . '/sand-core-publish-' . bin2hex(random_bytes(6));
$first = $root . '/first';
$modified = $root . '/modified';
$conflict = $root . '/conflict';
$installedRoot = $root . '/installed';

/** @return array{0:int,1:string} */
function runPublisher(string $publisher, string $target, string $mode = ''): array
{
    $command = ($mode === '' ? '' : 'SAND_FRONTEND_PUBLISH=' . escapeshellarg($mode) . ' ')
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($publisher) . ' ' . escapeshellarg($target);
    exec($command . ' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

try {
    [$code, $output] = runPublisher($publisher, $first);
    if ($code !== 0 || !is_file($first . '/.sand-core-source-manifest.json')) {
        throw new RuntimeException("首次发布失败：{$output}");
    }

    [$code, $output] = runPublisher($publisher, $first);
    if ($code !== 0 || !str_contains($output, 'published=0')) {
        throw new RuntimeException("幂等发布失败：{$output}");
    }

    [$code, $output] = runPublisher($publisher, $first, 'skip');
    if ($code !== 0 || !str_contains($output, 'skipped')) {
        throw new RuntimeException("SAND_FRONTEND_PUBLISH=skip 未跳过发布：{$output}");
    }

    // 宿主修改清单内文件：跳过并报告，不再中止发布
    [$code, $output] = runPublisher($publisher, $modified);
    if ($code !== 0) {
        throw new RuntimeException("修改场景首次发布失败：{$output}");
    }
    file_put_contents($modified . '/package.json', "\n", FILE_APPEND);
    [$code, $output] = runPublisher($publisher, $modified);
    if ($code !== 0 || !str_contains($output, 'skipped=1')) {
        throw new RuntimeException("宿主修改未被跳过并报告：{$output}");
    }
    if (hash_file('sha256', $modified . '/package.json')
        === hash_file('sha256', $packageRoot . '/sandadmin-artd/package.json')) {
        throw new RuntimeException('宿主修改被覆盖');
    }

    // 非空无清单：内容相同收养，内容不同拒绝并给出恢复命令
    mkdir($conflict, 0777, true);
    copy($packageRoot . '/sandadmin-artd/package.json', $conflict . '/package.json');
    file_put_contents($conflict . '/package.json', "\n", FILE_APPEND);
    [$code, $output] = runPublisher($publisher, $conflict);
    if ($code === 0
        || !str_contains($output, 'package.json')
        || !str_contains($output, 'SAND_FRONTEND_PUBLISH=force')) {
        throw new RuntimeException("内容不同的非空目录未被拒绝：{$output}");
    }
    [$code, $output] = runPublisher($publisher, $conflict, 'force');
    if ($code !== 0 || !str_contains($output, 'forced')) {
        throw new RuntimeException("SAND_FRONTEND_PUBLISH=force 未强制发布：{$output}");
    }

    // 自覆盖守卫：相对路径指向发布源同样拒绝
    [$code, $output] = runPublisher($publisher, $packageRoot . '/tools/../sandadmin-artd');
    if ($code === 0 || !str_contains($output, '不能是发布源目录')) {
        throw new RuntimeException("自覆盖守卫失效：{$output}");
    }

    // 安装形态：Composer 版本写入清单
    $installedPackage = $installedRoot . '/vendor/supdger/sand-core';
    mkdir($installedPackage . '/tools', 0777, true);
    mkdir($installedPackage . '/server', 0777, true);
    mkdir($installedPackage . '/sandadmin-artd', 0777, true);
    copy($publisher, $installedPackage . '/tools/publish-frontend.php');
    copy($packageRoot . '/server/FrontendPublisher.php', $installedPackage . '/server/FrontendPublisher.php');
    copy($packageRoot . '/server/PublishExclusions.php', $installedPackage . '/server/PublishExclusions.php');
    file_put_contents($installedPackage . '/sandadmin-artd/package.json', "{}\n");
    mkdir($installedRoot . '/vendor/composer', 0777, true);
    file_put_contents(
        $installedRoot . '/vendor/composer/InstalledVersions.php',
        "<?php\nnamespace Composer;\nfinal class InstalledVersions { public static function getPrettyVersion(string \$package): ?string { return \$package === 'supdger/sand-core' ? '0.1.2' : null; } }\n"
    );
    file_put_contents(
        $installedRoot . '/vendor/autoload.php',
        "<?php\nspl_autoload_register(static function (string \$class): void { if (\$class === 'Composer\\\\InstalledVersions') { require __DIR__ . '/composer/InstalledVersions.php'; } });\n"
    );
    [$code, $output] = runPublisher(
        $installedPackage . '/tools/publish-frontend.php',
        $installedRoot . '/published'
    );
    $installedManifest = json_decode(
        (string) file_get_contents($installedRoot . '/published/.sand-core-source-manifest.json'),
        true
    );
    if ($code !== 0 || ($installedManifest['version'] ?? null) !== '0.1.2') {
        throw new RuntimeException("安装形态未记录 Composer 版本：{$output}");
    }

    fwrite(STDOUT, "publish frontend tests passed\n");
} finally {
    removeTree($root);
}
