<?php

declare(strict_types=1);

namespace plugin\sandadmin\app\bootstrap;

use plugin\sandadmin\utils\InstallGuard;
use plugin\sandadmin\utils\JwtSecretGuard;
use Throwable;

/**
 * 进程启动自检：JWT 密钥不安全时拒绝启动；未安装时准备一次性安装令牌。
 *
 * 由 config/bootstrap.php 注册，Webman 在每个 worker 启动时调用 start()。
 */
final class SecurityBootstrap
{
    public static function start(?object $worker = null): void
    {
        try {
            JwtSecretGuard::assertRuntimeConfig();
        } catch (Throwable $error) {
            // 同时写到控制台，避免异常被 worker 重启循环淹没。
            fwrite(STDERR, '[sandadmin] ' . $error->getMessage() . PHP_EOL);
            throw $error;
        }
        if (!InstallGuard::isInstalled()) {
            InstallGuard::ensureToken();
        }
    }
}
