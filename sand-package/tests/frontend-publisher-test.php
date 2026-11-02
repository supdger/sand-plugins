<?php

declare(strict_types=1);

use SandAdmin\Package\FrontendPublisher;

$packageRoot = dirname(__DIR__);
require $packageRoot . '/server/PublishExclusions.php';
require $packageRoot . '/server/FrontendPublisher.php';

const CORE_MANIFEST = '.sand-core-source-manifest.json';
const MANIFEST = '.sand-package-source-manifest.json';
const PENDING = '.sand-package-source-manifest.json.pending';

$root = realpath(sys_get_temp_dir()) . '/sand-package-publish-' . bin2hex(random_bytes(6));
$sourceRoot = $packageRoot . '/sandadmin-artd';
$probe = 'src/views/plugin/sandpackage/api/index.ts';

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
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function baseline(string $root, string $name): string
{
    $target = $root . '/' . $name;
    mkdir($target, 0777, true);
    file_put_contents($target . '/' . CORE_MANIFEST, "{\"schema\":1,\"version\":\"0.2.5\",\"files\":{}}\n");

    return $target;
}

function put(string $path, string $content): void
{
    $parent = dirname($path);
    if (!is_dir($parent)) {
        mkdir($parent, 0777, true);
    }
    file_put_contents($path, $content);
}

/** @param array<string,string> $files */
function writeManifestFile(string $path, array $files): void
{
    file_put_contents(
        $path,
        json_encode(['schema' => 1, 'package' => 'supdger/sand-package', 'version' => '0.0.1', 'files' => $files])
    );
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('断言失败：' . $message);
    }
}

/** @return array{0:bool,1:string} */
function expectFailure(callable $callback): array
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return [true, $exception->getMessage()];
    }

    return [false, ''];
}

mkdir($root, 0777, true);

try {
    // 1. core 基线缺失：降级跳过，不抛异常、不写清单。
    $target = $root . '/no-core';
    mkdir($target, 0777, true);
    $report = FrontendPublisher::publish($target);
    assertTrue($report['status'] === 'skipped', 'core 基线缺失应跳过');
    assertTrue(str_contains($report['reason'], CORE_MANIFEST), '跳过原因应点名 core 清单');
    assertTrue(str_contains($report['reason'], 'sand:publish'), '跳过原因应给出恢复命令');
    assertTrue(!is_file($target . '/' . MANIFEST), '跳过时不应写清单');

    // 2. 首次发布 + 幂等重跑。
    $target = baseline($root, 'fresh');
    $report = FrontendPublisher::publish($target);
    assertTrue($report['status'] === 'published', '首次发布应成功');
    assertTrue($report['written'] !== [], '首次发布应写入文件');
    assertTrue(is_file($target . '/' . MANIFEST), '首次发布应生效清单');
    assertTrue(!is_file($target . '/' . PENDING), '发布后不应残留 pending');
    assertTrue(is_file($target . '/' . $probe), '首次发布应落地探针文件');

    $report = FrontendPublisher::publish($target);
    assertTrue($report['status'] === 'published' && $report['written'] === [], '幂等重跑不应重写文件');
    assertTrue(count($report['adopted']) > 0, '幂等重跑应全部命中已发布');

    // 3. 非空无清单且逐字节一致：收养而不是拒绝。
    $target = baseline($root, 'adopt');
    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
        ) as $file
    ) {
        if ($file->isFile()) {
            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($sourceRoot)), '/');
            put($target . '/' . $relative, (string) file_get_contents($file->getPathname()));
        }
    }
    $report = FrontendPublisher::publish($target);
    assertTrue($report['status'] === 'published', '内容一致的非空目录应被收养');
    assertTrue($report['written'] === [], '收养不应重写文件');
    assertTrue(is_file($target . '/' . MANIFEST), '收养应补写清单');

    // 4. 非空无清单且内容不同：拒绝并给出文件与恢复路径。
    $target = baseline($root, 'conflict');
    put($target . '/' . $probe, "// 本地改动\n");
    [$failed, $message] = expectFailure(static fn () => FrontendPublisher::publish($target));
    assertTrue($failed, '内容不同的未受管文件应被拒绝');
    assertTrue(str_contains($message, $probe), '冲突消息应列出文件路径');
    assertTrue(str_contains($message, '内容与发布源不同'), '冲突消息应说明命中的判定');
    assertTrue(str_contains($message, 'SAND_FRONTEND_PUBLISH=force'), '冲突消息应给出开关名');
    assertTrue(str_contains($message, 'sand:publish'), '冲突消息应给出恢复命令');
    assertTrue(!is_file($target . '/' . PENDING), '拒绝时不应留下 pending');

    // 5. force：跳过保护并重建清单。
    putenv('SAND_FRONTEND_PUBLISH=force');
    $report = FrontendPublisher::publish($target);
    putenv('SAND_FRONTEND_PUBLISH');
    assertTrue($report['status'] === 'published', 'force 应强制发布');
    assertTrue(
        hash_file('sha256', $target . '/' . $probe) === hash_file('sha256', $sourceRoot . '/' . $probe),
        'force 应覆盖本地改动'
    );

    // 6. skip：直接跳过。
    putenv('SAND_FRONTEND_PUBLISH=skip');
    $report = FrontendPublisher::publish(baseline($root, 'skip'));
    putenv('SAND_FRONTEND_PUBLISH');
    assertTrue($report['status'] === 'skipped', 'skip 应跳过发布');
    assertTrue(!is_file($root . '/skip/' . MANIFEST), 'skip 不应写清单');

    // 7. 清单内文件被消费者修改：跳过覆盖并汇总，不抛异常。
    $target = baseline($root, 'modified');
    FrontendPublisher::publish($target);
    put($target . '/' . $probe, "// 消费者修改\n");
    $report = FrontendPublisher::publish($target);
    assertTrue($report['status'] === 'published', '清单内修改不应中止发布');
    assertTrue(in_array($probe, $report['skipped'], true), '清单内修改应进入 skipped 报告');
    assertTrue(file_get_contents($target . '/' . $probe) === "// 消费者修改\n", '消费者修改不得被覆盖');

    // 8. 升级删除集：旧清单有、新载荷没有，哈希未变才删除并清理空目录。
    $target = baseline($root, 'obsolete');
    FrontendPublisher::publish($target);
    $installed = json_decode((string) file_get_contents($target . '/' . MANIFEST), true);
    put($target . '/legacy/gone.ts', "export const gone = 1\n");
    put($target . '/legacy/kept.ts', "export const kept = 1\n");
    $installed['files']['legacy/gone.ts'] = hash('sha256', "export const gone = 1\n");
    $installed['files']['legacy/kept.ts'] = hash('sha256', "export const stale = 1\n");
    writeManifestFile($target . '/' . MANIFEST, $installed['files']);
    $report = FrontendPublisher::publish($target);
    assertTrue(in_array('legacy/gone.ts', $report['removed'], true), '未变更的废弃文件应被删除');
    assertTrue(in_array('legacy/kept.ts', $report['retained'], true), '已修改的废弃文件应保留并报告');
    assertTrue(!is_file($target . '/legacy/gone.ts'), '删除集文件应从磁盘移除');
    assertTrue(is_file($target . '/legacy/kept.ts'), '已修改文件必须留在磁盘');

    // 9. 删除集清空目录后，连带易失文件一起清理空目录。
    $target = baseline($root, 'prune');
    FrontendPublisher::publish($target);
    $installed = json_decode((string) file_get_contents($target . '/' . MANIFEST), true);
    put($target . '/legacy/only.ts', "export const only = 1\n");
    put($target . '/legacy/.DS_Store', 'x');
    $installed['files']['legacy/only.ts'] = hash('sha256', "export const only = 1\n");
    writeManifestFile($target . '/' . MANIFEST, $installed['files']);
    FrontendPublisher::publish($target);
    assertTrue(!is_dir($target . '/legacy'), '仅剩易失文件的目录应被清理');

    // 10. 中断重入：copy 完成但清单未生效。
    $target = baseline($root, 'pending-complete');
    FrontendPublisher::publish($target);
    rename($target . '/' . MANIFEST, $target . '/' . PENDING);
    $report = FrontendPublisher::publish($target);
    assertTrue($report['status'] === 'published' && $report['recovered'], 'pending 应被识别为中断现场');
    assertTrue(is_file($target . '/' . MANIFEST) && !is_file($target . '/' . PENDING), 'pending 应被提交为正式清单');

    // 11. 中断重入：copy 只完成一半。
    $target = baseline($root, 'pending-partial');
    FrontendPublisher::publish($target);
    rename($target . '/' . MANIFEST, $target . '/' . PENDING);
    unlink($target . '/' . $probe);
    $report = FrontendPublisher::publish($target);
    assertTrue(in_array($probe, $report['written'], true), '中断缺失的文件应被补齐');
    assertTrue(is_file($target . '/' . MANIFEST), '补齐后清单应生效');

    // 12. 中断重入：pending 记录的是另一版本内容，不得判成消费者修改。
    $target = baseline($root, 'pending-version');
    $pendingFiles = [$probe => hash('sha256', "// 上一版本载荷\n")];
    writeManifestFile($target . '/' . PENDING, $pendingFiles);
    put($target . '/' . $probe, "// 上一版本载荷\n");
    $report = FrontendPublisher::publish($target);
    assertTrue($report['status'] === 'published', '中断版本残留不应被拒绝');
    assertTrue(in_array($probe, $report['written'], true), '中断版本残留应被新载荷覆盖');
    assertTrue(
        hash_file('sha256', $target . '/' . $probe) === hash_file('sha256', $sourceRoot . '/' . $probe),
        '重做后内容应与发布源一致'
    );

    // 13. 目标路径含符号链接：拒绝写入。
    $target = baseline($root, 'symlink');
    mkdir($root . '/elsewhere', 0777, true);
    mkdir($target . '/src/views/plugin/sandpackage', 0777, true);
    symlink($root . '/elsewhere', $target . '/src/views/plugin/sandpackage/api');
    [$failed, $message] = expectFailure(static fn () => FrontendPublisher::publish($target));
    assertTrue($failed, '符号链接路径段应被拒绝');
    assertTrue(str_contains($message, '符号链接'), '拒绝消息应说明符号链接');

    fwrite(STDOUT, "frontend publisher tests passed\n");
} finally {
    putenv('SAND_FRONTEND_PUBLISH');
    removeTree($root);
}
