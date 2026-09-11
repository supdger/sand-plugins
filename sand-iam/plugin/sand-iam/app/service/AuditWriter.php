<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\AuditLog;
use support\Log;

final class AuditWriter
{
    public function __construct(private readonly ?AuditEventPublisher $eventPublisher = null) {}

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
        $requestId = RequestId::normalize($requestId);
        $audit = AuditLog::create([
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
        ($this->eventPublisher ?? new AuditEventPublisher())->publish(
            $applicationId,
            $action,
            $resourceType,
            $resourceId,
            $outcome,
            $requestId,
        );
        try {
            (new SecurityOperationsService())->observeAudit($audit);
        } catch (\Throwable $exception) {
            // Alerting is an operational projection. Never replace the audited
            // business decision when alert storage is unavailable.
            Log::error('SandIAM security alert projection failed', [
                'exception_type' => $exception::class,
                'exception_file' => basename($exception->getFile()),
                'exception_line' => $exception->getLine(),
            ]);
        }
    }
}
