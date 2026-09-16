<?php

declare(strict_types=1);

namespace plugin\SandIam\app\support;

use Webman\Config;

final class PluginConfigAlias
{
    public static function load(): void
    {
        if (config('plugin.SandIam.app') !== null) {
            return;
        }
        Config::load(dirname(__DIR__, 2) . '/config', ['route', 'container'], 'plugin.SandIam');
    }
}
