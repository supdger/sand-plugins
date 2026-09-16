<?php

declare(strict_types=1);

namespace plugin\SandIamC05Business\app;

use RuntimeException;
use support\Db;

final class WorkItemRepository
{
    public function findOrFail(int $id): WorkItem
    {
        if ($id < 1) {
            throw new RuntimeException('C05 work item id is invalid');
        }
        $row = Db::table('standalone_work_item')
            ->where('id', $id)
            ->select(['id', 'organization_id', 'owner_identity_id', 'state', 'version'])
            ->first();
        if ($row === null) {
            throw new RuntimeException('C05 work item was not found');
        }
        $data = is_object($row) ? (array) $row : $row;
        if (!is_array($data)) {
            throw new RuntimeException('C05 work item result is invalid');
        }
        return new WorkItem(
            (int) $data['id'],
            (int) $data['organization_id'],
            (int) $data['owner_identity_id'],
            (string) $data['state'],
            (int) $data['version'],
        );
    }
}
