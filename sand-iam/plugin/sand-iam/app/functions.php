<?php

declare(strict_types=1);

$sandIamVendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($sandIamVendorAutoload)) {
    require_once $sandIamVendorAutoload;
}

/**
 * SaiPackage copies application plugins into plugin/<app>, but it does not
 * regenerate the host Composer PSR-4 map. Register this package-local loader
 * before Webman loads plugin routes so controller callables remain resolvable
 * after a normal package installation.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'plugin\\SandIam\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath;
    if (is_file($file)) {
        require_once $file;
    }
}, true, true);

/**
 * 过渡期双向类别名：源码已改为 plugin\sandadmin。
 * - 新宿主只有 sandadmin 时，把残留的 saiadmin 引用别名过去；
 * - 旧宿主只有 saiadmin 时，把新源码的 sandadmin 引用别名回去。
 * SandAdmin 正式标签发布后删除本加载器，禁止长期靠别名运行。
 */
spl_autoload_register(static function (string $class): void {
    $pairs = [
        'plugin\\saiadmin\\' => 'plugin\\sandadmin\\',
        'plugin\\sandadmin\\' => 'plugin\\saiadmin\\',
    ];
    foreach ($pairs as $requestedPrefix => $fallbackPrefix) {
        if (!str_starts_with($class, $requestedPrefix)) {
            continue;
        }
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return;
        }
        $fallbackClass = $fallbackPrefix . substr($class, strlen($requestedPrefix));
        if (class_exists($fallbackClass) || interface_exists($fallbackClass) || trait_exists($fallbackClass)) {
            class_alias($fallbackClass, $class);
            return;
        }
    }
});

$sandAdminFunctions = base_path() . '/plugin/sandadmin/app/functions.php';
$legacyAdminFunctions = base_path() . '/plugin/saiadmin/app/functions.php';
if (!function_exists('getCurrentInfo')) {
    if (is_file($sandAdminFunctions)) {
        require_once $sandAdminFunctions;
    } elseif (is_file($legacyAdminFunctions)) {
        require_once $legacyAdminFunctions;
    }
}
