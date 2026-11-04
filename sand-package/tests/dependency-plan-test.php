<?php

declare(strict_types=1);

use plugin\sandpackage\app\logic\InstallLogic;

/*
 * 只读依赖计划（inspectDependencies）测试。裸 PHP 脚本，包根执行，退出码非 0 即失败：
 *
 *   php tests/dependency-plan-test.php
 *
 * 覆盖：无依赖 / 已满足 / 已安装版本不足走仓库最小满足版本 / 未安装（内嵌工件
 * 与仓库两条路径）/ 待安装候选满足与不满足 / 依赖等待续跑 / 异常状态 blocked /
 * 仓库不可用降级 / restart 标志解析；并断言全过程只读（info.ini 不变、不产生锁）。
 */

$packageRoot = dirname(__DIR__);
$hostRoot = sys_get_temp_dir() . '/sandpackage-plan-' . bin2hex(random_bytes(6));

// ---- 宿主环境桩：InstallLogic 只经全局助手定位目录与版本 --------------------
$GLOBALS['__plan_host'] = $hostRoot;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return rtrim($GLOBALS['__plan_host'] . '/server' . ($path === '' ? '' : '/' . $path), '/');
    }
}
if (!function_exists('runtime_path')) {
    function runtime_path(string $path = ''): string
    {
        return rtrim($GLOBALS['__plan_host'] . '/server/runtime' . ($path === '' ? '' : '/' . $path), '/');
    }
}
if (!function_exists('config')) {
    function config(string $key, $default = null)
    {
        return $key === 'plugin.sandadmin.app.version' ? '0.2.1' : $default;
    }
}
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        return $default;
    }
}
if (!class_exists(\plugin\sandadmin\exception\ApiException::class)) {
    eval('namespace plugin\sandadmin\exception; class ApiException extends \Exception {}');
}

foreach ([
    '/server/plugin/sandpackage/app/service/HostPayloadPlan.php',
    '/server/plugin/sandpackage/app/service/PluginStorage.php',
    '/server/plugin/sandpackage/app/service/HostPayloadLifecycleJournal.php',
    '/server/plugin/sandpackage/app/service/HostPayloadManifest.php',
    '/server/plugin/sandpackage/app/service/HostPayloadOwnership.php',
    '/server/plugin/sandpackage/app/service/AbnormalPluginCleanup.php',
    '/server/plugin/sandpackage/app/service/PluginDependencyPolicy.php',
    '/server/compat/Saithink/Saipackage/service/Filesystem.php',
    '/server/plugin/sandpackage/app/logic/InstallLogic.php',
] as $file) {
    require $packageRoot . $file;
}

$failures = [];

function check(array &$failures, bool $condition, string $message): void
{
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL {$message}\n");
    } else {
        fwrite(STDOUT, "ok   {$message}\n");
    }
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
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $content);
}

function registryRoot(): string
{
    // PluginStorage::newRoot()，无旧根数据时即生效根。
    return base_path('storage/sandpackage');
}

function writeCandidate(string $app, array $info, array $config): void
{
    $dir = registryRoot() . '/' . $app;
    $info = array_merge(['app' => $app, 'title' => $app, 'version' => '0.0.0',
        'state' => 2, 'lifecycle_driver' => 'saipackage-pg-v1'], $info);
    $lines = [];
    foreach ($info as $key => $value) {
        $lines[] = is_int($value) ? "{$key} = {$value}" : "{$key} = \"{$value}\"";
    }
    put($dir . '/info.ini', implode("\n", $lines) . "\n");
    put($dir . '/config.json', json_encode($config, JSON_UNESCAPED_SLASHES));
}

/** 已安装插件：登记 state=1 + 运行目录存在（hostPayloadDeployed 无清单即真）。 */
function writeInstalled(string $app, string $version): void
{
    writeCandidate($app, ['version' => $version, 'state' => 1], ['schema' => 1]);
    if (!is_dir(base_path('plugin/' . $app))) mkdir(base_path('plugin/' . $app), 0777, true);
}

function depConfig(string $dep, string $version, string $sha): array
{
    return ['schema' => 1, 'plugin_dependencies' => [$dep => ['version' => $version, 'sha256' => $sha]]];
}

function catalogPlugin(string $app, array $versions): array
{
    $releases = [];
    foreach ($versions as $version) {
        $releases[] = ['version' => $version, 'tag' => $version, 'asset' => "{$app}-{$version}.zip",
            'sha256' => str_repeat(substr(md5($app . $version), 0, 1), 64), 'host_min' => '0.1.0'];
    }
    return ['app' => $app, 'repository' => 'supdger/' . $app, 'title' => ucfirst($app), 'versions' => $releases];
}

try {
    mkdir(registryRoot(), 0777, true);

    // ---- 1. 无依赖声明 → 空计划 ------------------------------------------------
    writeCandidate('sand-ai', ['version' => '0.3.5'], ['schema' => 1]);
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies([]);
    check($failures, $plan['app'] === 'sand-ai' && $plan['dependencies'] === []
        && $plan['resolvable'] === true && $plan['reason'] === '', '无依赖声明返回空计划');

    // ---- 2. 依赖已满足 → satisfied ---------------------------------------------
    writeInstalled('sand-iam', '0.8.6');
    writeCandidate('sand-ai', ['version' => '0.3.5'], depConfig('sand-iam', '0.8.6', str_repeat('a', 64)));
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies([]);
    check($failures, ($plan['dependencies'][0]['action'] ?? null) === 'satisfied'
        && $plan['dependencies'][0]['installed_version'] === '0.8.6'
        && $plan['resolvable'] === true, '依赖已满足 → satisfied');

    // ---- 3. 已安装版本不足 → upgrade + 最小满足版本 -----------------------------
    writeInstalled('sand-iam', '0.8.4');
    $catalog = [catalogPlugin('sand-iam', ['0.8.6', '0.8.5', '0.8.4', '0.9.0'])];
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies($catalog);
    $dep = $plan['dependencies'][0];
    check($failures, $dep['action'] === 'upgrade' && $dep['market']['version'] === '0.8.6',
        '已安装 0.8.4 < 0.8.6 → upgrade 选最小满足版本 0.8.6（非最新 0.9.0）');
    check($failures, $dep['market']['sha256'] === str_repeat(substr(md5('sand-iam0.8.6'), 0, 1), 64),
        'market 携带所选版本的 sha256');
    check($failures, $plan['resolvable'] === true, '可消解计划 resolvable=true');

    // ---- 3b. 目标 app 断在依赖等待（state=4+等待标志）时计划端点仍可读 -----------
    // 一键续做的入口：目标候选已登记 state=4，行级续跑先重算依赖计划——
    // 端点只读候选 config.json，不受目标自身登记状态影响。
    writeCandidate('sand-ai', ['version' => '0.3.5', 'state' => 4,
        'composer_dependent_wait_install' => 1], depConfig('sand-iam', '0.8.6', str_repeat('a', 64)));
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies($catalog);
    $dep = $plan['dependencies'][0];
    check($failures, $dep['action'] === 'upgrade' && $dep['market']['version'] === '0.8.6',
        '目标 app 处于 state4+依赖等待时 inspectDependencies 仍返回正确计划（upgrade+market）');

    // ---- 4. 未安装 + 仓库命中 → install -----------------------------------------
    removeTree(registryRoot() . '/sand-iam');
    removeTree(base_path('plugin/sand-iam'));
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies($catalog);
    $dep = $plan['dependencies'][0];
    check($failures, $dep['action'] === 'install' && $dep['market']['version'] === '0.8.6',
        '依赖未安装 → install + 市场最小满足版本');
    check($failures, $dep['installed_state'] === 0 && $dep['installed_version'] === null,
        '未安装依赖报告 state=0 无版本');

    // ---- 5. 未安装 + 内嵌工件匹配 → bundled（由安装流程处理）----------------------
    $bundle = registryRoot() . '/sand-ai/dependencies/sand-iam-0.8.6.zip';
    put($bundle, 'fake-zip');
    $sha = hash_file('sha256', $bundle);
    writeCandidate('sand-ai', ['version' => '0.3.5'], depConfig('sand-iam', '0.8.6', $sha));
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies([]);
    check($failures, ($plan['dependencies'][0]['action'] ?? null) === 'bundled',
        '未安装依赖 + 内嵌工件匹配 → bundled');

    // ---- 6. 未安装 + 仓库无满足版本 → unavailable，可消解=false ------------------
    unlink($bundle);
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies(
        [catalogPlugin('sand-iam', ['0.8.5', '0.8.4'])]
    );
    $dep = $plan['dependencies'][0];
    check($failures, $dep['action'] === 'unavailable' && $dep['market'] === null
        && str_contains($dep['reason'], '暂无满足条件的版本')
        && $plan['resolvable'] === false && $plan['reason'] === $dep['reason'],
        '仓库无满足版本 → unavailable + resolvable=false');

    // ---- 7. 仓库拉取失败（null）→ 逐项 unavailable 降级，不整计划报错 --------------
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies(null, '插件仓库读取失败，请检查网络');
    $dep = $plan['dependencies'][0];
    check($failures, $dep['action'] === 'unavailable' && $dep['reason'] === '插件仓库读取失败，请检查网络',
        '仓库不可用 → 单 dep unavailable 降级并携带通俗原因');

    // ---- 8. 待安装候选满足最低版本 → install 无 market（复用已暂存候选）------------
    writeCandidate('sand-iam', ['version' => '0.8.6'], ['schema' => 1]);
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies($catalog);
    $dep = $plan['dependencies'][0];
    check($failures, $dep['action'] === 'install' && $dep['market'] === null,
        '待安装候选 0.8.6 满足 → install + market=null');

    // ---- 8b. 已暂存升级候选满足 → upgrade（需确认串，前端按候选行生成）-------------
    writeCandidate('sand-iam', ['version' => '0.8.6', 'update' => 1, 'upgrade_from_version' => '0.8.4'], ['schema' => 1]);
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies($catalog);
    $dep = $plan['dependencies'][0];
    check($failures, $dep['action'] === 'upgrade' && $dep['market'] === null,
        '已暂存升级候选满足 → upgrade + market=null（前端按候选行生成确认串）');

    // ---- 9. 待安装候选版本不足 → blocked -----------------------------------------
    writeCandidate('sand-iam', ['version' => '0.8.5'], ['schema' => 1]);
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies($catalog);
    $dep = $plan['dependencies'][0];
    check($failures, $dep['action'] === 'blocked' && str_contains($dep['reason'], '待安装候选'),
        '待安装候选版本不足 → blocked 指路人工处理');

    // ---- 10. 依赖等待状态 → resume ------------------------------------------------
    writeCandidate('sand-iam', ['version' => '0.8.6', 'state' => 4, 'composer_dependent_wait_install' => 1], ['schema' => 1]);
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies($catalog);
    check($failures, ($plan['dependencies'][0]['action'] ?? null) === 'resume',
        'state=4+依赖等待标志 → resume');

    // ---- 11. 依赖安装失败（state=8）→ blocked --------------------------------------
    writeCandidate('sand-iam', ['version' => '0.8.6', 'state' => 8, 'operation_pending' => 1], ['schema' => 1]);
    $plan = (new InstallLogic('sand-ai'))->inspectDependencies($catalog);
    $dep = $plan['dependencies'][0];
    check($failures, $dep['action'] === 'blocked' && str_contains($dep['reason'], '人工处理'),
        '依赖失败现场 → blocked 指路人工恢复');

    // ---- 12. 只读断言：info.ini 不变、不产生锁文件 ---------------------------------
    $before = (string) file_get_contents(registryRoot() . '/sand-ai/info.ini');
    (new InstallLogic('sand-ai'))->inspectDependencies($catalog);
    check($failures, file_get_contents(registryRoot() . '/sand-ai/info.ini') === $before,
        'inspectDependencies 不修改安装登记');
    check($failures, glob(registryRoot() . '/locks/*.lock') === false
        || glob(registryRoot() . '/locks/*.lock') === [], 'inspectDependencies 不产生锁文件');

    // ---- 13. 缺失 config.json → 业务错误 -------------------------------------------
    writeCandidate('lonely-plugin', ['version' => '1.0.0'], ['schema' => 1]);
    unlink(registryRoot() . '/lonely-plugin/config.json');
    $threw = false;
    try {
        (new InstallLogic('lonely-plugin'))->inspectDependencies([]);
    } catch (\plugin\sandadmin\exception\ApiException $error) {
        $threw = str_contains($error->getMessage(), '依赖配置不可读取');
    }
    check($failures, $threw, '缺失依赖配置文件 → 通俗业务错误');

    // ---- 14. restart 标志解析：缺省重载，0/false 抑制 --------------------------------
    check($failures, InstallLogic::restartRequested(null) === true
        && InstallLogic::restartRequested('1') === true
        && InstallLogic::restartRequested(true) === true
        && InstallLogic::restartRequested('0') === false
        && InstallLogic::restartRequested(0) === false
        && InstallLogic::restartRequested(false) === false
        && InstallLogic::restartRequested('false') === false,
        'restart 标志：缺省重载，0/false 抑制重载');
} finally {
    removeTree($hostRoot);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " 项失败\n");
    exit(1);
}
fwrite(STDOUT, "dependency-plan-test 全部通过\n");
