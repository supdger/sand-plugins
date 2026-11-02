<?php

declare(strict_types=1);

/** Resolve installed-host storage without bootstrapping services or migrating data. */
function sandPackageStorageRoot(string $server): string
{
    $server = realpath($server);
    if ($server === false) {
        throw new RuntimeException('SandPackage host directory is unavailable');
    }
    $storage = $server . '/plugin/sandpackage/app/service/PluginStorage.php';
    if (!is_file($storage)) {
        throw new RuntimeException('Host PluginStorage is unavailable; synchronize the reviewed host revision first');
    }
    require_once $server . '/vendor/autoload.php';
    require_once $server . '/plugin/sandadmin/exception/ApiException.php';
    require_once $storage;

    return (new \plugin\sandpackage\app\service\PluginStorage($server . '/runtime', $server))->root();
}
