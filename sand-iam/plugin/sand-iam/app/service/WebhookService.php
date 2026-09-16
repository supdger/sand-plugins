<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\WebhookDelivery;
use plugin\SandIam\app\model\WebhookEndpoint;
use plugin\SandIam\app\webhook\NativeWebhookHttpAdapter;
use plugin\SandIam\app\webhook\WebhookHttpAdapter;
use plugin\SandIam\app\webhook\EventCatalog;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class WebhookService
{
    public function __construct(
        private readonly WebhookSecretCipher $cipher = new WebhookSecretCipher(),
        private readonly WebhookHttpAdapter $http = new NativeWebhookHttpAdapter(),
        private readonly AuditWriter $auditWriter = new AuditWriter(),
    ) {}

    /** @param list<string> $eventTypes @return array{id:int,secret:string,secret_version:int} */
    public function createEndpoint(
        int $applicationId,
        string $code,
        string $name,
        string $url,
        array $eventTypes,
        int $timeoutSeconds,
        int $maxAttempts,
        string $requestId,
    ): array {
        [$application, $organization] = $this->application($applicationId, false);
        $code = $this->code($code);
        $name = $this->name($name);
        $url = $this->url($url);
        $eventTypes = $this->eventTypes($eventTypes);
        $timeoutSeconds = $this->timeout($timeoutSeconds);
        $maxAttempts = $this->attempts($maxAttempts);
        $secret = 'siwh_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::startTrans();
        try {
            $application = Application::where('id', (int) $application->id)->where('status', 1)->lock(true)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
            if ($application === null || $organization === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND', 404);
            $endpoint = WebhookEndpoint::create([
                'application_id' => (int) $application->id,
                'code' => $code,
                'name' => $name,
                'url' => $url,
                'encrypted_secret' => $this->cipher->encrypt($secret),
                'secret_version' => 1,
                'event_types' => $eventTypes,
                'timeout_seconds' => $timeoutSeconds,
                'max_attempts' => $maxAttempts,
                'status' => 1,
            ]);
            $this->auditWriter->write('admin', 'control_plane', (int) $organization->id, (int) $application->id, 'webhook.create', 'webhook_endpoint', (int) $endpoint->id, 'succeeded', $this->requestId($requestId), ['event_types' => $eventTypes]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_WEBHOOK_CONFLICT: 当前应用已存在相同系统代码', 409);
            throw $exception;
        }
        return ['id' => (int) $endpoint->id, 'secret' => $secret, 'secret_version' => 1];
    }

    /** @param list<string> $eventTypes */
    public function updateEndpoint(
        int $endpointId,
        int $applicationId,
        string $name,
        string $url,
        array $eventTypes,
        int $timeoutSeconds,
        int $maxAttempts,
        string $requestId,
    ): void {
        [$application, $organization] = $this->application($applicationId, false);
        $name = $this->name($name);
        $url = $this->url($url);
        $eventTypes = $this->eventTypes($eventTypes);
        $timeoutSeconds = $this->timeout($timeoutSeconds);
        $maxAttempts = $this->attempts($maxAttempts);
        Db::startTrans();
        try {
            $application = Application::where('id', (int) $application->id)->where('status', 1)->lock(true)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
            $endpoint = $application === null ? null : WebhookEndpoint::where('id', $endpointId)->where('application_id', (int) $application->id)->lock(true)->find();
            if ($application === null || $organization === null || $endpoint === null) throw new ApiException('SAND_IAM_WEBHOOK_NOT_FOUND', 404);
            $endpoint->save(['name' => $name, 'url' => $url, 'event_types' => $eventTypes, 'timeout_seconds' => $timeoutSeconds, 'max_attempts' => $maxAttempts]);
            $this->auditWriter->write('admin', 'control_plane', (int) $organization->id, (int) $application->id, 'webhook.update', 'webhook_endpoint', (int) $endpoint->id, 'succeeded', $this->requestId($requestId), ['event_types' => $eventTypes]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /** @return array{secret:string,secret_version:int} */
    public function rotateSecret(int $endpointId, int $applicationId, string $requestId): array
    {
        [$application, $organization] = $this->application($applicationId, false);
        $secret = 'siwh_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::startTrans();
        try {
            $application = Application::where('id', (int) $application->id)->where('status', 1)->lock(true)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
            $endpoint = $application === null ? null : WebhookEndpoint::where('id', $endpointId)->where('application_id', (int) $application->id)->where('status', 1)->lock(true)->find();
            if ($application === null || $organization === null || $endpoint === null) throw new ApiException('SAND_IAM_WEBHOOK_NOT_FOUND', 404);
            $version = (int) $endpoint->secret_version + 1;
            $endpoint->save(['encrypted_secret' => $this->cipher->encrypt($secret), 'secret_version' => $version]);
            $this->auditWriter->write('admin', 'control_plane', (int) $organization->id, (int) $application->id, 'webhook.secret_rotate', 'webhook_endpoint', (int) $endpoint->id, 'succeeded', $this->requestId($requestId), ['secret_version' => $version]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return ['secret' => $secret, 'secret_version' => $version];
    }

    public function disableEndpoint(int $endpointId, int $applicationId, string $requestId): void
    {
        [$application, $organization] = $this->application($applicationId, false);
        Db::startTrans();
        try {
            $application = Application::where('id', (int) $application->id)->where('status', 1)->lock(true)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
            $endpoint = $application === null ? null : WebhookEndpoint::where('id', $endpointId)->where('application_id', (int) $application->id)->lock(true)->find();
            if ($application === null || $organization === null || $endpoint === null) throw new ApiException('SAND_IAM_WEBHOOK_NOT_FOUND', 404);
            $endpoint->save(['status' => 2, 'disabled_time' => date('Y-m-d H:i:s')]);
            $this->auditWriter->write('admin', 'control_plane', (int) $organization->id, (int) $application->id, 'webhook.disable', 'webhook_endpoint', (int) $endpoint->id, 'succeeded', $this->requestId($requestId));
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $data */
    public function enqueue(int $applicationId, string $eventType, array $data, string $eventId = ''): string
    {
        [$application] = $this->application($applicationId, false);
        $eventType = $this->eventType($eventType);
        $eventId = $eventId !== '' ? $this->eventId($eventId) : 'evt_' . bin2hex(random_bytes(16));
        $this->assertSafePayload($data);
        $envelope = [
            'id' => $eventId,
            'type' => $eventType,
            'occurred_at' => gmdate('c'),
            'application_id' => (int) $application->id,
            'data' => $data,
        ];
        Db::startTrans();
        try {
            $application = Application::where('id', (int) $application->id)->where('status', 1)->lock(true)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
            if ($application === null || $organization === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND', 404);
            $endpoints = WebhookEndpoint::where('application_id', (int) $application->id)->where('status', 1)->lock(true)->select()->all();
            foreach ($endpoints as $endpoint) {
                $types = $endpoint->event_types;
                if (is_string($types)) $types = json_decode($types, true);
                if (!is_array($types) || !in_array($eventType, $types, true)) continue;
                $existing = WebhookDelivery::where('webhook_endpoint_id', (int) $endpoint->id)->where('event_id', $eventId)->find();
                if ($existing !== null) continue;
                WebhookDelivery::create([
                        'application_id' => (int) $application->id,
                        'webhook_endpoint_id' => (int) $endpoint->id,
                        'event_id' => $eventId,
                        'event_type' => $eventType,
                        'payload' => $envelope,
                        'status' => 1,
                        'attempt_count' => 0,
                        'next_attempt_time' => date('Y-m-d H:i:s'),
                ]);
            }
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return $eventId;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{event_id:string,delivery_ids:list<int>,replayed:bool}
     */
    public function enqueueForEndpoint(
        int $endpointId,
        int $applicationId,
        string $eventType,
        array $data,
        string $eventId,
        string $requestId,
    ): array {
        [$application, $organization] = $this->application($applicationId, false);
        $eventType = $this->eventType($eventType);
        $eventId = $this->eventId($eventId);
        $this->assertSafePayload($data);
        $envelope = [
            'id' => $eventId,
            'type' => $eventType,
            'occurred_at' => gmdate('c'),
            'application_id' => (int) $application->id,
            'data' => $data,
        ];
        Db::startTrans();
        try {
            $application = Application::where('id', (int) $application->id)->where('status', 1)->lock(true)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
            $endpoint = $application === null ? null : WebhookEndpoint::where('id', $endpointId)->where('application_id', (int) $application->id)->where('status', 1)->lock(true)->find();
            if ($application === null || $organization === null || $endpoint === null) throw new ApiException('SAND_IAM_WEBHOOK_NOT_FOUND', 404);
            $types = $endpoint->event_types;
            if (is_string($types)) $types = json_decode($types, true);
            if (!is_array($types) || !in_array($eventType, $types, true)) throw new ApiException('SAND_IAM_WEBHOOK_EVENT_TYPE_INVALID', 400);
            $delivery = WebhookDelivery::where('webhook_endpoint_id', (int) $endpoint->id)->where('event_id', $eventId)->lock(true)->find();
            $replayed = $delivery !== null;
            if ($delivery === null) {
                $delivery = WebhookDelivery::create([
                    'application_id' => (int) $application->id,
                    'webhook_endpoint_id' => (int) $endpoint->id,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'payload' => $envelope,
                    'status' => 1,
                    'attempt_count' => 0,
                    'next_attempt_time' => date('Y-m-d H:i:s'),
                ]);
            }
            $this->auditWriter->write(
                'admin',
                'acceptance_fixture',
                (int) $organization->id,
                (int) $application->id,
                'webhook.delivery_enqueue',
                'webhook_delivery',
                (int) $delivery->id,
                'succeeded',
                $this->requestId($requestId),
                [
                    'endpoint_id' => (int) $endpoint->id,
                    'event_type' => $eventType,
                    'event_id_sha256' => hash('sha256', $eventId),
                    'replayed' => $replayed,
                ],
            );
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return ['event_id' => $eventId, 'delivery_ids' => [(int) $delivery->id], 'replayed' => $replayed];
    }

    /** @return array{claimed:int,delivered:int,retried:int,dead:int,lease_lost:int} */
    public function deliverBatch(int $limit = 20): array
    {
        $limit = min(max($limit, 1), 100);
        WebhookDelivery::where('status', 2)->where('locked_until', '<', date('Y-m-d H:i:s'))->update(['status' => 1, 'locked_until' => null]);
        $result = ['claimed' => 0, 'delivered' => 0, 'retried' => 0, 'dead' => 0, 'lease_lost' => 0];
        for ($index = 0; $index < $limit; $index++) {
            Db::startTrans();
            try {
                $delivery = WebhookDelivery::where('status', 1)
                    ->where('next_attempt_time', '<=', date('Y-m-d H:i:s'))
                    ->order('id')->lock('FOR UPDATE SKIP LOCKED')->find();
                if ($delivery !== null) $delivery->save(['status' => 2, 'locked_until' => date('Y-m-d H:i:s', time() + 120)]);
                Db::commit();
            } catch (\Throwable $exception) {
                Db::rollback();
                throw $exception;
            }
            if ($delivery === null) break;
            $result['claimed']++;
            $outcome = $this->deliver($delivery);
            $result[$outcome]++;
        }
        return $result;
    }

    public function retry(int $deliveryId, int $applicationId, string $requestId): void
    {
        [$application, $organization] = $this->application($applicationId, false);
        $requestId = $this->requestId($requestId);
        $fingerprint = IdempotencyService::fingerprint([
            'delivery_id' => $deliveryId,
            'application_id' => $applicationId,
        ]);
        Db::startTrans();
        try {
            (new IdempotencyService())->execute(
                'webhook_delivery',
                (string) $deliveryId,
                'webhook.delivery_retry',
                $requestId,
                $fingerprint,
                'webhook_delivery',
                function () use ($application, $organization, $deliveryId, $requestId): array {
                    $lockedApplication = Application::where('id', (int) $application->id)->where('status', 1)->lock(true)->find();
                    $lockedOrganization = $lockedApplication === null ? null : Organization::where('id', (int) $lockedApplication->organization_id)->where('status', 1)->lock(true)->find();
                    $delivery = $lockedApplication === null ? null : WebhookDelivery::where('id', $deliveryId)->where('application_id', (int) $lockedApplication->id)->lock(true)->find();
                    if ($lockedApplication === null || $lockedOrganization === null || $delivery === null || !in_array((int) $delivery->status, [1, 4], true)) {
                        throw new ApiException('SAND_IAM_WEBHOOK_DELIVERY_NOT_RETRYABLE', 409);
                    }
                    // Keep the cumulative attempt number. Resetting it here
                    // makes the next worker attempt reuse the first attempt's
                    // audit request id, so a successful manual retry can be
                    // delivered without a persisted success audit.
                    $delivery->save(['status' => 1, 'next_attempt_time' => date('Y-m-d H:i:s'), 'locked_until' => null, 'last_error_code' => null]);
                    $this->auditWriter->write(
                        'admin',
                        'control_plane',
                        (int) $lockedOrganization->id,
                        (int) $lockedApplication->id,
                        'webhook.delivery_retry',
                        'webhook_delivery',
                        (int) $delivery->id,
                        'succeeded',
                        $requestId,
                        ['event_id_sha256' => hash('sha256', (string) $delivery->event_id)],
                    );
                    return ['resource_id' => (int) $delivery->id, 'result' => []];
                },
                0,
                false,
            );
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /** @return 'delivered'|'retried'|'dead'|'lease_lost' */
    private function deliver(WebhookDelivery $claimed): string
    {
        $delivery = WebhookDelivery::where('id', (int) $claimed->id)->where('status', 2)
            ->where('locked_until', (string) $claimed->locked_until)
            ->where('locked_until', '>', date('Y-m-d H:i:s'))->find();
        $endpoint = $delivery === null ? null : WebhookEndpoint::where('id', (int) $delivery->webhook_endpoint_id)->where('application_id', (int) $delivery->application_id)->where('status', 1)->find();
        if ($delivery === null) return 'lease_lost';
        $attempt = (int) $delivery->attempt_count + 1;
        if ($endpoint === null) return $this->failDelivery($delivery, null, $attempt, 'SAND_IAM_WEBHOOK_ENDPOINT_DISABLED');
        $payload = $delivery->payload;
        if (is_string($payload)) $payload = json_decode($payload, true);
        if (!is_array($payload)) return $this->failDelivery($delivery, $endpoint, $attempt, 'SAND_IAM_WEBHOOK_PAYLOAD_INVALID');
        try {
            $eventType = $this->eventType((string) $delivery->event_type);
        } catch (ApiException) {
            return $this->failDelivery($delivery, $endpoint, $attempt, 'SAND_IAM_WEBHOOK_EVENT_TYPE_INVALID');
        }
        if (($payload['type'] ?? null) !== $eventType) {
            return $this->failDelivery($delivery, $endpoint, $attempt, 'SAND_IAM_WEBHOOK_PAYLOAD_INVALID');
        }
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) time();
            $secret = $this->cipher->decrypt((string) $endpoint->encrypted_secret);
            $response = $this->http->post((string) $endpoint->url, [
                'X-SandIAM-Event-Id' => (string) $delivery->event_id,
                'X-SandIAM-Event-Type' => $eventType,
                'X-SandIAM-Timestamp' => $timestamp,
                'X-SandIAM-Secret-Version' => (string) $endpoint->secret_version,
                'X-SandIAM-Signature' => 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
            ], $body, (int) $endpoint->timeout_seconds);
            if ($response['status'] < 200 || $response['status'] >= 300) {
                return $this->failDelivery($delivery, $endpoint, $attempt, 'SAND_IAM_WEBHOOK_HTTP_' . $response['status'], $response['status'], $response['body']);
            }
            if (!$this->saveClaimedDelivery($delivery, ['status' => 3, 'attempt_count' => $attempt, 'delivered_time' => date('Y-m-d H:i:s'), 'locked_until' => null, 'response_status' => $response['status'], 'response_digest' => hash('sha256', $response['body']), 'last_error_code' => null])) return 'lease_lost';
            $this->deliveryAudit($delivery, $endpoint, 'delivered', ['attempt' => $attempt, 'response_status' => $response['status']]);
            return 'delivered';
        } catch (\Throwable $exception) {
            $code = $exception instanceof ApiException && preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $exception->getMessage(), $match) ? $match[1] : 'SAND_IAM_WEBHOOK_DELIVERY_FAILED';
            return $this->failDelivery($delivery, $endpoint, $attempt, $code);
        }
    }

    /** @return 'retried'|'dead'|'lease_lost' */
    private function failDelivery(WebhookDelivery $delivery, ?WebhookEndpoint $endpoint, int $attempt, string $code, ?int $status = null, string $body = ''): string
    {
        $maxAttempts = $endpoint === null ? 1 : (int) $endpoint->max_attempts;
        $dead = $attempt >= $maxAttempts;
        $delay = [60, 300, 1800, 7200, 43200][min($attempt - 1, 4)];
        $saved = $this->saveClaimedDelivery($delivery, [
            'status' => $dead ? 4 : 1,
            'attempt_count' => $attempt,
            'next_attempt_time' => $dead ? null : date('Y-m-d H:i:s', time() + $delay),
            'locked_until' => null,
            'response_status' => $status,
            'response_digest' => $body === '' ? null : hash('sha256', $body),
            'last_error_code' => $code,
        ]);
        if (!$saved) return 'lease_lost';
        if ($endpoint !== null) $this->deliveryAudit($delivery, $endpoint, $dead ? 'dead' : 'retry_scheduled', ['attempt' => $attempt, 'code' => $code]);
        return $dead ? 'dead' : 'retried';
    }

    /** @param array<string,mixed> $values */
    private function saveClaimedDelivery(WebhookDelivery $delivery, array $values): bool
    {
        return WebhookDelivery::where('id', (int) $delivery->id)->where('status', 2)
            ->where('locked_until', (string) $delivery->locked_until)->update($values) === 1;
    }

    /** @param array<string,mixed> $context */
    private function deliveryAudit(WebhookDelivery $delivery, WebhookEndpoint $endpoint, string $deliveryState, array $context): void
    {
        try {
            $application = Application::find((int) $delivery->application_id);
            $attempt = (int) ($context['attempt'] ?? $delivery->attempt_count);
            $requestId = hash('sha256', "webhook.delivery\0" . (string) $delivery->event_id . "\0" . $attempt);
            $outcome = $deliveryState === 'delivered' ? 'succeeded' : 'failed';
            $this->auditWriter->write(
                'system',
                'webhook_worker',
                $application ? (int) $application->organization_id : null,
                (int) $delivery->application_id,
                'webhook.delivery',
                'webhook_delivery',
                (int) $delivery->id,
                $outcome,
                $requestId,
                $context + [
                    'endpoint_id' => (int) $endpoint->id,
                    'event_id_sha256' => hash('sha256', (string) $delivery->event_id),
                    'delivery_state' => $deliveryState,
                ],
            );
        } catch (\Throwable) {
            // The persisted delivery state remains authoritative if audit storage is unavailable.
        }
    }

    /** @return array{0:Application,1:Organization} */
    private function application(int $applicationId, bool $lock): array
    {
        $applicationQuery = Application::where('id', $applicationId)->where('status', 1);
        if ($lock) $applicationQuery->lock(true);
        $application = $applicationQuery->find();
        $organizationQuery = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1);
        if ($organizationQuery !== null && $lock) $organizationQuery->lock(true);
        $organization = $organizationQuery?->find();
        if ($application === null || $organization === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND', 404);
        return [$application, $organization];
    }

    /** @param list<string> $eventTypes @return list<string> */
    private function eventTypes(array $eventTypes): array
    {
        if ($eventTypes === [] || count($eventTypes) > 64) throw new ApiException('SAND_IAM_WEBHOOK_EVENT_TYPES_INVALID', 400);
        $validated = [];
        foreach ($eventTypes as $eventType) {
            if (!is_string($eventType)) throw new ApiException('SAND_IAM_WEBHOOK_EVENT_TYPES_INVALID', 400);
            $validated[] = $this->eventType($eventType);
        }
        return array_values(array_unique($validated));
    }

    private function eventType(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-z][a-z0-9_.:-]{2,95}$/', $value)) throw new ApiException('SAND_IAM_WEBHOOK_EVENT_TYPES_INVALID', 400);
        EventCatalog::get($value);
        return $value;
    }
    private function eventId(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $value)) throw new ApiException('SAND_IAM_WEBHOOK_EVENT_ID_INVALID', 400);
        return $value;
    }
    private function code(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $value)) throw new ApiException('SAND_IAM_WEBHOOK_CODE_INVALID', 400);
        return $value;
    }
    private function name(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 128 || !preg_match('//u', $value) || preg_match('/[\p{Cc}]/u', $value)) throw new ApiException('SAND_IAM_WEBHOOK_NAME_INVALID', 400);
        return $value;
    }
    private function url(string $value): string
    {
        $value = trim($value);
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || strlen($value) > 500) throw new ApiException('SAND_IAM_WEBHOOK_URL_INVALID', 400);
        return $value;
    }
    private function timeout(int $value): int
    {
        if ($value < 1 || $value > 30) throw new ApiException('SAND_IAM_WEBHOOK_TIMEOUT_INVALID', 400);
        return $value;
    }
    private function attempts(int $value): int
    {
        if ($value < 1 || $value > 10) throw new ApiException('SAND_IAM_WEBHOOK_ATTEMPTS_INVALID', 400);
        return $value;
    }
    /** @param array<string,mixed> $payload */
    private function assertSafePayload(array $payload): void
    {
        $blocked = ['password', 'current_password', 'new_password', 'access_token', 'refresh_token', 'authorization', 'client_secret', 'secret', 'encrypted_config', 'saml_response', 'credential'];
        $walk = static function (array $value, int $depth) use (&$walk, $blocked): void {
            if ($depth > 8) throw new ApiException('SAND_IAM_WEBHOOK_PAYLOAD_INVALID', 400);
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), $blocked, true)) {
                    throw new ApiException('SAND_IAM_WEBHOOK_PAYLOAD_SENSITIVE', 400);
                }
                if (is_array($item)) $walk($item, $depth + 1);
                elseif (!is_scalar($item) && $item !== null) throw new ApiException('SAND_IAM_WEBHOOK_PAYLOAD_INVALID', 400);
            }
        };
        $walk($payload, 0);
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable) {
            throw new ApiException('SAND_IAM_WEBHOOK_PAYLOAD_INVALID', 400);
        }
        if (strlen($encoded) > 65_536) throw new ApiException('SAND_IAM_WEBHOOK_PAYLOAD_TOO_LARGE', 413);
    }
    private function requestId(string $value): string { return RequestId::normalize($value); }
}
