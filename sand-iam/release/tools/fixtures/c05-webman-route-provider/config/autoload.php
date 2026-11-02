<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'plugin\\SandIamC05Business\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath;
    if (is_file($file)) {
        require_once $file;
    }
}, true, true);

return [];
