<?php

declare(strict_types=1);

namespace Example\WorkItem;

use RuntimeException;

/** Replace the table name only; never source owner or organization from a request body. */
final class WorkItemRepository
{
    private \PDO $database;

    public function __construct()
    {
        $dsn = (string) getenv('BUSINESS_DATABASE_DSN');
        if ($dsn === '') throw new RuntimeException('BUSINESS_DATABASE_DSN 未配置');
        $this->database = new \PDO($dsn, (string) getenv('BUSINESS_DATABASE_USER'), (string) getenv('BUSINESS_DATABASE_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    public function findOrFail(int $id): WorkItem
    {
        $query = $this->database->prepare('SELECT id, organization_id, owner_identity_id, state FROM business_work_item WHERE id = :id');
        $query->execute(['id' => $id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('工作项不存在');
        return new WorkItem((int) $row['id'], (int) $row['organization_id'], (int) $row['owner_identity_id'], (string) $row['state']);
    }

    /** @param list<int> $ids @return list<WorkItem> */
    public function findManyOrFail(array $ids): array
    {
        $items = [];
        foreach ($ids as $id) $items[] = $this->findOrFail($id);
        return $items;
    }

    public function close(WorkItem $workItem): void
    {
        $query = $this->database->prepare("UPDATE business_work_item SET state = 'closed' WHERE id = :id AND state <> 'closed'");
        $query->execute(['id' => $workItem->id]);
    }
}
