<?php

declare(strict_types=1);

namespace plugin\SandIam\app\sync;

/** Process-local seam for non-PG driver contracts; production defaults to native HTTPS transport. */
final class RemoteDirectoryTransportRegistry
{
    private static ?RemoteDirectoryTransport $transport = null;

    public static function transport(): RemoteDirectoryTransport
    {
        return self::$transport ?? new NativeRemoteDirectoryTransport();
    }

    public static function replaceForTesting(?RemoteDirectoryTransport $transport): void
    {
        self::$transport = $transport;
    }
}
