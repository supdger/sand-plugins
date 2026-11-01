<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\AuditLog;
use support\Log;
use think\facade\Db;

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
        string $eventKey = '',
    ): void {
        $requestId = RequestId::normalize($requestId);
        $values = [
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
        ];
        if ($eventKey !== '' && (!str_starts_with($action, 'scope.') || preg_match('/^[a-f0-9]{64}$/', $eventKey) !== 1)) {
            throw new \LogicException('SAND_IAM_SCOPE_AUDIT_EVENT_INVALID');
        }
        if ($eventKey !== '') {
            // ThinkORM keeps this on its writer PDO and uses a savepoint when
            // the caller already owns an ORM transaction. Outbox failure must
            // roll back the event so its retry can publish the projection.
            Db::transaction(function (object $connection) use ($values, $eventKey): void {
                $audit = $this->appendScopeEvent($values, $eventKey, $connection);
                if ($audit !== null) $this->projectAudit($audit, $values, true);
            });
            return;
        }
        $this->projectAudit(AuditLog::create($values), $values);
    }

    /** @param array<string,mixed> $values */
    private function projectAudit(AuditLog $audit, array $values, bool $scopeTransaction = false): void
    {
        ($this->eventPublisher ?? new AuditEventPublisher())->publish(
            $values['application_id'],
            $values['action'],
            $values['resource_type'],
            $values['resource_id'],
            $values['outcome'],
            $values['request_id'],
        );
        try {
            if ($scopeTransaction) {
                // A best-effort alert SQL failure must not leave PostgreSQL's
                // enclosing audit/outbox transaction in the aborted state.
                Db::transaction(static function (object $connection) use ($audit): void {
                    (new SecurityOperationsService())->observeAudit($audit);
                });
            } else {
                (new SecurityOperationsService())->observeAudit($audit);
            }
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

    /**
     * The conflict target is deliberately limited to the immutable scope event.
     * A replay emits neither another audit nor webhook / alert projections.
     * Missing event-key schema and all other storage errors remain failures.
     *
     * @param array<string,mixed> $values
     */
    private function appendScopeEvent(array $values, string $eventKey, object $connection): ?AuditLog
    {
        $parameters = [
            $values['actor_type'], $values['actor_ref'], $values['organization_id'], $values['application_id'],
            $values['action'], $values['resource_type'], $values['resource_id'], $values['outcome'], $values['request_id'],
            json_encode($values['context'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $values['create_time'], $eventKey,
        ];
        $rows = $connection->query(
            'INSERT INTO sand_iam_audit_log (actor_type, actor_ref, organization_id, application_id, action, resource_type, resource_id, outcome, request_id, context, create_time, event_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?) ON CONFLICT (request_id, action, event_key) DO NOTHING RETURNING id',
            $parameters,
            true,
        );
        if ($rows === []) return null;
        $audit = new AuditLog();
        $audit->data($values + ['id' => (int) $rows[0]['id'], 'event_key' => $eventKey]);
        return $audit;
    }
}
