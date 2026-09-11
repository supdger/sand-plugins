<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\SyncConnector;
use plugin\SandIam\app\model\SyncOutbox;
use plugin\SandIam\app\model\WebhookDelivery;
use plugin\SandIam\app\model\WebhookEndpoint;
use plugin\SandIam\app\webhook\EventCatalog;
use plugin\sandadmin\exception\ApiException;

/**
 * Writes identity events into local outbox tables in the caller's transaction.
 * It deliberately owns no transaction so an identity change and its events
 * either commit together or roll back together.
 */
final class IdentityEventPublisher
{
    private const OPERATIONS = [
        'identity.created' => 'create',
        'identity.updated' => 'update',
        'identity.enabled' => 'update',
        'identity.disabled' => 'disable',
        'identity.deleted' => 'delete',
        'identity.restored' => 'update',
    ];

    public function __construct(private readonly SyncSecretCipher $syncCipher = new SyncSecretCipher())
    {
    }

    /** @param list<string> $changedFields */
    public function publish(
        Application $application,
        Identity $identity,
        string $eventType,
        array $changedFields,
        string $requestId,
        bool $publishToSync = true,
    ): ?string {
        if ((int) config('plugin.sand-iam.app.identity_event_outbox_enabled', 0) !== 1) return null;
        if (!isset(self::OPERATIONS[$eventType])) throw new ApiException('SAND_IAM_IDENTITY_EVENT_TYPE_INVALID', 400);
        if ((int) $identity->application_id !== (int) $application->id) throw new ApiException('SAND_IAM_IDENTITY_EVENT_SCOPE_MISMATCH', 409);

        $changedFields = $this->changedFields($changedFields);
        $requestId = $this->requestId($requestId);
        // Acceptance runs use their authenticated request id as the event id.
        // This preserves the normal event contract while giving the controlled
        // cleanup API a non-guessable, exact ownership binding.
        $eventId = preg_match('/^sand_iam_acceptance_[a-f0-9]{16}_[A-Za-z0-9][A-Za-z0-9_.:-]{0,58}$/', $requestId) === 1
            ? $requestId
            : 'evt_' . bin2hex(random_bytes(16));
        $occurredAt = gmdate('c');
        $definition = EventCatalog::get($eventType);
        $data = [
            'identity_id' => (int) $identity->id,
            'identity_code' => (string) $identity->code,
            'display_name' => (string) $identity->display_name,
            'lifecycle_state' => (string) ($identity->lifecycle_state ?? ((int) $identity->status === 1 ? 'active' : 'disabled')),
            'status' => (int) $identity->status,
            'changed_fields' => $changedFields,
        ];
        $envelope = [
            'id' => $eventId,
            'type' => $eventType,
            'schema_version' => $definition['version'],
            'occurred_at' => $occurredAt,
            'application_id' => (int) $application->id,
            'request_id' => $requestId,
            'data' => $data,
        ];

        $this->publishWebhooks($application, $eventId, $eventType, $envelope, $requestId);
        if ($publishToSync) $this->publishSync((int) $application->id, (int) $identity->id, self::OPERATIONS[$eventType], $envelope);

        return $eventId;
    }

    /** @param array<string,mixed> $envelope */
    private function publishWebhooks(Application $application, string $eventId, string $eventType, array $envelope, string $requestId): void
    {
        $applicationId = (int) $application->id;
        foreach (WebhookEndpoint::where('application_id', $applicationId)->where('status', 1)->lock(true)->select() as $endpoint) {
            $types = $endpoint->event_types;
            if (is_string($types)) $types = json_decode($types, true);
            if (!is_array($types) || !in_array($eventType, $types, true)) continue;

            $delivery = WebhookDelivery::create([
                'application_id' => $applicationId,
                'webhook_endpoint_id' => (int) $endpoint->id,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $envelope,
                'status' => 1,
                'attempt_count' => 0,
                'next_attempt_time' => date('Y-m-d H:i:s'),
            ]);
            (new AuditWriter())->write(
                'system',
                'identity_event',
                (int) $application->organization_id,
                $applicationId,
                'webhook.delivery_enqueue',
                'webhook_delivery',
                (int) $delivery->id,
                'succeeded',
                $requestId,
                ['endpoint_id' => (int) $endpoint->id, 'event_id' => $eventId, 'event_type' => $eventType],
            );
        }
    }

    /** @param array<string,mixed> $envelope */
    private function publishSync(int $applicationId, int $identityId, string $operation, array $envelope): void
    {
        $connectors = SyncConnector::where('application_id', $applicationId)
            ->whereIn('direction', ['outbound', 'bidirectional'])
            ->whereNotNull('encrypted_config')
            ->where('status', 1)
            ->lock(true)
            ->select();

        foreach ($connectors as $connector) {
            $eventId = 'sync_' . bin2hex(random_bytes(16));
            SyncOutbox::create([
                'sync_connector_id' => (int) $connector->id,
                'application_id' => $applicationId,
                'identity_id' => $identityId,
                'event_id' => $eventId,
                'operation' => $operation,
                'encrypted_payload' => $this->syncCipher->encryptArray($envelope + ['event_id' => $eventId]),
                'state' => 'pending',
                'attempt_count' => 0,
                'status' => 1,
            ]);
        }
    }

    /** @param list<string> $fields @return list<string> */
    private function changedFields(array $fields): array
    {
        if (count($fields) > 32) throw new ApiException('SAND_IAM_IDENTITY_EVENT_FIELDS_INVALID', 400);
        $result = [];
        foreach ($fields as $field) {
            if (!is_string($field) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field)) throw new ApiException('SAND_IAM_IDENTITY_EVENT_FIELDS_INVALID', 400);
            $result[] = $field;
        }
        return array_values(array_unique($result));
    }

    private function requestId(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.:-]{8,96}$/', $value) ? $value : 'req_' . bin2hex(random_bytes(16));
    }
}
