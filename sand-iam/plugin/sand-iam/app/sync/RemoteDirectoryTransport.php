<?php

declare(strict_types=1);

namespace plugin\SandIam\app\sync;

/**
 * Deployment boundary for remote directory reads. Config remains encrypted by
 * SyncConnectorService; transport implementations must never log headers or
 * response bodies because they may contain bearer credentials or PII.
 */
interface RemoteDirectoryTransport
{
    /** @param array<string,string> $headers @return array{status:int,headers:array<string,string>,body:string} */
    public function get(string $url, array $headers): array;
}
