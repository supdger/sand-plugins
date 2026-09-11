<?php

declare(strict_types=1);

namespace plugin\SandIam\app\acceptance;

interface AcceptanceFixtureWebhookEventStore
{
    /** @param callable():mixed $operation */
    public function transaction(callable $operation): mixed;

    /** @return array<string,mixed>|null */
    public function application(int $applicationId, bool $lock): ?array;

    /** @return array<string,mixed>|null */
    public function endpoint(int $endpointId, int $applicationId, bool $lock): ?array;

    /** @return list<int> */
    public function creationAuditIds(string $requestId, int $endpointId, string $prefix): array;

    /** @return list<array<string,mixed>> */
    public function deliveries(int $endpointId, int $applicationId, string $eventId, bool $lock): array;
}
