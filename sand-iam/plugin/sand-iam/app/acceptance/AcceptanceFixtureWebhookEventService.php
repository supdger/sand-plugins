<?php

declare(strict_types=1);

namespace plugin\SandIam\app\acceptance;

use Closure;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
use plugin\SandIam\app\service\WebhookService;
use plugin\sandadmin\exception\ApiException;

final class AcceptanceFixtureWebhookEventService
{
    public const CHAIN_ID = 'event-webhook-delivery';
    public const EVENT_TYPE = 'acceptance.fixture.event';
    public const CONFIRMATION = 'I_CONFIRM_EMIT_ONLY_THIS_ACCEPTANCE_EVENT';

    private readonly AcceptanceFixtureWebhookEventStore $store;
    private readonly Closure $enqueue;
    private readonly Closure $audit;

    public function __construct(
        ?AcceptanceFixtureWebhookEventStore $store = null,
        ?Closure $enqueue = null,
        ?Closure $audit = null,
    ) {
        $this->store = $store ?? new DatabaseAcceptanceFixtureWebhookEventStore();
        $this->enqueue = $enqueue ?? static fn (int $endpointId, int $applicationId, string $eventId): array =>
            (new WebhookService())->enqueueForEndpoint(
                $endpointId,
                $applicationId,
                self::EVENT_TYPE,
                ['fixture' => ['endpoint_id' => $endpointId]],
                $eventId,
                $eventId,
            );
        $this->audit = $audit ?? static function (
            int $adminId,
            int $organizationId,
            int $applicationId,
            string $requestId,
            string $action,
            string $outcome,
            array $context,
        ): void {
            (new AuditWriter())->write(
                'admin',
                (string) $adminId,
                $organizationId,
                $applicationId,
                $action,
                'acceptance_fixture',
                null,
                $outcome,
                $requestId,
                $context,
            );
        };
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function trigger(array $payload, int $adminId, string $headerRequestId): array
    {
        $request = $this->request($payload, $headerRequestId);
        try {
            return $this->store->transaction(function () use ($request, $adminId): array {
                $application = $this->store->application($request['application_id'], true);
                if ($application === null || (int) ($application['organization_id'] ?? 0) !== $request['organization_id'] || (int) ($application['status'] ?? 0) !== 1) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: 指定接入应用不属于已启用的客户主体边界', 400);
                }
                $endpoint = $this->store->endpoint($request['endpoint_id'], $request['application_id'], true);
                if ($endpoint === null
                    || (int) ($endpoint['status'] ?? 0) !== 1
                    || !str_starts_with((string) ($endpoint['code'] ?? ''), $request['prefix'])
                    || !$this->subscribesToFixtureEvent($endpoint['event_types'] ?? null)) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SCOPE_DENIED: Webhook 端点不是本轮已启用的验收事件订阅端点', 400);
                }
                if ($this->store->creationAuditIds($request['endpoint_request_id'], $request['endpoint_id'], $request['prefix']) !== [$request['endpoint_id']]) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OWNERSHIP_DENIED: Webhook 端点缺少本轮精确创建审计，拒绝向预置端点投递', 400);
                }
                $enqueued = ($this->enqueue)($request['endpoint_id'], $request['application_id'], $request['request_id']);
                $deliveries = $this->store->deliveries($request['endpoint_id'], $request['application_id'], $request['request_id'], true);
                $deliveryIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $deliveries)));
                sort($deliveryIds);
                if ($deliveryIds === [] || count($deliveryIds) !== 1 || (($enqueued['delivery_ids'] ?? null) !== $deliveryIds)) {
                    throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_DELIVERY_INVALID: 专用验收事件未产生唯一可追溯投递', 400);
                }
                ($this->audit)(
                    $adminId,
                    $request['organization_id'],
                    $request['application_id'],
                    $request['request_id'],
                    'acceptance_fixture.webhook_event',
                    'succeeded',
                    [
                        'chain_id' => self::CHAIN_ID,
                        'prefix_sha256' => hash('sha256', $request['prefix']),
                        'endpoint_id' => $request['endpoint_id'],
                        'delivery_count' => count($deliveryIds),
                    ],
                );
                return [
                    'chain_id' => self::CHAIN_ID,
                    'event_type' => self::EVENT_TYPE,
                    'event_id' => $request['request_id'],
                    'endpoint_id' => $request['endpoint_id'],
                    'delivery_ids' => $deliveryIds,
                    'replayed' => (bool) ($enqueued['replayed'] ?? false),
                ];
            });
        } catch (\Throwable $exception) {
            try {
                ($this->audit)(
                    $adminId,
                    $request['organization_id'],
                    $request['application_id'],
                    $request['request_id'],
                    'acceptance_fixture.webhook_event',
                    'failed',
                    ['chain_id' => self::CHAIN_ID, 'prefix_sha256' => hash('sha256', $request['prefix'])],
                );
            } catch (\Throwable) {
                // The validated trigger failure remains authoritative when its audit cannot be written.
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $payload @return array{organization_id:int,application_id:int,endpoint_id:int,endpoint_request_id:string,prefix:string,request_id:string} */
    private function request(array $payload, string $headerRequestId): array
    {
        if (($payload['chain_id'] ?? null) !== self::CHAIN_ID) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_CHAIN_UNSUPPORTED: 仅支持事件通知与投递验收链', 400);
        $prefix = trim((string) ($payload['prefix'] ?? ''));
        if (preg_match(AcceptanceFixtureService::PREFIX_PATTERN, $prefix) !== 1) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_PREFIX_INVALID: 验收前缀必须精确使用本轮 16 位十六进制标识', 400);
        if (($payload['confirmation'] ?? null) !== self::CONFIRMATION) throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_CONFIRMATION_REQUIRED: 请确认只触发本轮专用验收事件', 400);
        $requestId = $this->requestId($payload['request_id'] ?? null, '验收事件');
        if (!str_starts_with($requestId, $prefix) || $requestId !== $this->requestId($headerRequestId, '请求头')) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUEST_MISMATCH: 请求头与本轮验收事件请求编号必须精确一致', 400);
        }
        $endpointRequestId = $this->requestId($payload['endpoint_request_id'] ?? null, '端点创建');
        if (!str_starts_with($endpointRequestId, $prefix) || $endpointRequestId === $requestId) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: 端点创建与验收事件必须使用不同的本轮请求编号', 400);
        }
        return [
            'organization_id' => $this->positiveId($payload['organization_id'] ?? null),
            'application_id' => $this->positiveId($payload['application_id'] ?? null),
            'endpoint_id' => $this->positiveId($payload['endpoint_id'] ?? null),
            'endpoint_request_id' => $endpointRequestId,
            'prefix' => $prefix,
            'request_id' => $requestId,
        ];
    }

    private function subscribesToFixtureEvent(mixed $types): bool
    {
        if (is_string($types)) {
            try { $types = json_decode($types, true, 32, JSON_THROW_ON_ERROR); } catch (\Throwable) { return false; }
        }
        return is_array($types) && in_array(self::EVENT_TYPE, $types, true);
    }

    private function requestId(mixed $value, string $label): string
    {
        $requestId = trim((string) $value);
        if (preg_match(AcceptanceFixtureService::REQUEST_ID_PATTERN, $requestId) !== 1) {
            throw new ApiException("SAND_IAM_ACCEPTANCE_FIXTURE_REQUESTS_INVALID: {$label}请求编号必须使用完整本轮验收前缀", 400);
        }
        return RequestId::normalize($requestId);
    }

    private function positiveId(mixed $value): int
    {
        $text = is_int($value) ? (string) $value : trim((string) $value);
        $maximum = (string) PHP_INT_MAX;
        if (preg_match('/^[1-9]\d*$/', $text) !== 1 || strlen($text) > strlen($maximum) || (strlen($text) === strlen($maximum) && strcmp($text, $maximum) > 0)) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_OBJECTS_INVALID: 对象编号必须是正整数', 400);
        }
        return (int) $text;
    }
}
