<?php

declare(strict_types=1);

namespace Example\Standalone;

use PDO;

final class PgsqlAuditWriter implements AuditWriter
{
    public function __construct(private readonly PDO $failureDatabase) {}

    public function writeSuccess(PDO $transaction, string $action, WorkItem $item, string $actorReference, string $requestId): void
    {
        $this->insert($transaction, $action, $item, 'allowed', null, $actorReference, $requestId);
    }

    public function writeFailure(string $action, ?WorkItem $item, string $reasonCode, string $requestId): void
    {
        $this->writeEvent($action, 'denied', $item, $reasonCode, 'unknown', $requestId);
    }

    public function writeEvent(string $action, string $outcome, ?WorkItem $item, ?string $reasonCode, string $actorReference, string $requestId): void
    {
        $this->insert($this->failureDatabase, $action, $item, $outcome, $reasonCode, $actorReference, $requestId);
    }

    private function insert(PDO $database, string $action, ?WorkItem $item, string $outcome, ?string $reasonCode, string $actorReference, string $requestId): void
    {
        $statement = $database->prepare(
            'INSERT INTO standalone_business_audit (action, outcome, reason_code, work_item_id, organization_ref, owner_ref, actor_ref, request_id)
             VALUES (:action, :outcome, :reason_code, :work_item_id, :organization_ref, :owner_ref, :actor_ref, :request_id)'
        );
        $statement->execute([
            'action' => $action,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'work_item_id' => $item?->id,
            'organization_ref' => $this->reference($item === null ? 'unknown' : (string) $item->organizationId),
            'owner_ref' => $this->reference($item === null ? 'unknown' : (string) $item->ownerIdentityId),
            'actor_ref' => $this->reference($actorReference),
            'request_id' => $requestId,
        ]);
    }

    private function reference(string $value): string
    {
        return substr(hash('sha256', $value), 0, 24);
    }
}
