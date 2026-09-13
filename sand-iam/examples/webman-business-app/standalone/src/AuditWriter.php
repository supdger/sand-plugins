<?php

declare(strict_types=1);

namespace Example\Standalone;

use PDO;

interface AuditWriter
{
    public function writeSuccess(PDO $transaction, string $action, WorkItem $item, string $actorReference, string $requestId): void;

    public function writeEvent(string $action, string $outcome, ?WorkItem $item, ?string $reasonCode, string $actorReference, string $requestId): void;
}
