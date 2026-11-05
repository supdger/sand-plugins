<?php

declare(strict_types=1);

use plugin\sandpackage\app\logic\InstallLogic;

/*
 * 卸载依赖方检查（inspectUninstall / uninstall 前置拦截）测试。裸 PHP 脚本，
 * 包根执行，退出码非 0 即失败：
 *
 *   php tests/uninstall-dependents-test.php
 *
 * 覆盖：已安装且声明依赖的插件 → 依赖方列出 + uninstall() 拒绝且登记不变；
 * 依赖方未安装/仅候选/声明其它依赖/配置缺失或损坏 → 不拦截；inspectUninstall
 * 全程只读（info.ini 不变、不产生锁）。
 */

$packageRoot = dirname(__DIR__);
$hostRoot = sys_get_temp_dir() . '/sandpackage-undep-' . bin2hex(random_bytes(6));

// ---- 宿主环境桩：InstallLogic 只经全局助手定位目录与版本 --------------------
$GLOBALS['__undep_host'] = $hostRoot;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return rtrim($GLOBALS['__undep_host'] . '/server' . ($path === '' ? '' : '/' . $path), '/');
    }
}
if (!function_exists('runtime_path')) {
    function runtime_path(string $path = ''): string
    {
        return rtrim($GLOBALS['__undep_host'] . '/server/runtime' . ($path === '' ? '' : '/' . $path), '/');
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
if (!class_exists(\plugin\sandadmin\app\cache\UserMenuCache::class)) {
    eval('namespace plugin\sandadmin\app\cache; class UserMenuCache { public static function clearMenuCache() { return true; } }');
}

foreach ([
    '/server/plugin/sandpackage/app/service/HostPayloadPlan.php',
    '/server/plugin/sandpackage/app/service/PluginStorage.php',
    '/server/plugin/sandpackage/app/service/HostPayloadLifecycleJournal.php',
    '/server/plugin/sandpackage/app/service/HostPayloadManifest.php',
    '/server/plugin/sandpackage/app/service/HostPayloadOwnership.php',
    '/server/plugin/sandpackage/app/service/AbnormalPluginCleanup.php',
    '/server/plugin/sandpackage/app/service/PluginDependencyPolicy.php',
    '/server/plugin/sandpackage/app/service/HostPayloadChangeFiles.php',
    '/server/plugin/sandpackage/app/service/HostPayloadRemovalFiles.php',
    '/server/plugin/sandpackage/app/service/HostPayloadUninstallFinalization.php',
    '/server/plugin/sandpackage/app/service/HostPayloadDependencyChange.php',
    '/server/plugin/sandpackage/app/service/LifecycleFailureLog.php',
    '/server/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php',
    '/server/plugin/sandpackage/app/exception/OperationLockException.php',
    '/server/compat/Saithink/Saipackage/service/Filesystem.php',
    '/server/compat/Saithink/Saipackage/service/Server.php',
    '/server/plugin/sandpackage/app/logic/InstallLogic.php',
] as $file) {
    if (!is_file($packageRoot . $file)) {
        fwrite(STDERR, "缺少测试依赖文件 {$file}\n");
        exit(1);
    }
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

/** 已安装插件：登记 state=1 + 运行目录 + 运行版本文件（uninstall 的版本核对需要）。 */
function writeInstalled(string $app, string $version, string $title = ''): void
{
    writeCandidate($app, ['version' => $version, 'state' => 1,
        'title' => $title !== '' ? $title : $app], ['schema' => 1]);
    if (!is_dir(base_path('plugin/' . $app))) mkdir(base_path('plugin/' . $app), 0777, true);
    put(base_path('plugin/' . $app . '/config/app.php'), "<?php return ['version' => '{$version}'];\n");
}

function depConfig(string $dep, string $version, string $sha): array
{
    return ['schema' => 1, 'plugin_dependencies' => [$dep => ['version' => $version, 'sha256' => $sha]]];
}

try {
    mkdir(registryRoot(), 0777, true);

    // ---- 1. 已安装依赖方声明依赖 → inspectUninstall 列出 ------------------------
    writeInstalled('sand-iam', '0.9.5', 'SandIAM 身份与访问控制');
    writeInstalled('sand-ai', '0.4.8', 'SandAI AI 能力底座');
    put(registryRoot() . '/sand-ai/config.json',
        (string) json_encode(depConfig('sand-iam', '0.8.6', str_repeat('a', 64)), JSON_UNESCAPED_SLASHES));
    $preview = (new InstallLogic('sand-iam'))->inspectUninstall();
    check($failures, $preview['app'] === 'sand-iam' && $preview['title'] === 'SandIAM 身份与访问控制'
        && $preview['version'] === '0.9.5', 'inspectUninstall 返回目标标题与版本');
    check($failures, count($preview['dependents']) === 1
        && $preview['dependents'][0]['app'] === 'sand-ai'
        && $preview['dependents'][0]['title'] === 'SandAI AI 能力底座'
        && $preview['dependents'][0]['version'] === '0.4.8',
        '已安装且声明依赖的插件列入 dependents（含通俗标题）');

    // ---- 1b. inspectUninstall 全程只读：不写登记、不产生锁 ------------------------
    $infoBefore = (string) file_get_contents(registryRoot() . '/sand-iam/info.ini');
    check($failures, (string) file_get_contents(registryRoot() . '/sand-iam/info.ini') === $infoBefore,
        'inspectUninstall 不修改安装登记');
    check($failures, glob(registryRoot() . '/locks/*.lock') === false
        || glob(registryRoot() . '/locks/*.lock') === [], 'inspectUninstall 不产生锁文件');

    // ---- 2. uninstall() 在写锁下拒绝且登记/目录不变 ------------------------------
    $threw = false;
    $message = '';
    try {
        (new InstallLogic('sand-iam'))->uninstall();
    } catch (\plugin\sandadmin\exception\ApiException $error) {
        $threw = true;
        $message = $error->getMessage();
    }
    check($failures, $threw && str_contains($message, '仍被以下已安装插件依赖')
        && str_contains($message, 'sand-ai') && str_contains($message, 'SandAI AI 能力底座'),
        '存在已安装依赖方时 uninstall() 拒绝并列出依赖方');
    check($failures, (string) file_get_contents(registryRoot() . '/sand-iam/info.ini') === $infoBefore
        && is_dir(registryRoot() . '/sand-iam') && is_dir(base_path('plugin/sand-iam')),
        '拒绝卸载不改动登记与目录');

    // ---- 3. 拒绝路径释放操作锁：锁文件可重新 flock -------------------------------
    $locksReleased = true;
    foreach (glob(registryRoot() . '/locks/*.lock') ?: [] as $lockFile) {
        $handle = @fopen($lockFile, 'r');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            $locksReleased = false;
        }
        if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
    }
    check($failures, $locksReleased, 'uninstall() 拒绝后操作锁已释放');

    // ---- 4. 依赖方仅是待安装候选（state=2）→ 不拦截 -------------------------------
    writeCandidate('sand-ai', ['version' => '0.4.8', 'state' => 2, 'title' => 'SandAI AI 能力底座'],
        depConfig('sand-iam', '0.8.6', str_repeat('a', 64)));
    $preview = (new InstallLogic('sand-iam'))->inspectUninstall();
    check($failures, $preview['dependents'] === [] && $preview['unverifiable'] === [],
        '依赖方仅处于待安装候选状态时不拦截卸载');

    // ---- 5. 依赖方声明的是其它插件 → 不拦截 ----------------------------------------
    writeCandidate('sand-ai', ['version' => '0.4.8', 'state' => 1, 'title' => 'SandAI AI 能力底座'],
        depConfig('sand-other', '1.0.0', str_repeat('b', 64)));
    $preview = (new InstallLogic('sand-iam'))->inspectUninstall();
    check($failures, $preview['dependents'] === [] && $preview['unverifiable'] === [],
        '依赖方声明其它依赖目标时不算本插件依赖方');

    // ---- 5b. list 形态依赖声明同样计入依赖方 -----------------------------------------
    put(registryRoot() . '/sand-ai/config.json',
        (string) json_encode(['schema' => 1, 'plugin_dependencies' => ['sand-iam']], JSON_UNESCAPED_SLASHES));
    $preview = (new InstallLogic('sand-iam'))->inspectUninstall();
    check($failures, count($preview['dependents']) === 1
        && $preview['dependents'][0]['app'] === 'sand-ai',
        'list 形态 plugin_dependencies 声明也计入依赖方');

    // ---- 5c. 超限（>1MiB）配置 → 无法核对，列入 unverifiable ---------------------------
    $oversized = str_repeat(' ', 1048577);
    put(registryRoot() . '/sand-ai/config.json', $oversized);
    $preview = (new InstallLogic('sand-iam'))->inspectUninstall();
    check($failures, $preview['dependents'] === []
        && count($preview['unverifiable']) === 1
        && $preview['unverifiable'][0]['app'] === 'sand-ai',
        '超限配置不可证明安全 → 列入 unverifiable 而非静默跳过');

    // ---- 5d. 超限 + uninstall() 用独立文案拒绝 ----------------------------------------
    $message = '';
    try {
        (new InstallLogic('sand-iam'))->uninstall();
    } catch (\plugin\sandadmin\exception\ApiException $error) {
        $message = $error->getMessage();
    }
    check($failures, str_contains($message, '无法确认以下已安装插件是否依赖本插件')
        && str_contains($message, 'sand-ai'),
        '存在无法核对的依赖方时 uninstall() 拒绝并列出待检查插件');

    // ---- 5e. 超过旧 16KB 但在 1MiB 内的合法配置正常识别依赖 ----------------------------
    $largeConfig = depConfig('sand-iam', '0.8.6', str_repeat('a', 64));
    $largeConfig['notes'] = str_repeat('x', 20480);
    put(registryRoot() . '/sand-ai/config.json',
        (string) json_encode($largeConfig, JSON_UNESCAPED_SLASHES));
    $preview = (new InstallLogic('sand-iam'))->inspectUninstall();
    check($failures, count($preview['dependents']) === 1
        && $preview['dependents'][0]['app'] === 'sand-ai'
        && $preview['unverifiable'] === [],
        '大于 16KiB 的合法配置正常识别依赖（旧上限不再漏判）');

    // ---- 6. 依赖方配置缺失 → 无法核对，列入 unverifiable ------------------------------
    put(registryRoot() . '/sand-ai/config.json',
        (string) json_encode(depConfig('sand-other', '1.0.0', str_repeat('b', 64)), JSON_UNESCAPED_SLASHES));
    unlink(registryRoot() . '/sand-ai/config.json');
    $preview = (new InstallLogic('sand-iam'))->inspectUninstall();
    check($failures, $preview['dependents'] === []
        && count($preview['unverifiable']) === 1
        && $preview['unverifiable'][0]['app'] === 'sand-ai',
        '依赖方缺少配置文件时列入 unverifiable（不静默漏判）');

    // ---- 7. 依赖方配置损坏 → 列入 unverifiable ----------------------------------------
    put(registryRoot() . '/sand-ai/config.json', '{broken json');
    $preview = (new InstallLogic('sand-iam'))->inspectUninstall();
    check($failures, $preview['dependents'] === []
        && count($preview['unverifiable']) === 1
        && $preview['unverifiable'][0]['app'] === 'sand-ai',
        '依赖方配置不可解析时列入 unverifiable');

    // ---- 8. 无任何依赖方 → uninstall() 通过依赖检查（继续走到后续生命周期）-----------
    // 桩环境没有真实数据库：越过依赖检查后会在卸载 SQL 阶段失败，断言失败
    // 不是依赖拒绝文案即证明前置检查放行。
    put(registryRoot() . '/sand-ai/config.json',
        (string) json_encode(depConfig('sand-iam', '0.8.6', str_repeat('a', 64)), JSON_UNESCAPED_SLASHES));
    removeTree(registryRoot() . '/sand-ai');
    removeTree(base_path('plugin/sand-ai'));
    $message = '';
    try {
        (new InstallLogic('sand-iam'))->uninstall();
    } catch (\Throwable $error) {
        $message = $error->getMessage();
    }
    check($failures, $message !== '' && !str_contains($message, '仍被以下已安装插件依赖')
        && !str_contains($message, '无法确认以下已安装插件是否依赖'),
        '无已安装依赖方时 uninstall() 不被依赖检查拦截');
} finally {
    removeTree($hostRoot);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " 项失败\n");
    exit(1);
}
fwrite(STDOUT, "uninstall-dependents-test 全部通过\n");
