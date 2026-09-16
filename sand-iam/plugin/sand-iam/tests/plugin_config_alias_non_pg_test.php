<?php

declare(strict_types=1);

namespace Webman {
    final class Config
    {
        /** @var list<array{path:string,exclude:list<string>,key:string}> */
        public static array $loads = [];

        public static function load(string $path, array $exclude, string $key): void
        {
            self::$loads[] = ['path' => $path, 'exclude' => $exclude, 'key' => $key];
        }
    }
}

namespace {
    $sandIamAliasExists = false;

    function config(string $key): mixed
    {
        global $sandIamAliasExists;
        return $key === 'plugin.SandIam.app' && $sandIamAliasExists ? ['version' => 'test'] : null;
    }

    require dirname(__DIR__) . '/app/support/PluginConfigAlias.php';

    \plugin\SandIam\app\support\PluginConfigAlias::load();
    $load = \Webman\Config::$loads[0] ?? null;
    if ($load === null
        || $load['path'] !== dirname(__DIR__) . '/config'
        || $load['exclude'] !== ['route', 'container']
        || $load['key'] !== 'plugin.SandIam') {
        fwrite(STDERR, "SandIAM namespace config alias was not loaded from the package config\n");
        exit(1);
    }

    $sandIamAliasExists = true;
    \plugin\SandIam\app\support\PluginConfigAlias::load();
    if (count(\Webman\Config::$loads) !== 1) {
        fwrite(STDERR, "existing SandIAM namespace config alias was loaded twice\n");
        exit(1);
    }

    echo "SandIAM plugin config alias checks passed\n";
}
