<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\SyncConnector;

final class EloquentDirectorySyncScheduleRepository implements DirectorySyncScheduleRepository
{
    /** @return list<array{id:int,organization_id:int,application_id:int,status:int,config_configured:bool}> */
    public function dueAfter(?int $afterId, int $limit): array
    {
        $query = SyncConnector::alias('connector')
            ->join('sand_iam_application application', 'application.id = connector.application_id')
            ->join('sand_iam_organization organization', 'organization.id = connector.organization_id')
            ->where('connector.status', 1)
            ->where('application.status', 1)
            ->where('organization.status', 1)
            ->whereNotNull('connector.encrypted_config')
            ->where('connector.encrypted_config', '<>', '');
        if ($afterId !== null) {
            $query->where('connector.id', '>', $afterId);
        }
        $rows = $query
            ->field('connector.id, connector.organization_id, connector.application_id, connector.status')
            ->order('connector.id', 'asc')
            ->limit(max(1, min(500, $limit)))
            ->select()
            ->toArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'organization_id' => (int) $row['organization_id'],
            'application_id' => (int) $row['application_id'],
            'status' => (int) $row['status'],
            'config_configured' => true,
        ], $rows);
    }

    /** @param list<int> $connectorIds @return list<int> */
    public function retryableIds(array $connectorIds): array
    {
        $connectorIds = array_values(array_unique(array_filter($connectorIds, static fn (int $id): bool => $id > 0)));
        if ($connectorIds === []) {
            return [];
        }

        return array_map('intval', SyncConnector::alias('connector')
            ->join('sand_iam_application application', 'application.id = connector.application_id')
            ->join('sand_iam_organization organization', 'organization.id = connector.organization_id')
            ->whereIn('connector.id', $connectorIds)
            ->where('connector.status', 1)
            ->where('application.status', 1)
            ->where('organization.status', 1)
            ->whereNotNull('connector.encrypted_config')
            ->where('connector.encrypted_config', '<>', '')
            ->column('connector.id'));
    }
}
