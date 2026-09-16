<?php

declare(strict_types=1);

namespace Example\Standalone;

use PDO;
use RuntimeException;

final class PgsqlRepository implements Repository
{
    public function __construct(private readonly PDO $database) {}

    public function health(): void
    {
        $statement = $this->database->query(
            "SELECT to_regclass('public.standalone_work_item') AS work_items,
                    to_regclass('public.standalone_business_audit') AS audit"
        );
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['work_items'] === null || $row['audit'] === null) {
            throw new RuntimeException('standalone consumer tables are unavailable');
        }
    }

    public function find(int $id): WorkItem
    {
        if ($id <= 0) throw new RuntimeException('work item id is invalid');
        $statement = $this->database->prepare(
            'SELECT id, organization_id, owner_identity_id, state, version FROM standalone_work_item WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        return $this->item($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function close(int $id, callable $authorize, AuditWriter $auditWriter, string $action, string $requestId): WorkItem
    {
        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare(
                'SELECT id, organization_id, owner_identity_id, state, version FROM standalone_work_item WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $id]);
            $item = $this->item($statement->fetch(PDO::FETCH_ASSOC));
            if ($item->state !== 'open') throw new RuntimeException('work item is not open');

            $actorReference = $authorize($item);
            $update = $this->database->prepare(
                "UPDATE standalone_work_item SET state = 'closed', version = version + 1, update_time = CURRENT_TIMESTAMP
                 WHERE id = :id AND state = 'open' AND version = :version"
            );
            $update->execute(['id' => $item->id, 'version' => $item->version]);
            if ($update->rowCount() !== 1) throw new RuntimeException('work item changed concurrently');

            $closed = new WorkItem($item->id, $item->organizationId, $item->ownerIdentityId, 'closed', $item->version + 1);
            $auditWriter->writeSuccess($this->database, $action, $closed, $actorReference, $requestId);
            $this->database->commit();
            return $closed;
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $exception;
        }
    }

    /** @param array<string,mixed>|false $row */
    private function item(array|false $row): WorkItem
    {
        if (!is_array($row)) throw new RuntimeException('work item was not found');
        return new WorkItem((int) $row['id'], (int) $row['organization_id'], (int) $row['owner_identity_id'], (string) $row['state'], (int) $row['version']);
    }
}
