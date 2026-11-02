<?php

declare(strict_types=1);

use SandAdmin\Core\Install;

/**
 * Install::frontendTarget() 的目标解析与越界防护测试（裸 PHP assert）。
 */

function base_path(string $path = ''): string
{
    return $GLOBALS['sand_test_base_path'] . ($path === '' ? '' : '/' . $path);
}

$repositoryRoot = dirname(__DIR__);
require_once $repositoryRoot . '/server/PublishExclusions.php';
require_once $repositoryRoot . '/server/FrontendPublisher.php';
require_once $repositoryRoot . '/server/Install.php';

$root = sys_get_temp_dir() . '/sand-core-target-' . bin2hex(random_bytes(6));

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
        if ($item->isLink()) {
            unlink($item->getPathname());
            continue;
        }
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function withBasePath(string $basePath, ?string $frontendPath): string
{
    $GLOBALS['sand_test_base_path'] = $basePath;
    if (!is_dir($basePath)) {
        mkdir($basePath, 0777, true);
    }
    $composer = $basePath . '/composer.json';
    if ($frontendPath === null) {
        if (is_file($composer)) {
            unlink($composer);
        }
    } else {
        file_put_contents(
            $composer,
            (string) json_encode(['extra' => ['sandadmin' => ['frontend-path' => $frontendPath]]])
        );
    }

    return Install::frontendTarget();
}

function expectRejection(string $basePath, ?string $frontendPath, string $expected): void
{
    $caught = null;
    try {
        withBasePath($basePath, $frontendPath);
    } catch (RuntimeException $e) {
        $caught = $e->getMessage();
    }
    check($caught !== null, "应拒绝的目标被接受：{$basePath} / " . var_export($frontendPath, true));
    check(str_contains($caught, $expected), "拒绝消息缺少「{$expected}」：{$caught}");
    check(
        str_contains($caught, 'extra.sandadmin.frontend-path'),
        "拒绝消息应给出 extra 配置指引：{$caught}"
    );
}

try {
    mkdir($root . '/project/server', 0777, true);
    mkdir($root . '/plain/host', 0777, true);

    // 1. 缺省启发式：目录名为 server 时取同级
    $target = withBasePath($root . '/project/server', null);
    check($target === $root . '/project/sandadmin-artd', '启发式应取同级 sandadmin-artd，实际 ' . $target);

    // 2. 缺省启发式：其他目录名取自身下级
    $target = withBasePath($root . '/plain/host', null);
    check($target === $root . '/plain/host/sandadmin-artd', '启发式应取下级 sandadmin-artd，实际 ' . $target);

    // 3. extra 显式声明优先（相对宿主 base_path 解析并展开 ..）
    $target = withBasePath($root . '/project/server', '../sandadmin-artd');
    check($target === $root . '/project/sandadmin-artd', 'extra 相对路径解析错误：' . $target);

    $target = withBasePath($root . '/project/server', 'frontend/artd');
    check($target === $root . '/project/server/frontend/artd', 'extra 子目录解析错误：' . $target);

    // 4. 越出宿主项目父级：拒绝
    expectRejection($root . '/project/server', '../../escape/artd', '越出宿主项目父级');
    expectRejection($root . '/project/server', '/tmp/sand-core-outside-artd', '越出宿主项目父级');

    // 5. 指向宿主项目自身或父级：拒绝
    expectRejection($root . '/project/server', '.', '前端目标目录无效');
    expectRejection($root . '/project/server', '..', '前端目标目录无效');

    // 6. 符号链接路径：拒绝
    mkdir($root . '/project/real-artd', 0777, true);
    symlink($root . '/project/real-artd', $root . '/project/linked-artd');
    expectRejection($root . '/project/server', '../linked-artd', '符号链接');

    fwrite(STDOUT, 'frontend target tests passed' . PHP_EOL);
} finally {
    removeTree($root);
}
