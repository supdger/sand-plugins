<?php

declare(strict_types=1);

namespace plugin\SandIam\api;

/**
 * SaiPackage 以包根的 install.sql、update.sql、uninstall.sql 为准。
 * 此生命周期入口保留为与现有插件源码一致的扩展点；P0 迁移冻结后再加入校验逻辑。
 */
final class Install
{
    public static function install(string $version): void
    {
    }

    public static function update(string $fromVersion, string $toVersion, mixed $context = null): void
    {
    }

    public static function uninstall(string $version): void
    {
    }
}
