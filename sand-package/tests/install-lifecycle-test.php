<?php

declare(strict_types=1);

use SandAdmin\Package\Install;

$packageRoot = dirname(__DIR__);

const PAYLOAD_MANIFEST = '.sand-package-payload-manifest.json';
const CORE_MANIFEST = '.sand-core-source-manifest.json';
const PAYLOAD_PROBE = 'app/functions.php';
const CONFIG_PROBE = 'config/app.php';
const FRONTEND_PROBE = 'src/views/plugin/sandpackage/api/index.ts';

function base_path(string $path = ''): string
{
    $base = (string) ($GLOBALS['__sand_base'] ?? '');

    return $path === '' ? $base : $base . '/' . $path;
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
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function put(string $path, string $content): void
{
    $parent = dirname($path);
    if (!is_dir($parent)) {
        mkdir($parent, 0777, true);
    }
    file_put_contents($path, $content);
}

function hostComposer(string $base, ?string $frontendPath): void
{
    $data = ['name' => 'sandadmin/host'];
    if ($frontendPath !== null) {
        $data['extra'] = ['sandadmin' => ['frontend-path' => $frontendPath]];
    }
    put($base . '/composer.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function frontendBaseline(string $target): void
{
    mkdir($target, 0777, true);
    file_put_contents($target . '/' . CORE_MANIFEST, "{\"schema\":1,\"version\":\"0.2.5\",\"files\":{}}\n");
}

// ---------------------------------------------------------------- child mode

$case = null;
$root = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--case=')) {
        $case = substr($argument, 7);
    }
    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, 7);
    }
}

if ($case !== null && $root !== null) {
    require $packageRoot . '/server/PublishExclusions.php';
    require $packageRoot . '/server/FrontendPublisher.php';
    require $packageRoot . '/server/Install.php';

    $base = $root . '/server';
    mkdir($base, 0777, true);
    $GLOBALS['__sand_base'] = $base;
    $payload = $base . '/plugin/sandpackage';

    switch ($case) {
        case 'frontend-target-heuristic':
            fwrite(STDOUT, Install::frontendTarget());
            break;

        case 'frontend-target-extra':
            mkdir($root . '/web', 0777, true);
            hostComposer($base, '../web');
            fwrite(STDOUT, Install::frontendTarget());
            break;

        case 'frontend-target-outside':
            hostComposer($base, '../../outside');
            fwrite(STDOUT, Install::frontendTarget());
            break;

        case 'frontend-target-symlink':
            mkdir($root . '/real/artd', 0777, true);
            symlink($root . '/real', $root . '/link');
            hostComposer($base, '../link/artd');
            fwrite(STDOUT, Install::frontendTarget());
            break;

        case 'install-fresh':
            Install::install();
            fwrite(STDOUT, implode("\n", [
                'payload=' . (is_file($payload . '/' . PAYLOAD_PROBE) ? '1' : '0'),
                'manifest=' . (is_file($payload . '/' . PAYLOAD_MANIFEST) ? '1' : '0'),
                'frontend=' . (is_dir($root . '/sandadmin-artd') ? '1' : '0'),
            ]));
            break;

        case 'install-frontend-degraded':
            frontendBaseline($root . '/sandadmin-artd');
            put($root . '/sandadmin-artd/' . FRONTEND_PROBE, "// 本地改动\n");
            Install::install();
            fwrite(STDOUT, implode("\n", [
                'payload=' . (is_file($payload . '/' . PAYLOAD_PROBE) ? '1' : '0'),
                'manifest=' . (is_file($payload . '/' . PAYLOAD_MANIFEST) ? '1' : '0'),
                'frontend_kept=' . (file_get_contents($root . '/sandadmin-artd/' . FRONTEND_PROBE) === "// 本地改动\n" ? '1' : '0'),
            ]));
            break;

        case 'update-boundaries':
            Install::install();
            put($payload . '/' . CONFIG_PROBE, "<?php\nreturn ['custom' => true];\n");
            put($payload . '/' . PAYLOAD_PROBE, "<?php\n// 本地改动\n");
            put($payload . '/config/custom.php', "<?php\nreturn [];\n");
            Install::update();
            fwrite(STDOUT, implode("\n", [
                'config_kept=' . (file_get_contents($payload . '/' . CONFIG_PROBE) === "<?php\nreturn ['custom' => true];\n" ? '1' : '0'),
                'config_extra_kept=' . (is_file($payload . '/config/custom.php') ? '1' : '0'),
                'code_restored=' . (hash_file('sha256', $payload . '/' . PAYLOAD_PROBE)
                    === hash_file('sha256', $packageRoot . '/server/plugin/sandpackage/' . PAYLOAD_PROBE) ? '1' : '0'),
            ]));
            break;

        case 'update-removes-obsolete':
            Install::install();
            $manifest = json_decode((string) file_get_contents($payload . '/' . PAYLOAD_MANIFEST), true);
            put($payload . '/app/legacy/gone.php', "<?php\n");
            put($payload . '/app/legacy/kept.php', "<?php\n// 本地改动\n");
            $manifest['files']['app/legacy/gone.php'] = hash('sha256', "<?php\n");
            $manifest['files']['app/legacy/kept.php'] = hash('sha256', "<?php\n");
            put($payload . '/' . PAYLOAD_MANIFEST, (string) json_encode($manifest));
            Install::update();
            fwrite(STDOUT, implode("\n", [
                'gone=' . (is_file($payload . '/app/legacy/gone.php') ? '1' : '0'),
                'kept=' . (is_file($payload . '/app/legacy/kept.php') ? '1' : '0'),
            ]));
            break;

        case 'uninstall-manifest-driven':
            Install::install();
            put($payload . '/app/operator-note.php', "<?php\n// 运维放置\n");
            put($payload . '/' . CONFIG_PROBE, "<?php\nreturn ['custom' => true];\n");
            Install::uninstall();
            fwrite(STDOUT, implode("\n", [
                'unknown_kept=' . (is_file($payload . '/app/operator-note.php') ? '1' : '0'),
                'modified_kept=' . (is_file($payload . '/' . CONFIG_PROBE) ? '1' : '0'),
                'registered_removed=' . (is_file($payload . '/' . PAYLOAD_PROBE) ? '0' : '1'),
                'manifest_removed=' . (is_file($payload . '/' . PAYLOAD_MANIFEST) ? '0' : '1'),
            ]));
            break;

        case 'uninstall-clean':
            Install::install();
            Install::uninstall();
            fwrite(STDOUT, 'payload_dir=' . (is_dir($payload) ? '1' : '0'));
            break;

        default:
            fwrite(STDERR, '未知用例：' . $case);
            exit(2);
    }

    exit(0);
}

// --------------------------------------------------------------- parent mode

$root = realpath(sys_get_temp_dir()) . '/sand-package-install-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
$failures = [];

/** @return array{code:int,out:string,err:string,root:string} */
function runCase(string $script, string $root, string $case): array
{
    $caseRoot = $root . '/' . $case;
    mkdir($caseRoot, 0777, true);
    $errorFile = $root . '/' . $case . '.err';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg('--case=' . $case) . ' ' . escapeshellarg('--root=' . $caseRoot)
        . ' 2>' . escapeshellarg($errorFile);
    $output = [];
    $code = 0;
    exec($command, $output, $code);

    return [
        'code' => $code,
        'out' => implode("\n", $output),
        'err' => (string) file_get_contents($errorFile),
        'root' => $caseRoot,
    ];
}

function check(array &$failures, bool $condition, string $message, string $context = ''): void
{
    if (!$condition) {
        $failures[] = $message . ($context === '' ? '' : PHP_EOL . $context);
    }
}

try {
    $script = __FILE__;

    $result = runCase($script, $root, 'frontend-target-heuristic');
    check($failures, $result['code'] === 0, '启发式目标解析应成功', $result['err']);
    check(
        $failures,
        $result['out'] === $result['root'] . '/sandadmin-artd',
        'server 同级启发式应落在宿主父级',
        $result['out']
    );

    $result = runCase($script, $root, 'frontend-target-extra');
    check($failures, $result['code'] === 0, 'extra 目标解析应成功', $result['err']);
    check(
        $failures,
        $result['out'] === $result['root'] . '/web',
        'extra.sandadmin.frontend-path 应优先于启发式',
        $result['out']
    );

    $result = runCase($script, $root, 'frontend-target-outside');
    check($failures, $result['code'] !== 0, '越界目标应被拒绝', $result['out']);
    check($failures, str_contains($result['err'], '越界'), '越界错误应说明判定', $result['err']);
    check(
        $failures,
        str_contains($result['err'], 'extra.sandadmin.frontend-path'),
        '越界错误应给出配置指引',
        $result['err']
    );

    $result = runCase($script, $root, 'frontend-target-symlink');
    check($failures, $result['code'] !== 0, '符号链接目标应被拒绝', $result['out']);
    check($failures, str_contains($result['err'], '符号链接'), '符号链接错误应说明判定', $result['err']);

    $result = runCase($script, $root, 'install-fresh');
    check($failures, $result['code'] === 0, '首装应成功', $result['err']);
    check($failures, str_contains($result['out'], 'payload=1'), '首装应落地后端载荷', $result['out']);
    check($failures, str_contains($result['out'], 'manifest=1'), '首装应写入载荷安装记录', $result['out']);
    check($failures, str_contains($result['out'], 'frontend=0'), 'core 基线缺失时不应创建前端目录', $result['out']);
    check($failures, str_contains($result['err'], 'Sand Core 前端基线未就绪'), '应告警 core 基线未就绪', $result['err']);
    check($failures, str_contains($result['err'], 'sand:publish'), '告警应带恢复命令', $result['err']);

    $result = runCase($script, $root, 'install-frontend-degraded');
    check($failures, $result['code'] === 0, '前端冲突不得中止安装', $result['err']);
    check($failures, str_contains($result['out'], 'payload=1'), '前端冲突时后端载荷仍应完整', $result['out']);
    check($failures, str_contains($result['out'], 'frontend_kept=1'), '前端本地改动必须保留', $result['out']);
    check($failures, str_contains($result['err'], '前端发布未完成'), '应输出前端失败原因', $result['err']);
    check($failures, str_contains($result['err'], FRONTEND_PROBE), '失败消息应列出冲突文件', $result['err']);
    check($failures, str_contains($result['err'], 'sand:publish'), '失败消息应带恢复命令', $result['err']);

    $result = runCase($script, $root, 'update-boundaries');
    check($failures, $result['code'] === 0, '更新应成功', $result['err']);
    check($failures, str_contains($result['out'], 'config_kept=1'), 'config/ 定制不得被更新覆盖', $result['out']);
    check($failures, str_contains($result['out'], 'config_extra_kept=1'), 'config/ 新增文件应保留', $result['out']);
    check($failures, str_contains($result['out'], 'code_restored=1'), '非 config 载荷应按新版本对齐', $result['out']);
    check($failures, str_contains($result['err'], PAYLOAD_PROBE), '覆盖本地修改应告警并点名文件', $result['err']);

    $result = runCase($script, $root, 'update-removes-obsolete');
    check($failures, $result['code'] === 0, '废弃文件清理应成功', $result['err']);
    check($failures, str_contains($result['out'], 'gone=0'), '未变更的废弃载荷文件应被删除', $result['out']);
    check($failures, str_contains($result['out'], 'kept=1'), '已修改的废弃载荷文件应保留', $result['out']);

    $result = runCase($script, $root, 'uninstall-manifest-driven');
    check($failures, $result['code'] === 0, '卸载应成功', $result['err']);
    check($failures, str_contains($result['out'], 'unknown_kept=1'), '卸载必须保留未登记文件', $result['out']);
    check($failures, str_contains($result['out'], 'modified_kept=1'), '卸载必须保留已修改文件', $result['out']);
    check($failures, str_contains($result['out'], 'registered_removed=1'), '卸载应移除登记文件', $result['out']);
    check($failures, str_contains($result['out'], 'manifest_removed=1'), '卸载应移除安装记录', $result['out']);
    check($failures, str_contains($result['err'], '已保留'), '卸载应报告保留内容', $result['err']);

    $result = runCase($script, $root, 'uninstall-clean');
    check($failures, $result['code'] === 0, '无改动卸载应成功', $result['err']);
    check($failures, str_contains($result['out'], 'payload_dir=0'), '无改动卸载应清空载荷目录', $result['out']);

    if ($failures !== []) {
        throw new RuntimeException('断言失败：' . PHP_EOL . '  - ' . implode(PHP_EOL . '  - ', $failures));
    }
    fwrite(STDOUT, "install lifecycle tests passed\n");
} finally {
    removeTree($root);
}
