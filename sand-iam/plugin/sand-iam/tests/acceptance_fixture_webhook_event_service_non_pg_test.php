<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace plugin\SandIam\app\service {
    final class RequestId { public static function normalize(string $value): string { return trim($value); } }
}

namespace plugin\SandIam\app\acceptance {
    final class AcceptanceFixtureService
    {
        public const PREFIX_PATTERN = '/^sand_iam_acceptance_[a-f0-9]{16}_$/';
        public const REQUEST_ID_PATTERN = '/^sand_iam_acceptance_[a-f0-9]{16}_[A-Za-z0-9][A-Za-z0-9_.:-]{0,58}$/';
    }
}

namespace {
    use plugin\SandIam\app\acceptance\AcceptanceFixtureWebhookEventService;
    use plugin\SandIam\app\acceptance\AcceptanceFixtureWebhookEventStore;
    use plugin\sandadmin\exception\ApiException;

    require_once dirname(__DIR__) . '/app/acceptance/AcceptanceFixtureWebhookEventStore.php';

    final class AcceptanceFixtureWebhookEventMemoryStore implements AcceptanceFixtureWebhookEventStore
    {
        /** @var list<array<string,mixed>> */
        public array $deliveries = [];

        public function transaction(callable $operation): mixed { return $operation(); }
        public function application(int $applicationId, bool $lock): ?array
        {
            return $applicationId === 22 ? ['id' => 22, 'organization_id' => 11, 'status' => 1] : null;
        }
        public function endpoint(int $endpointId, int $applicationId, bool $lock): ?array
        {
            return $endpointId === 33 && $applicationId === 22 ? [
                'id' => 33, 'application_id' => 22, 'status' => 1,
                'code' => 'sand_iam_acceptance_0123456789abcdef_webhook',
                'event_types' => ['acceptance.fixture.event'],
            ] : null;
        }
        public function creationAuditIds(string $requestId, int $endpointId, string $prefix): array
        {
            return $requestId === 'sand_iam_acceptance_0123456789abcdef_chain7-webhook-create' && $endpointId === 33 ? [33] : [];
        }
        public function deliveries(int $endpointId, int $applicationId, string $eventId, bool $lock): array
        {
            return array_values(array_filter($this->deliveries, static fn (array $row): bool =>
                $row['webhook_endpoint_id'] === $endpointId && $row['application_id'] === $applicationId && $row['event_id'] === $eventId
            ));
        }
    }

    function acceptanceFixtureWebhookEventAssert(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    }

    /** @param callable():mixed $operation */
    function acceptanceFixtureWebhookEventExpect(callable $operation, string $code): void
    {
        try { $operation(); } catch (ApiException $exception) {
            acceptanceFixtureWebhookEventAssert(str_contains($exception->getMessage(), $code), 'unexpected webhook fixture event error: ' . $exception->getMessage());
            return;
        }
        acceptanceFixtureWebhookEventAssert(false, 'expected ' . $code);
    }

    require_once dirname(__DIR__) . '/app/acceptance/AcceptanceFixtureWebhookEventService.php';

    $prefix = 'sand_iam_acceptance_0123456789abcdef_';
    $requestId = $prefix . 'chain7-event';
    $store = new AcceptanceFixtureWebhookEventMemoryStore();
    $audits = [];
    $service = new AcceptanceFixtureWebhookEventService(
        $store,
        static function (int $endpointId, int $applicationId, string $eventId) use ($store): array {
            $store->deliveries[] = ['id' => 44, 'webhook_endpoint_id' => $endpointId, 'application_id' => $applicationId, 'event_id' => $eventId];
            return ['event_id' => $eventId, 'delivery_ids' => [44], 'replayed' => false];
        },
        static function (...$arguments) use (&$audits): void { $audits[] = $arguments; },
    );
    $payload = [
        'chain_id' => 'event-webhook-delivery',
        'request_id' => $requestId,
        'prefix' => $prefix,
        'confirmation' => AcceptanceFixtureWebhookEventService::CONFIRMATION,
        'organization_id' => 11,
        'application_id' => 22,
        'endpoint_id' => 33,
        'endpoint_request_id' => $prefix . 'chain7-webhook-create',
    ];
    $result = $service->trigger($payload, 1, $requestId);
    acceptanceFixtureWebhookEventAssert($result['event_id'] === $requestId && $result['delivery_ids'] === [44] && $result['endpoint_id'] === 33, 'safe fixture event did not return its exact endpoint and delivery');
    acceptanceFixtureWebhookEventAssert(count($audits) === 1 && $audits[0][4] === 'acceptance_fixture.webhook_event' && $audits[0][5] === 'succeeded', 'fixture event success audit is missing');
    $auditJson = json_encode($audits[0][6], JSON_THROW_ON_ERROR);
    acceptanceFixtureWebhookEventAssert(!str_contains($auditJson, $prefix) && !str_contains($auditJson, AcceptanceFixtureWebhookEventService::CONFIRMATION), 'fixture event audit leaked prefix or confirmation');
    $wrongChain = $payload;
    $wrongChain['chain_id'] = 'other-chain';
    acceptanceFixtureWebhookEventExpect(static fn () => $service->trigger($wrongChain, 1, $requestId), 'CHAIN_UNSUPPORTED');
    $wrongHeader = $payload;
    acceptanceFixtureWebhookEventExpect(static fn () => $service->trigger($wrongHeader, 1, $prefix . 'other-request'), 'REQUEST_MISMATCH');
    $overflow = $payload;
    $overflow['endpoint_id'] = (string) ((int) PHP_INT_MAX) . '0';
    acceptanceFixtureWebhookEventExpect(static fn () => $service->trigger($overflow, 1, $requestId), 'OBJECTS_INVALID');
    echo 'acceptance fixture webhook event service non-PG checks passed' . PHP_EOL;
}
