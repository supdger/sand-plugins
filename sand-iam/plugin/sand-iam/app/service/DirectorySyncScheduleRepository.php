<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

interface DirectorySyncScheduleRepository
{
    /**
     * @return list<array{id:int,organization_id:int,application_id:int,status:int,config_configured:bool}>
     */
    public function dueAfter(?int $afterId, int $limit): array;

    /**
     * Return only retry entries that remain enabled, configured and inside an
     * enabled organization/application boundary. Entries omitted here have
     * been disabled, deleted or made permanently ineligible.
     *
     * @param list<int> $connectorIds
     * @return list<int>
     */
    public function retryableIds(array $connectorIds): array;
}
