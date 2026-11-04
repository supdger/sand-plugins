<?php

declare(strict_types=1);

use plugin\sandpackage\app\service\DependencyCommandSupport;

/*
 * 依赖命令环境与失败诊断测试。裸 PHP 脚本，包根执行，退出码非 0 即失败：
 *
 *   php tests/dependency-command-test.php
 *
 * 覆盖：COMPOSER_HOME 沙箱的镜像注入、宿主显式声明尊重、auth.json/config.json
 * 继承、输出尾部的中文原因分类；末尾在真实 composer 进程里验证依赖解析不
 * 请求 api.github.com（无 composer 可执行文件时跳过并提示）。
 */

$packageRoot = dirname(__DIR__);
require $packageRoot . '/server/plugin/sandpackage/app/service/DependencyCommandSupport.php';

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

$tmp = sys_get_temp_dir() . '/sandpackage-dep-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);

try {
    // ---- 1. 默认注入镜像，继承宿主 auth/config ------------------------------
    $realHome = $tmp . '/real-home';
    mkdir($realHome, 0700, true);
    file_put_contents($realHome . '/auth.json', "{\"github-oauth\":{\"github.com\":\"dummy\"}}\n");
    file_put_contents($realHome . '/config.json', "{\"config\":{\"process-timeout\":900}}\n");
    $project = $tmp . '/project-a';
    mkdir($project, 0700, true);
    file_put_contents($project . '/composer.json', "{\"name\":\"t/host\"}\n");
    $home = DependencyCommandSupport::prepareComposerHome(
        $tmp . '/sandbox-a',
        $project . '/composer.json',
        $realHome,
        DependencyCommandSupport::DEFAULT_PACKAGIST_MIRROR
    );
    $config = json_decode((string) file_get_contents($home . '/config.json'), true);
    check($failures, ($config['repositories']['packagist.org']['url'] ?? null) === DependencyCommandSupport::DEFAULT_PACKAGIST_MIRROR, '默认注入腾讯云 packagist 镜像');
    check($failures, ($config['config']['preferred-install'] ?? null) === 'dist', 'preferred-install=dist');
    check($failures, ($config['config']['use-github-api'] ?? null) === false, 'use-github-api=false');
    check($failures, ($config['config']['process-timeout'] ?? null) === 900, '继承宿主全局 config.json');
    check($failures, is_file($home . '/auth.json'), '继承宿主 auth.json（私有凭证不丢）');
    check($failures, substr(sprintf('%o', fileperms($home . '/auth.json')), -3) === '600', 'auth.json 权限 0600');

    // ---- 2. 宿主项目已声明 packagist.org → 不注入镜像 -------------------------
    $projectB = $tmp . '/project-b';
    mkdir($projectB, 0700, true);
    file_put_contents($projectB . '/composer.json', "{\"name\":\"t/host\",\"repositories\":{\"packagist.org\":{\"type\":\"composer\",\"url\":\"https://example.test/packagist\"}}}\n");
    $homeB = DependencyCommandSupport::prepareComposerHome(
        $tmp . '/sandbox-b',
        $projectB . '/composer.json',
        null,
        DependencyCommandSupport::DEFAULT_PACKAGIST_MIRROR
    );
    $configB = json_decode((string) file_get_contents($homeB . '/config.json'), true);
    check($failures, !isset($configB['repositories']['packagist.org']), '宿主显式声明 packagist.org 时不注入镜像');

    // ---- 3. 空镜像 → 仅隔离环境不改仓库 ------------------------------------
    $homeC = DependencyCommandSupport::prepareComposerHome(
        $tmp . '/sandbox-c',
        $project . '/composer.json',
        null,
        null
    );
    $configC = json_decode((string) file_get_contents($homeC . '/config.json'), true);
    check($failures, !isset($configC['repositories']), '空镜像不写入 repositories');

    // ---- 4. 环境注入 --------------------------------------------------------
    $env = DependencyCommandSupport::composerEnvironment(['PATH' => '/usr/bin'], $home);
    check($failures, ($env['COMPOSER_HOME'] ?? null) === $home && ($env['PATH'] ?? null) === '/usr/bin'
        && ($env['COMPOSER_NO_INTERACTION'] ?? null) === '1', 'COMPOSER_HOME/NO_INTERACTION 注入且保留宿主环境');

    // ---- 5. 失败原因分类 ----------------------------------------------------
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', "Downloading https://api.github.com/repos/x/y\nGitHub API rate limit exceeded"),
        'GitHub 访问次数'
    ), 'GitHub 限额 → 中文原因');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', 'curl error 28 while downloading https://github.com/x/y.zip: Connection timed out'),
        'GitHub'
    ), 'GitHub 连接失败 → 中文原因');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', 'Failed to download https://mirrors.cloud.tencent.com/composer/packages.json: Connection refused'),
        '依赖仓库'
    ), '镜像不可达 → 中文原因');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('npm', 'npm ERR! code ETIMEDOUT\nnpm ERR! network'),
        '前端依赖仓库'
    ), 'npm 网络错误 → 中文原因');
    check($failures, DependencyCommandSupport::failureHint('composer', 'Some unrelated output') === null, '无法识别的输出不编造原因');

    // ---- 5b. 网络类夹具（curl 6/7/28、域名解析、连接失败）--------------------
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', "Loading composer repositories with package information\ncurl error 7 while downloading https://127.0.0.1:9/packages.json: Failed to connect to 127.0.0.1:9 after 0 ms: Could not connect to server"),
        '依赖仓库'
    ), 'curl error 7 镜像不可达 → 依赖仓库原因');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', 'curl error 6 while downloading https://mirrors.example.test/packages.json: Could not resolve host: mirrors.example.test'),
        '依赖仓库'
    ), 'curl error 6 域名解析失败 → 依赖仓库原因');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', 'curl error 28 while downloading https://mirrors.example.test/x.zip: Connection timed out after 30000 milliseconds'),
        '依赖仓库'
    ), 'curl error 28 超时 → 依赖仓库原因');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', 'Failed to connect to repo.packagist.example: connect failed'),
        '依赖仓库'
    ), 'connect failed → 依赖仓库原因');

    // ---- 5c. GitHub 限流夹具（本地输出推算分钟数，不请求真实 api.github.com）---
    $resetTs = time() + 1800;
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', "GitHub API rate limit (60 calls/hr) exceeded for api.github.com. X-RateLimit-Reset: {$resetTs}"),
        '约 30 分钟'
    ), 'GitHub 限额 + X-RateLimit-Reset → 约 30 分钟');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', "rate limit exceeded for github.com\nRetry-After: 300"),
        '约 5 分钟'
    ), 'GitHub 限额 + Retry-After 秒数 → 约 5 分钟');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', 'rate limit exceeded for github.com, please try again in 42 minutes'),
        '约 42 分钟'
    ), 'GitHub 限额 + 分钟数报文 → 约 42 分钟');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', 'rate limit exceeded for github.com without any reset hint'),
        'GitHub 访问次数'
    ), 'GitHub 限额无时间线索 → 仍给令牌指引');

    // ---- 5d. 磁盘 / 权限夹具 ------------------------------------------------
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', 'copy(/tmp/vendor/x/y.zip): failed to open stream: No space left on device'),
        '磁盘空间不足'
    ), 'composer 磁盘满 → 中文原因');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', 'mkdir(): Permission denied'),
        '权限'
    ), 'composer 目录无权限 → 中文原因');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('npm', "npm ERR! code EACCES\nnpm ERR! syscall mkdir"),
        '权限'
    ), 'npm EACCES → 中文原因');

    // ---- 5e. 版本冲突夹具 ---------------------------------------------------
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('composer', "Your requirements could not be resolved to an installable set of packages.\n  Problem 1\n    - Root composer.json requires x/y ^2.0 -> satisfiable by x/y[v2.0.0]."),
        '无法满足'
    ), 'composer 依赖冲突 → 中文原因');
    check($failures, str_contains(
        (string) DependencyCommandSupport::failureHint('npm', "npm ERR! ERESOLVE unable to resolve dependency tree\nnpm ERR! code ERESOLVE"),
        '无法解析'
    ), 'npm ERESOLVE → 中文原因');

    // ---- 5f. 其他兜底：输出摘录 ----------------------------------------------
    check($failures, DependencyCommandSupport::failureExcerpt('Some unrelated output tail') === 'Some unrelated output tail', '输出摘录保留原文');
    check($failures, DependencyCommandSupport::failureExcerpt("  line1\n\n line2  ") === 'line1 line2', '输出摘录折叠空白');
    $longTail = str_repeat('a', 300);
    $excerpt = (string) DependencyCommandSupport::failureExcerpt($longTail);
    check($failures, mb_strlen($excerpt) <= 201 && str_starts_with($excerpt, '…'), '输出摘录超长截断');
    check($failures, DependencyCommandSupport::failureExcerpt('   ') === null, '空输出不编造摘录');

    // ---- 6. 真实 composer 进程：依赖解析不请求 api.github.com ---------------
    $composer = trim((string) shell_exec('command -v composer 2>/dev/null'));
    if ($composer === '') {
        fwrite(STDOUT, "skip 未找到 composer 可执行文件，跳过 api.github.com 端到端验证\n");
    } else {
        $e2e = $tmp . '/e2e';
        mkdir($e2e, 0700, true);
        file_put_contents($e2e . '/composer.json', "{\"name\":\"t/host\",\"require\":{\"psr/log\":\"^3.0\"}}\n");
        $e2eHome = DependencyCommandSupport::prepareComposerHome(
            $tmp . '/sandbox-e2e',
            $e2e . '/composer.json',
            null,
            DependencyCommandSupport::DEFAULT_PACKAGIST_MIRROR
        );
        $env = DependencyCommandSupport::composerEnvironment(['PATH' => getenv('PATH') ?: '/usr/bin:/bin'], $e2eHome);
        $prefix = implode(' ', array_map(
            static fn(string $key, string $value): string => $key . '=' . escapeshellarg($value),
            array_keys($env),
            $env
        ));
        $command = 'cd ' . escapeshellarg($e2e) . ' && ' . $prefix . ' ' . escapeshellarg($composer)
            . ' update --prefer-dist --no-interaction -vvv 2>&1';
        $output = (string) shell_exec($command);
        check($failures, stripos($output, 'api.github.com') === false, '依赖解析与 dist 下载不请求 api.github.com');
        check($failures, is_file($e2e . '/vendor/psr/log/composer.json'), 'composer update 实际完成并安装 dist 包');
    }
} finally {
    removeTree($tmp);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " 项失败\n");
    exit(1);
}
fwrite(STDOUT, "dependency-command-test 全部通过\n");
