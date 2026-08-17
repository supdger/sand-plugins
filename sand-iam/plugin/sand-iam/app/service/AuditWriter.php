<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\AuditLog;

final class AuditWriter
{
    /** @param array<string, mixed> $context */
    public function write(
        string $actorType,
        string $actorRef,
        ?int $organizationId,
        ?int $applicationId,
        string $action,
        string $resourceType,
        ?int $resourceId,
        string $outcome,
        string $requestId,
        array $context = [],
    ): void {
        AuditLog::create([
            'actor_type' => $actorType,
            'actor_ref' => $actorRef,
            'organization_id' => $organizationId,
            'application_id' => $applicationId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'outcome' => $outcome,
            'request_id' => $requestId,
            'context' => $context,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }
}
