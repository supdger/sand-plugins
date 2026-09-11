<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\WebhookDelivery;
use plugin\SandIam\app\model\WebhookEndpoint;
use plugin\SandIam\app\webhook\EventCatalog;

/** Publishes a deliberately small audit projection inside the caller's transaction. */
final class AuditEventPublisher
{
    public function publish(
        ?int $applicationId,
        string $action,
        string $resourceType,
        ?int $resourceId,
        string $outcome,
        string $requestId,
    ): ?string {
        if ((int) config('plugin.sand-iam.app.audit_event_outbox_enabled', 0) !== 1) return null;
        $eventType = EventCatalog::fromAudit($action, $outcome);
        if ($eventType === null) return null;
        if ($applicationId === null) return $this->publishGlobalEvent($eventType, [
            'action' => $action,
            'outcome' => $outcome,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'request_id' => $requestId,
        ]);
        return $this->publishEvent($applicationId, $eventType, [
            'action' => $action,
            'outcome' => $outcome,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'request_id' => $requestId,
        ]);
    }

    /** @param array<string,int|string|null> $data */
    public function publishEvent(int $applicationId, string $eventType, array $data): string
    {
        $definition = EventCatalog::get($eventType);
        $eventId = 'evt_' . bin2hex(random_bytes(16));
        $envelope = [
            'id' => $eventId,
            'type' => $eventType,
            'schema_version' => $definition['version'],
            'occurred_at' => gmdate('c'),
            'application_id' => $applicationId,
            'data' => $data,
        ];
        foreach (WebhookEndpoint::where('application_id', $applicationId)->where('status', 1)->lock(true)->select() as $endpoint) {
            $types = $endpoint->event_types;
            if (is_string($types)) $types = json_decode($types, true);
            if (!is_array($types) || !in_array($eventType, $types, true)) continue;
            WebhookDelivery::create([
                'application_id' => $applicationId,
                'webhook_endpoint_id' => (int) $endpoint->id,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $envelope,
                'status' => 1,
                'attempt_count' => 0,
                'next_attempt_time' => date('Y-m-d H:i:s'),
            ]);
        }
        return $eventId;
    }

    /** @param array<string,int|string|null> $data */
    private function publishGlobalEvent(string $eventType, array $data): string
    {
        $definition = EventCatalog::get($eventType);
        $eventId = 'evt_' . bin2hex(random_bytes(16));
        foreach (WebhookEndpoint::where('status', 1)->lock(true)->select() as $endpoint) {
            $types = $endpoint->event_types;
            if (is_string($types)) $types = json_decode($types, true);
            if (!is_array($types) || !in_array($eventType, $types, true)) continue;
            WebhookDelivery::create([
                'application_id' => (int) $endpoint->application_id,
                'webhook_endpoint_id' => (int) $endpoint->id,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => ['id' => $eventId, 'type' => $eventType, 'schema_version' => $definition['version'], 'occurred_at' => gmdate('c'), 'application_id' => null, 'data' => $data],
                'status' => 1,
                'attempt_count' => 0,
                'next_attempt_time' => date('Y-m-d H:i:s'),
            ]);
        }
        return $eventId;
    }
}
