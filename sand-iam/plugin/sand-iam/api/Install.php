<?php

declare(strict_types=1);

namespace plugin\SandIam\api;

/**
 * SaiPackage executes the lifecycle SQL files from the package root.
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
