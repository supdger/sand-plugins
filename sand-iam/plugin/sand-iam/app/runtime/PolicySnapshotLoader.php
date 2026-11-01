<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

interface PolicySnapshotLoader
{
    /** @return array<string,mixed> */
    public function load(int $applicationId, int $identityId, string $resourceCode, string $action, ?array $apiContext = null): array;
}
