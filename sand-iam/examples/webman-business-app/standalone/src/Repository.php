<?php

declare(strict_types=1);

namespace Example\Standalone;

interface Repository
{
    public function health(): void;

    public function find(int $id): WorkItem;

    /** @param callable(WorkItem):string $authorize */
    public function close(int $id, callable $authorize, AuditWriter $auditWriter, string $requestId): WorkItem;
}
