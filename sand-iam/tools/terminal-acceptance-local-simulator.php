#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Local, database-free counterpart. --serve is TLS, loopback and per-run-session only. */
final class TerminalAcceptanceSimulator
{
    private const CREDENTIAL = 'simulator-credential';
    private const ACTION = 'document.read';
    private const EVENT_NAME = 'acceptance-fixture-webhook-delivery';
    private const EVENT_TYPE = 'acceptance.fixture.event';
    private const LOCK_SECONDS = 15;
    private const MAX_POSITIVE_ID = 2147483647;
    /** @var list<array<string,mixed>> */ private array $effects = [];
    /** @var array<int,string> */ private array $endpoints = [];
    /** @var array<string,array<string,mixed>> */ private array $deliveries = [];
    /** @var array<string,string> */ private array $byEndpointEvent = [];
    /** @var array<string,true> */ private array $revoked = [];
    private int $nextEndpoint = 1;
    private int $nextDelivery = 1;
    /** @var Closure():int */ private readonly Closure $clock;

    public function __construct(?Closure $clock = null) { $this->clock = $clock ?? static fn (): int => time(); }
    public static function pkce(string $verifier): string { return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='); }
    /** @return array{status:int,body:string} */
    public function oauthRpCallback(string $state, string $code, string $verifier): array
    {
        if ($state === '' || $code === '' || $verifier === '') return $this->problem(400, 'PKCE callback invalid');
        $this->effects[] = ['kind' => 'oauth_rp', 'state' => $state, 'code' => $code, 'pkce_s256' => self::pkce($verifier)];
        return $this->json(200, ['code' => 0, 'result' => 'pkce callback accepted']);
    }
    /** @return array{status:int,body:string} */
    public function casClient(string $ticket, string $service): array
    {
        if (!str_starts_with($ticket, 'ST-') || !str_starts_with($service, 'https://')) return $this->problem(401, 'CAS ticket invalid');
        $this->effects[] = ['kind' => 'cas_client', 'ticket' => $ticket, 'service' => $service];
        return $this->json(200, ['code' => 0, 'result' => 'CAS ticket accepted']);
    }
    /** @return array{status:int,body:string} */
    public function provider(array $payload, string $credential, string $requestId): array
    {
        $action = (string) ($payload['action'] ?? '');
        if ($credential !== self::CREDENTIAL || isset($this->revoked[$credential])) { $this->effects[] = ['kind' => 'provider_authorization', 'action' => $action, 'request_id' => $requestId, 'outcome' => 'revoked']; return $this->json(401, ['accepted' => false]); }
        if ($action !== self::ACTION) { $this->effects[] = ['kind' => 'provider_authorization', 'action' => $action, 'request_id' => $requestId, 'outcome' => 'ungranted']; return $this->json(403, ['accepted' => false]); }
        $this->effects[] = ['kind' => 'provider_authorization', 'action' => $action, 'request_id' => $requestId, 'outcome' => 'allowed'];
        return $this->json(200, ['accepted' => true, 'recorded_request_id' => $requestId]);
    }
    public function revokeProviderCredential(string $credential): void { $this->revoked[$credential] = true; }
    /** @param array<string,mixed> $payload @return array{status:int,body:string} */
    public function endpoint(array $payload): array
    {
        if (preg_match('/^sand_iam_acceptance_[a-f0-9]{16}_$/', (string) ($payload['prefix'] ?? '')) !== 1) return $this->problem(400, 'acceptance prefix invalid');
        if (!$this->knownEvent($payload)) return $this->problem(400, 'unknown event_name or event_type');
        $id = $this->nextEndpoint++; $this->endpoints[$id] = 'enabled'; $this->effects[] = ['kind' => 'webhook_fixture_endpoint', 'endpoint_id' => $id];
        return $this->json(201, ['endpoint_id' => $id, 'event_name' => self::EVENT_NAME, 'event_type' => self::EVENT_TYPE]);
    }
    /** @param array<string,mixed> $payload @return array{status:int,body:string} */
    public function event(array $payload): array
    {
        $endpointId = $this->positiveId($payload['endpoint_id'] ?? null); $eventId = (string) ($payload['event_id'] ?? '');
        if ($endpointId === 0) return $this->problem(400, 'endpoint_id must be a bounded positive integer');
        if (($this->endpoints[$endpointId] ?? null) !== 'enabled' || preg_match('/^sand_iam_acceptance_[a-f0-9]{16}_[A-Za-z0-9][A-Za-z0-9_.:-]{0,58}$/', $eventId) !== 1) return $this->problem(400, 'dedicated webhook event is not bound to the enabled fixture endpoint');
        if (!$this->knownEvent($payload)) return $this->problem(400, 'unknown event_name or event_type');
        $key = $endpointId . ':' . $eventId;
        if (isset($this->byEndpointEvent[$key])) return $this->json(200, ['endpoint_id' => $endpointId, 'delivery_id' => $this->byEndpointEvent[$key], 'replayed' => true]);
        $deliveryId = $eventId . '-delivery-' . $this->nextDelivery++; $this->byEndpointEvent[$key] = $deliveryId;
        $this->deliveries[$deliveryId] = ['endpoint_id' => $endpointId, 'event_id' => $eventId, 'status' => 'queued', 'attempt_count' => 0, 'first_status' => null, 'last_status' => null, 'signature_verified' => false, 'locked_until' => null, 'worker_runs' => 0];
        $this->effects[] = ['kind' => 'webhook_fixture_event', 'endpoint_id' => $endpointId, 'delivery_id' => $deliveryId, 'event_id' => $eventId];
        return $this->json(201, ['endpoint_id' => $endpointId, 'delivery_id' => $deliveryId, 'replayed' => false]);
    }
    /** @param array<string,mixed> $payload @return array{status:int,body:string} */
    public function worker(array $payload, string $secret): array
    {
        $id = (string) ($payload['delivery_id'] ?? ''); $delivery = $this->deliveries[$id] ?? null; $now = ($this->clock)();
        if ($delivery === null || $secret === '') return $this->problem(400, 'delivery or webhook secret invalid');
        if ($delivery['status'] === 'dispatching' && $delivery['locked_until'] > $now) return $this->problem(409, 'delivery worker lock is active');
        if ($delivery['status'] === 'dispatching') { $delivery['status'] = 'queued'; $delivery['locked_until'] = null; $this->effects[] = ['kind' => 'webhook_worker_lock_recovered', 'delivery_id' => $id]; }
        if (!in_array($delivery['status'], ['queued', 'retry_queued'], true)) return $this->problem(409, 'delivery is not runnable');
        $body = json_encode(['event_name' => self::EVENT_NAME, 'event_type' => self::EVENT_TYPE, 'data' => ['endpoint_id' => $delivery['endpoint_id'], 'delivery_id' => $id]], JSON_THROW_ON_ERROR); $timestamp = (string) $now;
        $delivery['status'] = 'dispatching'; $delivery['locked_until'] = $now + self::LOCK_SECONDS; $delivery['worker_runs']++;
        $this->deliveries[$id] = $delivery; $this->effects[] = ['kind' => 'webhook_worker_dispatch', 'delivery_id' => $id, 'attempt' => $delivery['worker_runs']];
        return $this->json(200, ['endpoint_id' => $delivery['endpoint_id'], 'delivery_id' => $id, 'headers' => ['x-sandiam-event-id' => $delivery['event_id'], 'x-sandiam-timestamp' => $timestamp, 'x-sandiam-signature' => 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret)], 'body' => $body]);
    }
    /** @return array{status:int,body:string} */
    public function receiver(string $eventId, string $timestamp, string $body, string $signature, string $secret): array
    {
        if ($eventId === '' || !ctype_digit($timestamp) || $secret === '') return $this->problem(400, 'webhook headers invalid');
        if (abs(($this->clock)() - (int) $timestamp) > 300) return $this->problem(401, 'webhook timestamp invalid');
        $expected = 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        if (!str_starts_with($signature, 'v1=') || !hash_equals($expected, $signature)) return $this->problem(401, 'webhook signature invalid');
        try { $event = json_decode($body, true, 16, JSON_THROW_ON_ERROR); } catch (Throwable) { return $this->problem(400, 'webhook body invalid'); }
        if (!is_array($event) || !$this->knownEvent($event)) return $this->problem(400, 'unknown event_name or event_type');
        $id = $this->deliveryForEvent($eventId); $delivery = $id === null ? null : $this->deliveries[$id];
        if ($delivery === null || $delivery['status'] !== 'dispatching') return $this->problem(409, 'delivery not runnable');
        $delivery['attempt_count']++; $delivery['signature_verified'] = true; $delivery['locked_until'] = null;
        if ($delivery['attempt_count'] === 1) { $delivery['status'] = 'retryable'; $delivery['first_status'] = 500; $delivery['last_status'] = 500; $this->deliveries[$id] = $delivery; $this->effects[] = ['kind' => 'webhook_receiver', 'delivery_id' => $id, 'status' => 500, 'signature_verified' => true]; return $this->problem(500, 'retry'); }
        $delivery['status'] = 'delivered'; $delivery['last_status'] = 204; $this->deliveries[$id] = $delivery; $this->effects[] = ['kind' => 'webhook_receiver', 'delivery_id' => $id, 'status' => 204, 'signature_verified' => true];
        return ['status' => 204, 'body' => ''];
    }
    /** @param array<string,mixed> $payload @return array{status:int,body:string} */
    public function retry(array $payload): array
    {
        $id = (string) ($payload['delivery_id'] ?? ''); $delivery = $this->deliveries[$id] ?? null;
        if ($delivery === null || $delivery['last_status'] !== 500) return $this->problem(409, 'only a recorded first 500 delivery may be retried');
        if ($delivery['status'] === 'retry_queued') return $this->json(200, ['delivery_id' => $id, 'replayed' => true]);
        if ($delivery['status'] !== 'retryable') return $this->problem(409, 'delivery retry is not runnable');
        $delivery['status'] = 'retry_queued'; $this->deliveries[$id] = $delivery; $this->effects[] = ['kind' => 'webhook_retry', 'delivery_id' => $id];
        return $this->json(200, ['delivery_id' => $id, 'replayed' => false]);
    }
    /** @return array{endpoint_id:int,delivery_id:string,signature_verified:bool,attempt_count:int,first_status:int,last_status:int} */
    public function proof(int $endpointId, string $id): array
    {
        $delivery = $this->deliveries[$id] ?? null;
        if ($endpointId < 1 || $delivery === null || $delivery['endpoint_id'] !== $endpointId || $delivery['status'] !== 'delivered' || $delivery['attempt_count'] !== 2 || $delivery['first_status'] !== 500 || $delivery['last_status'] !== 204 || !$delivery['signature_verified']) throw new RuntimeException('webhook retry proof is incomplete');
        return ['endpoint_id' => $endpointId, 'delivery_id' => $id, 'signature_verified' => true, 'attempt_count' => 2, 'first_status' => 500, 'last_status' => 204];
    }
    /** @param array<string,mixed> $payload @return array{status:int,body:string} */
    public function cleanup(array $payload): array
    {
        $endpointId = $this->positiveId($payload['endpoint_id'] ?? null); $id = (string) ($payload['delivery_id'] ?? ''); $delivery = $this->deliveries[$id] ?? null;
        if (($this->endpoints[$endpointId] ?? null) !== 'enabled' || $delivery === null || $delivery['endpoint_id'] !== $endpointId) return $this->problem(409, 'webhook cleanup requires the exact enabled endpoint and delivery');
        if ($delivery['status'] !== 'delivered') { $this->effects[] = ['kind' => 'webhook_fixture_cleanup_failed', 'endpoint_id' => $endpointId, 'delivery_id' => $id, 'reason' => 'DRAIN_REQUIRED']; return $this->problem(409, 'DRAIN_REQUIRED: delivery must be drained before cleanup'); }
        $this->endpoints[$endpointId] = 'disabled'; unset($this->deliveries[$id], $this->endpoints[$endpointId]);
        foreach ($this->byEndpointEvent as $key => $deliveryId) if ($deliveryId === $id) unset($this->byEndpointEvent[$key]);
        $this->effects[] = ['kind' => 'webhook_fixture_cleanup', 'endpoint_id' => $endpointId, 'delivery_id' => $id];
        return $this->json(200, ['endpoint_id' => $endpointId, 'delivery_id' => $id, 'residual' => $this->webhookState()]);
    }
    /** @return list<array<string,mixed>> */ public function effects(): array { return $this->effects; }
    /** @return array{endpoint_ids:list<int>,delivery_ids:list<string>} */
    public function webhookState(): array
    {
        $endpointIds = array_map('intval', array_keys($this->endpoints));
        $deliveryIds = array_map('strval', array_keys($this->deliveries));
        sort($endpointIds, SORT_NUMERIC); sort($deliveryIds, SORT_STRING);
        return ['endpoint_ids' => $endpointIds, 'delivery_ids' => $deliveryIds];
    }
    private function knownEvent(array $event): bool { return ($event['event_name'] ?? null) === self::EVENT_NAME && ($event['event_type'] ?? null) === self::EVENT_TYPE; }
    private function deliveryForEvent(string $eventId): ?string { foreach ($this->deliveries as $id => $delivery) if ($delivery['event_id'] === $eventId) return $id; return null; }
    private function positiveId(mixed $value): int
    {
        if (is_int($value)) return $value >= 1 && $value <= self::MAX_POSITIVE_ID ? $value : 0;
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 10 || (strlen($value) === 10 && strcmp($value, (string) self::MAX_POSITIVE_ID) > 0)) return 0;
        $id = (int) $value;
        return $id >= 1 ? $id : 0;
    }
    /** @return array{status:int,body:string} */ private function json(int $status, array $body): array { return ['status' => $status, 'body' => json_encode($body, JSON_THROW_ON_ERROR)]; }
    /** @return array{status:int,body:string} */ private function problem(int $status, string $message): array { return $this->json($status, ['code' => $status, 'message' => $message]); }
}

/**
 * Database-free, service-shaped counterpart of Chain5. It keeps only hashes
 * for protocol credentials and returns only opaque numeric fixture ids.
 */
final class TerminalAcceptanceOAuthCasFixtureStore
{
    /** @var array<string,array<int,array<string,mixed>>> */ public array $rows = [];
    /** @var list<array{action:string,outcome:string}> */ public array $audit = [];
    private int $nextId = 1;
    private bool $locked = false;
    /** @var array<string,array<string,mixed>> */ private array $replay = [];
    /** @var array{application:int,environment:int,identity:int,resource:int}|null */ private ?array $chainFiveScope = null;

    public function create(string $type, array $row): int
    {
        $id = $this->nextId++;
        $this->rows[$type][$id] = ['id' => $id] + $row;
        return $id;
    }

    /** @return list<int> */
    public function ids(string $type): array
    {
        $ids = array_map('intval', array_keys($this->rows[$type] ?? [])); sort($ids, SORT_NUMERIC); return $ids;
    }

    public function transaction(callable $operation): mixed
    {
        $before = $this->rows;
        try { return $operation(); } catch (Throwable $exception) { $this->rows = $before; throw $exception; }
    }

    public function lock(): void { if ($this->locked) throw new RuntimeException('CHAIN5_CLEANUP_LOCKED'); $this->locked = true; }
    public function unlock(): void { $this->locked = false; }
    /** @param array{application:int,environment:int,identity:int,resource:int} $scope */
    public function bindChainFiveScope(array $scope): void { $this->chainFiveScope = $scope; }
    /** @return array{application:int,environment:int,identity:int,resource:int} */
    public function chainFiveScope(): array
    {
        if ($this->chainFiveScope === null) throw new RuntimeException('CHAIN5_SCOPE_REJECTED:unconfigured');
        return $this->chainFiveScope;
    }
    /** @param array<string,mixed> $result */ public function replay(string $requestId, array $result): void { $this->replay[$requestId] = $result; }
    /** @return array<string,mixed>|null */ public function replayed(string $requestId): ?array { return $this->replay[$requestId] ?? null; }
    public function audit(string $action, string $outcome): void { $this->audit[] = compact('action', 'outcome'); }
}

final class TerminalAcceptanceOAuthCasGovernanceService
{
    public function __construct(private readonly TerminalAcceptanceOAuthCasFixtureStore $store) {}

    /** @return array<string,int> */
    public function configure(): array
    {
        $application = $this->store->create('application', ['status' => 1]);
        $environment = $this->store->create('environment', ['application_id' => $application, 'status' => 1]);
        $identity = $this->store->create('identity', ['application_id' => $application, 'status' => 1]);
        $resource = $this->store->create('resource', ['application_id' => $application, 'status' => 1]);
        $this->store->bindChainFiveScope([
            'application' => $application,
            'environment' => $environment,
            'identity' => $identity,
            'resource' => $resource,
        ]);
        return [
            'application' => $application, 'environment' => $environment, 'identity' => $identity, 'resource' => $resource,
            'oauth_client' => $this->store->create('oauth_client', ['application_id' => $application, 'status' => 1, 'secret_hash' => hash('sha256', 'one-time-secret')]),
            'cas_service' => $this->store->create('cas_service', ['application_id' => $application, 'status' => 1]),
            'api_resource' => $this->store->create('api_resource', ['application_id' => $application, 'resource_id' => $resource, 'status' => 1]),
            'api_route_binding' => $this->store->create('api_route_binding', ['application_id' => $application, 'status' => 1]),
            'policy' => $this->store->create('policy', ['application_id' => $application, 'identity_id' => $identity, 'resource_id' => $resource, 'status' => 1, 'published' => false]),
        ];
    }

    /** @return array{access_allowed:bool,wrong_pkce_denied:bool,cas_validated:bool} */
    public function protocols(array $ids): array
    {
        $challenge = TerminalAcceptanceSimulator::pkce('chain5-verifier');
        $correct = hash_equals($challenge, TerminalAcceptanceSimulator::pkce('chain5-verifier'));
        $wrong = !hash_equals($challenge, TerminalAcceptanceSimulator::pkce('chain5-wrong-verifier'));
        $app = $ids['application'];
        $request = $this->store->create('oauth_authorization_request', ['application_id' => $app, 'client_id' => $ids['oauth_client'], 'code' => 'request-code-id', 'status' => 1]);
        $code = $this->store->create('authorization_code', ['application_id' => $app, 'client_id' => $ids['oauth_client'], 'authorization_request_id' => $request, 'request_id' => $request, 'token_hash' => hash('sha256', 'authorization-code'), 'status' => 1]);
        $this->store->create('oauth_consent', ['application_id' => $app, 'client_id' => $ids['oauth_client'], 'authorization_request_id' => $request, 'status' => 1]);
        $grant = $this->store->create('oauth_grant', ['application_id' => $app, 'client_id' => $ids['oauth_client'], 'authorization_code_id' => $code, 'status' => 1]);
        $this->store->create('oauth_token', ['application_id' => $app, 'client_id' => $ids['oauth_client'], 'grant_id' => $grant, 'oauth_grant_id' => $grant, 'token_hash' => hash('sha256', 'access-token'), 'status' => 1]);
        $login = $this->store->create('cas_login_request', ['application_id' => $app, 'cas_service_id' => $ids['cas_service'], 'status' => 1]);
        $this->store->create('cas_ticket', ['application_id' => $app, 'cas_service_id' => $ids['cas_service'], 'cas_login_request_id' => $login, 'ticket_hash' => hash('sha256', 'ticket'), 'status' => 1, 'used' => true]);
        $this->store->audit('oauth.pkce', $correct && $wrong ? 'succeeded' : 'failed');
        $this->store->audit('cas.service_validate', 'succeeded');
        return ['access_allowed' => $correct, 'wrong_pkce_denied' => $wrong, 'cas_validated' => true];
    }

    public function publishPolicy(array $ids): void
    {
        $version = $this->store->create('policy_version', ['application_id' => $ids['application'], 'policy_id' => $ids['policy'], 'rollback_of_version_id' => null, 'status' => 1]);
        $this->store->rows['policy'][$ids['policy']]['published'] = true;
        $this->store->rows['policy'][$ids['policy']]['published_version_id'] = $version;
        $this->store->rows['api_route_binding'][$ids['api_route_binding']]['api_resource_id'] = $ids['api_resource'];
    }

    public function authorize(array $ids): bool
    {
        $allowed = ($this->store->rows['application'][$ids['application']]['status'] ?? 2) === 1
            && ($this->store->rows['environment'][$ids['environment']]['application_id'] ?? 0) === $ids['application']
            && ($this->store->rows['identity'][$ids['identity']]['application_id'] ?? 0) === $ids['application']
            && ($this->store->rows['resource'][$ids['resource']]['application_id'] ?? 0) === $ids['application']
            && ($this->store->rows['api_resource'][$ids['api_resource']]['status'] ?? 2) === 1
            && ($this->store->rows['api_resource'][$ids['api_resource']]['resource_id'] ?? 0) === $ids['resource']
            && ($this->store->rows['api_route_binding'][$ids['api_route_binding']]['api_resource_id'] ?? 0) === $ids['api_resource']
            && ($this->store->rows['policy'][$ids['policy']]['identity_id'] ?? 0) === $ids['identity']
            && ($this->store->rows['policy'][$ids['policy']]['resource_id'] ?? 0) === $ids['resource']
            && ($this->store->rows['policy'][$ids['policy']]['published'] ?? false) === true
            && isset($this->store->rows['policy_version'][$this->store->rows['policy'][$ids['policy']]['published_version_id'] ?? 0])
            && ($this->store->rows['api_route_binding'][$ids['api_route_binding']]['status'] ?? 2) === 1;
        $tokens = array_filter($this->store->rows['oauth_token'] ?? [], static fn (array $row): bool => ($row['client_id'] ?? 0) === $ids['oauth_client'] && ($row['status'] ?? 2) === 1);
        $tickets = array_filter($this->store->rows['cas_ticket'] ?? [], static fn (array $row): bool => ($row['cas_service_id'] ?? 0) === $ids['cas_service'] && ($row['status'] ?? 2) === 1);
        $allowed = $allowed && $tokens !== [] && $tickets !== [] && ($this->store->rows['oauth_client'][$ids['oauth_client']]['status'] ?? 2) === 1 && ($this->store->rows['cas_service'][$ids['cas_service']]['status'] ?? 2) === 1;
        $this->store->audit('authorize.route', $allowed ? 'allowed' : 'denied');
        return $allowed;
    }

    public function revokeOAuth(array $ids): bool
    {
        foreach ($this->store->rows['oauth_token'] ?? [] as $id => $token) if (($token['client_id'] ?? 0) === $ids['oauth_client']) $this->store->rows['oauth_token'][$id]['status'] = 2;
        $this->store->audit('oauth.revoke', 'succeeded');
        return !$this->authorize($ids);
    }

    public function revokePolicyAndRoute(array $ids): void
    {
        $this->store->rows['policy'][$ids['policy']]['status'] = 2;
        $this->store->rows['api_route_binding'][$ids['api_route_binding']]['status'] = 2;
        $this->store->audit('policy.revoke', 'succeeded');
    }

    public function revokeCas(array $ids): bool
    {
        $this->store->rows['cas_service'][$ids['cas_service']]['status'] = 2;
        foreach ($this->store->rows['cas_ticket'] ?? [] as $id => $ticket) if (($ticket['cas_service_id'] ?? 0) === $ids['cas_service']) $this->store->rows['cas_ticket'][$id]['status'] = 2;
        $this->store->audit('cas.revoke', 'succeeded');
        return !$this->authorize($ids);
    }

    public function drain(array $ids): void
    {
        foreach ($this->actual() as $type => $typeIds) foreach ($typeIds as $id) $this->store->rows[$type][$id]['status'] = 2;
        $this->store->audit('acceptance_fixture.drain', 'succeeded');
    }

    /** @return array<string,list<int>> */
    public function actual(): array
    {
        $types = ['oauth_client', 'cas_service', 'api_resource', 'api_route_binding', 'policy', 'oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token', 'cas_login_request', 'cas_ticket', 'policy_version'];
        $actual = [];
        foreach ($types as $type) $actual[$type] = $this->store->ids($type);
        return $actual;
    }

    public function holdCleanupLock(): void { $this->store->lock(); }
    public function releaseCleanupLock(): void { $this->store->unlock(); }

    /** @param array<string,list<int>> $submitted @return array<string,mixed> */
    public function cleanup(array $submitted, string $requestId, ?string $failType = null): array
    {
        if (($replayed = $this->store->replayed($requestId)) !== null) return array_merge($replayed, ['replayed' => true]);
        $locked = false;
        try {
            $this->store->lock();
            $locked = true;
            return $this->store->transaction(function () use ($submitted, $requestId, $failType): array {
                $actual = $this->actual();
                foreach ($actual as $type => $actualIds) {
                    $submittedIds = $submitted[$type] ?? [];
                    sort($submittedIds, SORT_NUMERIC);
                    if ($submittedIds !== $actualIds) throw new RuntimeException('CHAIN5_FIXTURE_SET_MISMATCH:' . $type);
                    foreach ($actualIds as $id) if (($this->store->rows[$type][$id]['status'] ?? 1) !== 2) throw new RuntimeException('CHAIN5_DRAIN_REQUIRED:' . $type);
                }
                $this->assertScope($ids = $this->store->chainFiveScope() + [
                    'oauth_client' => $actual['oauth_client'][0], 'cas_service' => $actual['cas_service'][0],
                    'api_resource' => $actual['api_resource'][0], 'api_route_binding' => $actual['api_route_binding'][0],
                    'policy' => $actual['policy'][0],
                ], $actual);
                foreach (['oauth_token', 'oauth_grant', 'authorization_code', 'oauth_authorization_request', 'oauth_consent', 'oauth_client', 'cas_ticket', 'cas_login_request', 'cas_service', 'api_route_binding', 'api_resource', 'policy_version', 'policy'] as $type) {
                    if ($failType === $type) throw new RuntimeException('CHAIN5_PARTIAL_FAILURE:' . $type);
                    foreach ($submitted[$type] as $id) unset($this->store->rows[$type][$id]);
                }
                $residual = [];
                foreach (['oauth_client', 'cas_service', 'api_resource', 'api_route_binding', 'policy', 'oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token', 'cas_login_request', 'cas_ticket', 'policy_version'] as $type) $residual[$type] = $this->store->ids($type);
                if (array_filter($residual) !== []) throw new RuntimeException('CHAIN5_RESIDUAL');
                $result = ['actual' => $submitted, 'residual' => $residual, 'replayed' => false];
                $this->store->replay($requestId, $result); $this->store->audit('acceptance_fixture.cleanup', 'succeeded'); return $result;
            });
        } catch (Throwable $exception) {
            $this->store->audit('acceptance_fixture.cleanup_failed', 'failed'); throw $exception;
        } finally { if ($locked) $this->store->unlock(); }
    }

    /** @param array<string,int> $ids @param array<string,list<int>> $actual */
    private function assertScope(array $ids, array $actual): void
    {
        $application = $this->store->rows['application'][$ids['application']] ?? null;
        $environment = $this->store->rows['environment'][$ids['environment']] ?? null;
        $resource = $this->store->rows['resource'][$ids['resource']] ?? null;
        if (!is_array($application) || (int) ($application['id'] ?? 0) !== $ids['application'] || (int) ($application['status'] ?? 0) !== 1) {
            throw new RuntimeException('CHAIN5_SCOPE_REJECTED:application');
        }
        if (!is_array($environment) || (int) ($environment['id'] ?? 0) !== $ids['environment']
            || (int) ($environment['application_id'] ?? 0) !== $ids['application'] || (int) ($environment['status'] ?? 0) !== 1) {
            throw new RuntimeException('CHAIN5_SCOPE_REJECTED:environment');
        }
        if (!is_array($resource) || (int) ($resource['id'] ?? 0) !== $ids['resource']
            || (int) ($resource['application_id'] ?? 0) !== $ids['application'] || (int) ($resource['status'] ?? 0) !== 1) {
            throw new RuntimeException('CHAIN5_SCOPE_REJECTED:resource');
        }
        foreach ($actual as $type => $typeIds) foreach ($typeIds as $id) {
            $row = $this->store->rows[$type][$id] ?? null;
            if (!is_array($row) || (int) ($row['application_id'] ?? 0) !== $ids['application']) throw new RuntimeException('CHAIN5_SCOPE_REJECTED:' . $type);
        }
        if (($this->store->rows['api_route_binding'][$ids['api_route_binding']]['api_resource_id'] ?? 0) !== $ids['api_resource']
            || ($this->store->rows['policy'][$ids['policy']]['published_version_id'] ?? 0) !== ($actual['policy_version'][0] ?? 0)) {
            throw new RuntimeException('CHAIN5_PARENT_REJECTED');
        }
        $identity = $this->store->rows['identity'][$ids['identity']] ?? null;
        if (!is_array($identity) || (int) ($identity['application_id'] ?? 0) !== $ids['application']
            || (int) ($this->store->rows['api_resource'][$ids['api_resource']]['resource_id'] ?? 0) !== $ids['resource']
            || (int) ($this->store->rows['policy'][$ids['policy']]['resource_id'] ?? 0) !== $ids['resource']
            || (int) ($this->store->rows['policy'][$ids['policy']]['identity_id'] ?? 0) !== $ids['identity']) {
            throw new RuntimeException('CHAIN5_PARENT_REJECTED:policy_scope');
        }
        foreach (['oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token'] as $type) foreach ($actual[$type] as $id) if (($this->store->rows[$type][$id]['client_id'] ?? 0) !== $ids['oauth_client']) throw new RuntimeException('CHAIN5_PARENT_REJECTED:' . $type);
        foreach (['cas_login_request', 'cas_ticket'] as $type) foreach ($actual[$type] as $id) if (($this->store->rows[$type][$id]['cas_service_id'] ?? 0) !== $ids['cas_service']) throw new RuntimeException('CHAIN5_PARENT_REJECTED:' . $type);
        foreach ($actual['policy_version'] as $id) if (($this->store->rows['policy_version'][$id]['policy_id'] ?? 0) !== $ids['policy']) throw new RuntimeException('CHAIN5_PARENT_REJECTED:policy_version');
        $requestId = $actual['oauth_authorization_request'][0] ?? 0;
        $codeId = $actual['authorization_code'][0] ?? 0;
        $grantId = $actual['oauth_grant'][0] ?? 0;
        $loginId = $actual['cas_login_request'][0] ?? 0;
        foreach ($actual['authorization_code'] as $id) if (($this->store->rows['authorization_code'][$id]['authorization_request_id'] ?? 0) !== $requestId) throw new RuntimeException('CHAIN5_PARENT_REJECTED:authorization_code');
        foreach ($actual['oauth_consent'] as $id) if (($this->store->rows['oauth_consent'][$id]['authorization_request_id'] ?? 0) !== $requestId) throw new RuntimeException('CHAIN5_PARENT_REJECTED:oauth_consent');
        foreach ($actual['oauth_grant'] as $id) if (($this->store->rows['oauth_grant'][$id]['authorization_code_id'] ?? 0) !== $codeId) throw new RuntimeException('CHAIN5_PARENT_REJECTED:oauth_grant');
        foreach ($actual['oauth_token'] as $id) if (($this->store->rows['oauth_token'][$id]['grant_id'] ?? 0) !== $grantId) throw new RuntimeException('CHAIN5_PARENT_REJECTED:oauth_token');
        foreach ($actual['cas_ticket'] as $id) if (($this->store->rows['cas_ticket'][$id]['cas_login_request_id'] ?? 0) !== $loginId) throw new RuntimeException('CHAIN5_PARENT_REJECTED:cas_ticket');
    }
}

/** Database-free fixture store for the human authentication acceptance protocol. */
final class TerminalAcceptanceHumanAuthFixtureStore
{
    /** @var array<int,array<string,mixed>> */ public array $identities = [];
    /** @var array<int,array<string,mixed>> */ public array $identityAuth = [];
    /** @var array<int,array<string,mixed>> */ public array $sessions = [];
    /** @var array<int,array<string,mixed>> */ public array $refreshTokens = [];
    /** @var array<int,array<string,mixed>> */ public array $factors = [];
    /** @var array<int,array<string,mixed>> */ public array $recoveryCodes = [];
    /** @var list<array<string,mixed>> */ public array $audit = [];
    public int $nextId = 1;
    public function id(): int { return $this->nextId++; }
    /** @return array<string,list<int>> */
    public function artifacts(int $identityId, int $applicationId): array
    {
        $filter = static fn (array $row): bool => (int) $row['identity_id'] === $identityId && (int) $row['application_id'] === $applicationId;
        $sessions = array_values(array_filter($this->sessions, $filter));
        $factors = array_values(array_filter($this->factors, $filter));
        $sessionIds = array_map(static fn (array $row): int => (int) $row['id'], $sessions);
        $factorIds = array_map(static fn (array $row): int => (int) $row['id'], $factors);
        $ids = static fn (array $rows): array => array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $in = static fn (int $id, array $set): bool => in_array($id, $set, true);
        return [
            'identity' => isset($this->identities[$identityId]) ? [$identityId] : [],
            'identity_auth' => $ids(array_values(array_filter($this->identityAuth, $filter))),
            'auth_session' => $sessionIds,
            'auth_refresh_token' => $ids(array_values(array_filter($this->refreshTokens, static fn (array $row): bool => $in((int) $row['session_id'], $sessionIds)))),
            'mfa_factor' => $factorIds,
            'mfa_recovery_code' => $ids(array_values(array_filter($this->recoveryCodes, static fn (array $row): bool => $in((int) $row['factor_id'], $factorIds)))),
        ];
    }
}

/** Service-semantic local counterpart; it never emits a password, token, OTP or secret. */
final class TerminalAcceptanceHumanAuthService
{
    private int $cleanupLockUntil = 0;
    /** @var array<string,array{identity_id:int,application_id:int,used:bool}> */ private array $challenges = [];
    /** @var array<string,array<string,mixed>> */ private array $cleanupReplay = [];
    public function __construct(private readonly TerminalAcceptanceHumanAuthFixtureStore $store, private readonly Closure $clock) {}
    /** @return array<string,mixed> */
    public function register(string $prefix, int $organizationId, int $applicationId, string $requestId): array
    {
        $identityId = $this->store->id(); $this->store->identities[$identityId] = ['id' => $identityId, 'organization_id' => $organizationId, 'application_id' => $applicationId, 'code' => $prefix . 'user'];
        $authId = $this->store->id(); $this->store->identityAuth[$authId] = ['id' => $authId, 'identity_id' => $identityId, 'application_id' => $applicationId];
        $session = $this->newSession($identityId, $applicationId, $requestId);
        $this->audit('identity.register', $identityId, $requestId, 'succeeded');
        return ['identity_id' => $identityId, 'session_id' => $session];
    }
    public function login(int $identityId, int $applicationId, string $requestId): int|array
    {
        $identity = $this->store->identities[$identityId] ?? null;
        if ($identity === null || (int) $identity['application_id'] !== $applicationId) throw new RuntimeException('IDENTITY_SCOPE_REJECTED');
        foreach ($this->store->factors as $factor) if ((int) $factor['identity_id'] === $identityId && (int) $factor['application_id'] === $applicationId && (int) $factor['status'] === 1) return ['mfa_required' => true, 'challenge_token' => $this->beginMfaLogin($identityId, $applicationId, $requestId)];
        $session = $this->newSession($identityId, $applicationId, $requestId); $this->audit('identity.login', $identityId, $requestId, 'succeeded'); return $session;
    }
    public function startMfa(int $identityId, int $applicationId, string $requestId): int
    {
        $this->identity($identityId, $applicationId); $id = $this->store->id(); $this->store->factors[$id] = ['id' => $id, 'identity_id' => $identityId, 'application_id' => $applicationId, 'secret' => '12345678901234567890', 'status' => 0, 'revoked_time' => null];
        $this->audit('identity.totp_start', $id, $requestId, 'succeeded'); return $id;
    }
    private function beginMfaLogin(int $identityId, int $applicationId, string $requestId): string
    {
        $this->identity($identityId, $applicationId);
        foreach ($this->store->factors as $factor) if ((int) $factor['identity_id'] === $identityId && (int) $factor['application_id'] === $applicationId && (int) $factor['status'] === 1) {
            $token = 'challenge-' . $this->store->id(); $this->challenges[$token] = ['identity_id' => $identityId, 'application_id' => $applicationId, 'used' => false]; $this->audit('identity.mfa_login_start', $identityId, $requestId, 'succeeded'); return $token;
        }
        throw new RuntimeException('MFA_NOT_REQUIRED');
    }
    public function verifyMfaLogin(string $challenge, int $applicationId, string $otp, string $requestId): int
    {
        $record = $this->challenges[$challenge] ?? null;
        if ($record === null || $record['used'] || $record['application_id'] !== $applicationId) throw new RuntimeException('MFA_CHALLENGE_REJECTED');
        $factor = array_values(array_filter($this->store->factors, static fn (array $item): bool => (int) $item['identity_id'] === $record['identity_id'] && (int) $item['application_id'] === $applicationId && (int) $item['status'] === 1))[0] ?? null;
        if ($factor === null || $otp !== $this->totp((string) $factor['secret'])) throw new RuntimeException('MFA_CHALLENGE_REJECTED');
        $record['used'] = true; $this->challenges[$challenge] = $record; $session = $this->newSession($record['identity_id'], $applicationId, $requestId); $this->audit('identity.mfa_login_verify', $record['identity_id'], $requestId, 'succeeded'); return $session;
    }
    public function confirmMfa(int $identityId, int $applicationId, int $factorId, string $otp, string $requestId): void
    {
        $factor = $this->factor($identityId, $applicationId, $factorId); if ($otp !== $this->totp((string) $factor['secret'])) throw new RuntimeException('MFA_OTP_REJECTED');
        $factor['status'] = 1; $this->store->factors[$factorId] = $factor;
        foreach ([1, 2] as $_) { $id = $this->store->id(); $this->store->recoveryCodes[$id] = ['id' => $id, 'factor_id' => $factorId]; }
        $this->audit('identity.totp_confirm', $factorId, $requestId, 'succeeded');
    }
    public function revokeMfa(int $identityId, int $applicationId, int $factorId, string $requestId): void
    {
        $factor = $this->factor($identityId, $applicationId, $factorId); $factor['status'] = 2; $factor['revoked_time'] = ($this->clock)(); $this->store->factors[$factorId] = $factor; $this->audit('identity.mfa_revoke', $factorId, $requestId, 'succeeded');
    }
    public function revokeSession(int $identityId, int $applicationId, int $sessionId, string $requestId): void
    {
        $session = $this->session($identityId, $applicationId, $sessionId); $session['status'] = 2; $session['revoked_time'] = ($this->clock)(); $this->store->sessions[$sessionId] = $session; $this->audit('identity.session_revoke', $sessionId, $requestId, 'succeeded');
    }
    public function accessSession(int $identityId, int $applicationId, int $sessionId): bool
    {
        return ($this->session($identityId, $applicationId, $sessionId)['status'] ?? 2) === 1;
    }
    /** @param array{identity:list<int>,auth_session:list<int>,mfa_factor:list<int>} $submitted @return array<string,mixed> */
    public function cleanup(int $identityId, int $applicationId, array $submitted, string $requestId, ?string $failType = null): array
    {
        if (isset($this->cleanupReplay[$requestId])) return $this->cleanupReplay[$requestId];
        if ($this->cleanupLockUntil > ($this->clock)()) throw new RuntimeException('CLEANUP_LOCKED');
        $this->cleanupLockUntil = ($this->clock)() + 15; $before = [$this->store->identities, $this->store->identityAuth, $this->store->sessions, $this->store->refreshTokens, $this->store->factors, $this->store->recoveryCodes];
        try {
            $actual = $this->store->artifacts($identityId, $applicationId);
            foreach (['identity', 'auth_session', 'mfa_factor'] as $type) if (!$this->same($actual[$type], $submitted[$type] ?? [])) throw new RuntimeException('FIXTURE_SET_MISMATCH:' . $type);
            foreach ($actual['auth_session'] as $id) if (($this->store->sessions[$id]['status'] ?? 0) !== 2 || ($this->store->sessions[$id]['revoked_time'] ?? null) === null) throw new RuntimeException('DRAIN_REQUIRED');
            foreach ($actual['mfa_factor'] as $id) if (($this->store->factors[$id]['status'] ?? 0) !== 2 || ($this->store->factors[$id]['revoked_time'] ?? null) === null) throw new RuntimeException('DRAIN_REQUIRED');
            foreach (['mfa_recovery_code' => 'recoveryCodes', 'mfa_factor' => 'factors', 'auth_refresh_token' => 'refreshTokens', 'auth_session' => 'sessions', 'identity_auth' => 'identityAuth', 'identity' => 'identities'] as $type => $property) {
                if ($failType === $type) throw new RuntimeException('PARTIAL_FAILURE:' . $type);
                foreach ($actual[$type] as $id) unset($this->store->{$property}[$id]);
            }
            $result = ['actual' => $actual, 'cleanup' => $actual, 'residual' => $this->store->artifacts($identityId, $applicationId), 'replayed' => false];
            $this->cleanupReplay[$requestId] = $result; $this->audit('acceptance_fixture.cleanup', $identityId, $requestId, 'succeeded'); return $result;
        } catch (Throwable $error) {
            [$this->store->identities, $this->store->identityAuth, $this->store->sessions, $this->store->refreshTokens, $this->store->factors, $this->store->recoveryCodes] = $before;
            $this->audit('acceptance_fixture.cleanup_failed', $identityId, $requestId, 'failed'); throw $error;
        } finally { $this->cleanupLockUntil = 0; }
    }
    public function holdCleanupLock(): void { $this->cleanupLockUntil = ($this->clock)() + 15; }
    /** @return list<array<string,mixed>> */ public function auditLog(): array { return $this->store->audit; }
    private function newSession(int $identityId, int $applicationId, string $requestId): int { $id = $this->store->id(); $this->store->sessions[$id] = ['id' => $id, 'identity_id' => $identityId, 'application_id' => $applicationId, 'status' => 1, 'revoked_time' => null]; $refresh = $this->store->id(); $this->store->refreshTokens[$refresh] = ['id' => $refresh, 'session_id' => $id]; return $id; }
    /** @return array<string,mixed> */ private function identity(int $id, int $applicationId): array { $row = $this->store->identities[$id] ?? null; if ($row === null || (int) $row['application_id'] !== $applicationId) throw new RuntimeException('IDENTITY_SCOPE_REJECTED'); return $row; }
    /** @return array<string,mixed> */ private function session(int $identityId, int $applicationId, int $id): array { $row = $this->store->sessions[$id] ?? null; if ($row === null || (int) $row['identity_id'] !== $identityId || (int) $row['application_id'] !== $applicationId) throw new RuntimeException('SESSION_SCOPE_REJECTED'); return $row; }
    /** @return array<string,mixed> */ private function factor(int $identityId, int $applicationId, int $id): array { $row = $this->store->factors[$id] ?? null; if ($row === null || (int) $row['identity_id'] !== $identityId || (int) $row['application_id'] !== $applicationId) throw new RuntimeException('MFA_SCOPE_REJECTED'); return $row; }
    /** @param list<int> $left @param list<int> $right */ private function same(array $left, array $right): bool { sort($left); sort($right); return $left === $right; }
    private function audit(string $action, int $resourceId, string $requestId, string $outcome): void { $this->store->audit[] = compact('action', 'resourceId', 'requestId', 'outcome'); }
    private function totp(string $secret): string { $counter = pack('N2', 0, intdiv(($this->clock)(), 30)); $hash = hash_hmac('sha1', $counter, $secret, true); $offset = ord($hash[19]) & 15; return str_pad((string) ((unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff) % 1000000), 6, '0', STR_PAD_LEFT); }
}

/** @param array<string,string> $headers @return array{status:int,body:string} */
function simulatorRequest(TerminalAcceptanceSimulator $simulator, string $method, string $target, array $headers, string $body, string $secret, string $session = ''): array
{
    $path = parse_url($target, PHP_URL_PATH) ?: '/'; parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
    if (str_starts_with($path, '/acceptance-fixture/') && ($session === '' || !isset($headers['x-sand-iam-acceptance-session']) || !hash_equals($session, $headers['x-sand-iam-acceptance-session']))) return ['status' => 401, 'body' => '{"code":401,"message":"local acceptance session required"}'];
    try { $payload = $body === '' ? [] : json_decode($body, true, 32, JSON_THROW_ON_ERROR); } catch (Throwable) { return ['status' => 400, 'body' => '{"code":400,"message":"JSON body invalid"}']; }
    if (!is_array($payload)) return ['status' => 400, 'body' => '{"code":400,"message":"JSON object required"}'];
    return match ($path) {
        '/provider/invoke' => $simulator->provider($payload, (string) ($headers['x-sand-iam-credential'] ?? ''), (string) ($headers['x-request-id'] ?? '')),
        '/webhook' => $method === 'POST' ? $simulator->receiver((string) ($headers['x-sandiam-event-id'] ?? ''), (string) ($headers['x-sandiam-timestamp'] ?? ''), $body, (string) ($headers['x-sandiam-signature'] ?? ''), $secret) : ['status' => 405, 'body' => '{"code":405,"message":"method not allowed"}'],
        '/acceptance-fixture/webhook/endpoint' => $method === 'POST' ? $simulator->endpoint($payload) : ['status' => 405, 'body' => '{"code":405,"message":"method not allowed"}'],
        '/acceptance-fixture/webhook/event' => $method === 'POST' ? $simulator->event($payload) : ['status' => 405, 'body' => '{"code":405,"message":"method not allowed"}'],
        '/acceptance-fixture/webhook/delivery/worker' => $method === 'POST' ? $simulator->worker($payload, $secret) : ['status' => 405, 'body' => '{"code":405,"message":"method not allowed"}'],
        '/acceptance-fixture/webhook/delivery/retry' => $method === 'POST' ? $simulator->retry($payload) : ['status' => 405, 'body' => '{"code":405,"message":"method not allowed"}'],
        '/acceptance-fixture/webhook/proof' => $method === 'GET' ? simulatorProofResponse($simulator, (int) ($query['endpoint_id'] ?? 0), (string) ($query['delivery_id'] ?? '')) : ['status' => 405, 'body' => '{"code":405,"message":"method not allowed"}'],
        '/acceptance-fixture/webhook/cleanup' => $method === 'POST' ? $simulator->cleanup($payload) : ['status' => 405, 'body' => '{"code":405,"message":"method not allowed"}'],
        '/oauth/callback' => $simulator->oauthRpCallback((string) ($query['state'] ?? ''), (string) ($query['code'] ?? ''), (string) ($query['code_verifier'] ?? '')),
        '/cas/serviceValidate' => $simulator->casClient((string) ($query['ticket'] ?? ''), (string) ($query['service'] ?? '')),
        '/effects' => ['status' => 200, 'body' => json_encode(['code' => 0, 'effects' => $simulator->effects()], JSON_THROW_ON_ERROR)], default => ['status' => 404, 'body' => '{"code":404,"message":"not found"}'],
    };
}
/** @return array{status:int,body:string} */
function simulatorProofResponse(TerminalAcceptanceSimulator $simulator, int $endpointId, string $deliveryId): array { try { return ['status' => 200, 'body' => json_encode($simulator->proof($endpointId, $deliveryId), JSON_THROW_ON_ERROR)]; } catch (RuntimeException) { return ['status' => 409, 'body' => '{"code":409,"message":"webhook proof incomplete"}']; } }
/** @return array<string,mixed> */
function simulatorChainThreeProtocol(): array
{
    $now = 1800000000; $store = new TerminalAcceptanceHumanAuthFixtureStore(); $service = new TerminalAcceptanceHumanAuthService($store, static function () use (&$now): int { return $now; });
    $prefix = 'sand_iam_acceptance_0123456789abcdef_'; $organizationId = 71; $applicationId = 72;
    $registered = $service->register($prefix, $organizationId, $applicationId, $prefix . 'chain3-register'); $identityId = $registered['identity_id']; $registrationSession = $registered['session_id'];
    $loginSession = $service->login($identityId, $applicationId, $prefix . 'chain3-login'); $factorId = $service->startMfa($identityId, $applicationId, $prefix . 'chain3-totp-start');
    foreach ([
        static fn () => $service->login($identityId + 999, $applicationId, $prefix . 'bad-identity'),
        static fn () => $service->revokeSession($identityId, $applicationId + 1, $loginSession, $prefix . 'bad-session'),
        static fn () => $service->confirmMfa($identityId, $applicationId, $factorId, '000000', $prefix . 'bad-mfa'),
    ] as $denied) { try { $denied(); throw new RuntimeException('scope or OTP denial was accepted'); } catch (RuntimeException $error) { if ($error->getMessage() === 'scope or OTP denial was accepted') throw $error; } }
    $totp = static function (int $timestamp): string { $counter = pack('N2', 0, intdiv($timestamp, 30)); $hash = hash_hmac('sha1', $counter, '12345678901234567890', true); $offset = ord($hash[19]) & 15; return str_pad((string) ((unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff) % 1000000), 6, '0', STR_PAD_LEFT); };
    $service->confirmMfa($identityId, $applicationId, $factorId, $totp($now), $prefix . 'chain3-totp-confirm');
    $challengeResponse = $service->login($identityId, $applicationId, $prefix . 'chain3-mfa-login');
    if (!is_array($challengeResponse) || ($challengeResponse['mfa_required'] ?? false) !== true || !is_string($challengeResponse['challenge_token'] ?? null)) throw new RuntimeException('enabled MFA login created a session instead of a challenge');
    $challenge = $challengeResponse['challenge_token'];
    $now += 30;
    $mfaLoginSession = $service->verifyMfaLogin($challenge, $applicationId, $totp($now), $prefix . 'chain3-mfa-challenge-verify');
    if (!$service->accessSession($identityId, $applicationId, $mfaLoginSession)) throw new RuntimeException('MFA-verified session was not visible');
    if (!$service->accessSession($identityId, $applicationId, $loginSession)) throw new RuntimeException('active login session was not visible');
    $submitted = ['identity' => [$identityId], 'auth_session' => [$registrationSession, $loginSession, $mfaLoginSession], 'mfa_factor' => [$factorId]];
    $snapshot = static function () use ($store, $identityId, $applicationId): array {
        $artifacts = $store->artifacts($identityId, $applicationId); $out = [];
        foreach (['identity_auth', 'auth_session', 'auth_refresh_token', 'mfa_factor', 'mfa_recovery_code'] as $type) {
            $rows = array_values(array_filter(match ($type) {'identity_auth' => $store->identityAuth, 'auth_session' => $store->sessions, 'auth_refresh_token' => $store->refreshTokens, 'mfa_factor' => $store->factors, 'mfa_recovery_code' => $store->recoveryCodes}, static fn (array $row): bool => in_array((int) $row['id'], $artifacts[$type], true)));
            $byId = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $byId[$id] = [
                    'id' => $id,
                    'identity_id' => isset($row['identity_id']) ? (int) $row['identity_id'] : null,
                    'application_id' => isset($row['application_id']) ? (int) $row['application_id'] : null,
                    'parent_id' => isset($row['session_id']) ? (int) $row['session_id'] : (isset($row['factor_id']) ? (int) $row['factor_id'] : null),
                    'status' => isset($row['status']) ? (int) $row['status'] : null,
                    'revoked_time' => isset($row['revoked_time']) && $row['revoked_time'] !== '' ? (string) $row['revoked_time'] : null,
                ];
            }
            ksort($byId, SORT_NUMERIC); $out[$type] = $byId;
        } return $out;
    };
    $beforeDrain = $snapshot();
    try { $service->cleanup($identityId, $applicationId, $submitted, $prefix . 'chain3-drain'); throw new RuntimeException('active cleanup unexpectedly succeeded'); } catch (RuntimeException $error) { if ($error->getMessage() !== 'DRAIN_REQUIRED') throw $error; }
    $afterDrain = $snapshot();
    $drainPreserved = $beforeDrain === $afterDrain;
    $service->revokeMfa($identityId, $applicationId, $factorId, $prefix . 'chain3-mfa-revoke');
    $service->revokeSession($identityId, $applicationId, $registrationSession, $prefix . 'chain3-registration-session-revoke');
    $service->revokeSession($identityId, $applicationId, $loginSession, $prefix . 'chain3-login-session-revoke');
    $service->revokeSession($identityId, $applicationId, $mfaLoginSession, $prefix . 'chain3-mfa-login-session-revoke');
    foreach ([$registrationSession, $loginSession, $mfaLoginSession] as $sessionId) {
        if ($service->accessSession($identityId, $applicationId, $sessionId)) throw new RuntimeException('revoked session remained usable');
    }
    try { $service->cleanup($identityId, $applicationId, ['identity' => [$identityId], 'auth_session' => [$registrationSession], 'mfa_factor' => [$factorId]], $prefix . 'chain3-extra'); throw new RuntimeException('incomplete submitted set unexpectedly succeeded'); } catch (RuntimeException $error) { if (!str_starts_with($error->getMessage(), 'FIXTURE_SET_MISMATCH')) throw $error; }
    $beforePartial = $store->artifacts($identityId, $applicationId);
    try { $service->cleanup($identityId, $applicationId, $submitted, $prefix . 'chain3-partial', 'identity_auth'); throw new RuntimeException('partial cleanup unexpectedly succeeded'); } catch (RuntimeException $error) { if ($error->getMessage() !== 'PARTIAL_FAILURE:identity_auth') throw $error; }
    if ($store->artifacts($identityId, $applicationId) !== $beforePartial) throw new RuntimeException('partial cleanup changed fixture state');
    $service->holdCleanupLock(); try { $service->cleanup($identityId, $applicationId, $submitted, $prefix . 'chain3-locked'); throw new RuntimeException('concurrent cleanup unexpectedly succeeded'); } catch (RuntimeException $error) { if ($error->getMessage() !== 'CLEANUP_LOCKED') throw $error; }
    $now += 16;
    $cleanup = $service->cleanup($identityId, $applicationId, $submitted, $prefix . 'chain3-cleanup');
    $replay = $service->cleanup($identityId, $applicationId, $submitted, $prefix . 'chain3-cleanup');
    $replay['replayed'] = true;
    $residual = $store->artifacts($identityId, $applicationId);
    $audit = array_map(static fn (array $event): array => ['action' => (string) $event['action'], 'outcome' => (string) $event['outcome']], $service->auditLog());
    $result = ['identity_id' => $identityId, 'actual' => $cleanup['actual'], 'cleanup' => ['ids' => $cleanup['cleanup'], 'zero_residual' => array_filter($residual) === [], 'ok' => array_filter($residual) === []], 'residual' => $residual, 'replay' => $replay, 'drain_preserved' => $drainPreserved, 'revoked_sessions_denied' => 3, 'drain_snapshot' => ['before' => $beforeDrain, 'after' => $afterDrain], 'audit' => $audit];
    simulatorAssertNoSensitiveTree($result);
    return $result;
}

/** Reject raw acceptance requests and authentication materials at every output depth. */
function simulatorAssertNoSensitiveTree(mixed $value, string $path = ''): void
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $name = strtolower((string) $key);
            $chainFiveArtifact = ['oauth_client', 'cas_service', 'api_resource', 'api_route_binding', 'policy', 'oauth_authorization_request', 'authorization_code', 'oauth_consent', 'oauth_grant', 'oauth_token', 'cas_login_request', 'cas_ticket', 'policy_version'];
            $artifactCategory = is_array($item) && in_array($name, $chainFiveArtifact, true);
            $artifactCount = is_int($item) && $item >= 0 && in_array($name, $chainFiveArtifact, true);
            $legacyArtifact = in_array($name, ['identity_auth', 'auth_session', 'auth_refresh_token', 'mfa_factor', 'mfa_recovery_code'], true);
            $protocolOutcome = is_bool($item) && $name === 'wrong_pkce_denied';
            if ($artifactCategory) {
                foreach ($item as $id) if (!is_int($id) || $id <= 0) throw new RuntimeException('protocol artifact array contains a non-integer ID at ' . $path . '/' . $key);
            }
            if (!$artifactCategory && !$artifactCount && !$legacyArtifact && !$protocolOutcome && preg_match('/(?:password|secret|ticket|token|code|verifier|challenge|nonce|csrf|totp|recovery|confirmation|request[_-]?id)/', $name) === 1) {
                throw new RuntimeException('protocol contains a sensitive field at ' . $path . '/' . $key);
            }
            simulatorAssertNoSensitiveTree($item, $path . '/' . $key);
        }
        return;
    }
    if (!is_scalar($value)) return;
    $text = (string) $value;
    if (str_contains($text, 'sand_iam_acceptance_') || preg_match('/(?:siam_(?:ac|at|rt)_|otpauth:|challenge-|recovery[_-]?code|(?:^|[^A-Za-z0-9])ST-[A-Za-z0-9._-]+)/i', $text) === 1) {
        throw new RuntimeException('protocol contains a raw sensitive value at ' . $path);
    }
}

function simulatorSensitiveTreeSelfTest(): void
{
    foreach (['access_token' => 'siam_at_nested-leak', 'refresh_token' => 'siam_rt_nested-leak', 'client_secret' => 'client-secret-value', 'pkce_verifier' => 'pkce-verifier', 'cas_ticket' => 'ST-nested-ticket', 'authorization_code' => 'authorization-code', 'csrf' => 'csrf-value', 'nonce' => 'nonce-value'] as $field => $value) {
        try {
            simulatorAssertNoSensitiveTree(['nested' => ['details' => [$field => $value]]]);
            throw new RuntimeException("nested {$field} protocol output was accepted");
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === "nested {$field} protocol output was accepted") throw $exception;
        }
    }
    try {
        simulatorAssertNoSensitiveTree(['actual' => ['oauth_token' => ['opaque-token-value']]]);
        throw new RuntimeException('opaque artifact array value was accepted');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'opaque artifact array value was accepted') throw $exception;
    }
    simulatorAssertNoSensitiveTree(['actual' => ['oauth_token' => [1], 'cas_ticket' => [2]], 'residual' => ['oauth_token' => 0, 'cas_ticket' => 0]]);
}
/** @return array<string,mixed> */
function simulatorChainSevenProtocol(): array
{
    $now = 1800000000; $simulator = new TerminalAcceptanceSimulator(static function () use (&$now): int { return $now; }); $prefix = 'sand_iam_acceptance_0123456789abcdef_'; $session = 'self-test-local-acceptance-session'; $secret = 'simulator-webhook-secret'; $headers = ['x-sand-iam-acceptance-session' => $session];
    $request = static function (string $method, string $path, array $payload = []) use ($simulator, $headers, $secret, $session): array { return simulatorRequest($simulator, $method, $path, $headers, $payload === [] ? '' : json_encode($payload, JSON_THROW_ON_ERROR), $secret, $session); };
    $json = static fn (array $response): array => json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
    $endpointPayload = ['prefix' => $prefix, 'event_name' => 'acceptance-fixture-webhook-delivery', 'event_type' => 'acceptance.fixture.event'];
    $missingSession = simulatorRequest($simulator, 'POST', '/acceptance-fixture/webhook/endpoint', [], json_encode($endpointPayload, JSON_THROW_ON_ERROR), $secret, $session);
    $wrongSession = simulatorRequest($simulator, 'POST', '/acceptance-fixture/webhook/endpoint', ['x-sand-iam-acceptance-session' => 'wrong-session'], json_encode($endpointPayload, JSON_THROW_ON_ERROR), $secret, $session);
    if ($missingSession['status'] !== 401 || $wrongSession['status'] !== 401 || ($json($missingSession)['message'] ?? null) !== 'local acceptance session required' || ($json($wrongSession)['message'] ?? null) !== 'local acceptance session required') throw new RuntimeException('local acceptance session rejection failed');
    $endpoint = $json($request('POST', '/acceptance-fixture/webhook/endpoint', $endpointPayload)); $endpointId = (int) ($endpoint['endpoint_id'] ?? 0);
    $unknownEventName = $request('POST', '/acceptance-fixture/webhook/endpoint', ['prefix' => $prefix, 'event_name' => 'identity.changed', 'event_type' => 'acceptance.fixture.event']);
    $unknownEventType = $request('POST', '/acceptance-fixture/webhook/endpoint', ['prefix' => $prefix, 'event_name' => 'acceptance-fixture-webhook-delivery', 'event_type' => 'identity.changed']);
    if ($endpointId !== 1 || $unknownEventName['status'] !== 400 || $unknownEventType['status'] !== 400 || ($json($unknownEventName)['message'] ?? null) !== 'unknown event_name or event_type' || ($json($unknownEventType)['message'] ?? null) !== 'unknown event_name or event_type') throw new RuntimeException('empty receiver registration or unknown event rejection failed');
    foreach ([0, -1, '2147483648', '999999999999999999999'] as $invalidEndpointId) {
        $invalidEvent = $request('POST', '/acceptance-fixture/webhook/event', ['endpoint_id' => $invalidEndpointId, 'event_id' => $prefix . 'chain7-invalid-id', 'event_name' => 'acceptance-fixture-webhook-delivery', 'event_type' => 'acceptance.fixture.event']);
        if ($invalidEvent['status'] !== 400 || ($json($invalidEvent)['message'] ?? null) !== 'endpoint_id must be a bounded positive integer') throw new RuntimeException('invalid endpoint_id was accepted');
    }
    $eventId = $prefix . 'chain7-event'; $eventPayload = ['endpoint_id' => $endpointId, 'event_id' => $eventId, 'event_name' => 'acceptance-fixture-webhook-delivery', 'event_type' => 'acceptance.fixture.event']; $event = $json($request('POST', '/acceptance-fixture/webhook/event', $eventPayload)); $deliveryId = (string) ($event['delivery_id'] ?? '');
    if ($deliveryId === '' || $json($request('POST', '/acceptance-fixture/webhook/event', $eventPayload)) !== ['endpoint_id' => $endpointId, 'delivery_id' => $deliveryId, 'replayed' => true]) throw new RuntimeException('repeat trigger is not idempotent');
    $first = $json($request('POST', '/acceptance-fixture/webhook/delivery/worker', ['delivery_id' => $deliveryId]));
    if ($request('POST', '/acceptance-fixture/webhook/delivery/worker', ['delivery_id' => $deliveryId])['status'] !== 409 || simulatorRequest($simulator, 'POST', '/webhook', $first['headers'], $first['body'], $secret, $session)['status'] !== 500) throw new RuntimeException('worker concurrency or initial receiver attempt failed');
    if ($request('POST', '/acceptance-fixture/webhook/delivery/retry', ['delivery_id' => $deliveryId])['status'] !== 200 || ($json($request('POST', '/acceptance-fixture/webhook/delivery/retry', ['delivery_id' => $deliveryId]))['replayed'] ?? false) !== true) throw new RuntimeException('retry idempotency failed');
    $second = $json($request('POST', '/acceptance-fixture/webhook/delivery/worker', ['delivery_id' => $deliveryId]));
    if (simulatorRequest($simulator, 'POST', '/webhook', $second['headers'], $second['body'], $secret, $session)['status'] !== 204) throw new RuntimeException('retry receiver attempt failed');
    $proofResponse = simulatorRequest($simulator, 'GET', '/acceptance-fixture/webhook/proof?endpoint_id=' . $endpointId . '&delivery_id=' . rawurlencode($deliveryId), $headers, '', $secret, $session); $proof = $json($proofResponse);
    if ($proofResponse['status'] !== 200 || $proof !== ['endpoint_id' => $endpointId, 'delivery_id' => $deliveryId, 'signature_verified' => true, 'attempt_count' => 2, 'first_status' => 500, 'last_status' => 204]) throw new RuntimeException('proof did not bind the actual retry sequence');
    $other = $json($request('POST', '/acceptance-fixture/webhook/endpoint', $endpointPayload));
    if (simulatorRequest($simulator, 'GET', '/acceptance-fixture/webhook/proof?endpoint_id=' . $other['endpoint_id'] . '&delivery_id=' . rawurlencode($deliveryId), $headers, '', $secret, $session)['status'] !== 409) throw new RuntimeException('other endpoint received a proof');
    $otherEndpointId = (int) ($other['endpoint_id'] ?? 0);
    $locked = $json($request('POST', '/acceptance-fixture/webhook/event', ['endpoint_id' => $otherEndpointId, 'event_id' => $prefix . 'chain7-expired-lock', 'event_name' => 'acceptance-fixture-webhook-delivery', 'event_type' => 'acceptance.fixture.event'])); $lockedDeliveryId = (string) ($locked['delivery_id'] ?? '');
    $request('POST', '/acceptance-fixture/webhook/delivery/worker', ['delivery_id' => $lockedDeliveryId]); $now += 16;
    $recovered = $json($request('POST', '/acceptance-fixture/webhook/delivery/worker', ['delivery_id' => $lockedDeliveryId]));
    if ($lockedDeliveryId === '' || simulatorRequest($simulator, 'POST', '/webhook', $recovered['headers'], $recovered['body'], $secret, $session)['status'] !== 500) throw new RuntimeException('expired worker lock was not recovered into the first receiver attempt');
    if ($request('POST', '/acceptance-fixture/webhook/delivery/retry', ['delivery_id' => $lockedDeliveryId])['status'] !== 200) throw new RuntimeException('expired-lock delivery did not enter retry');
    $recoveredRetry = $json($request('POST', '/acceptance-fixture/webhook/delivery/worker', ['delivery_id' => $lockedDeliveryId]));
    if (simulatorRequest($simulator, 'POST', '/webhook', $recoveredRetry['headers'], $recoveredRetry['body'], $secret, $session)['status'] !== 204) throw new RuntimeException('expired-lock delivery retry attempt failed');
    $otherProof = simulatorRequest($simulator, 'GET', '/acceptance-fixture/webhook/proof?endpoint_id=' . $otherEndpointId . '&delivery_id=' . rawurlencode($lockedDeliveryId), $headers, '', $secret, $session);
    if ($otherProof['status'] !== 200) throw new RuntimeException('expired-lock delivery proof is incomplete');
    $actual = $simulator->webhookState();
    $cleanupResponses = [
        $request('POST', '/acceptance-fixture/webhook/cleanup', ['endpoint_id' => $endpointId, 'delivery_id' => $deliveryId]),
        $request('POST', '/acceptance-fixture/webhook/cleanup', ['endpoint_id' => $otherEndpointId, 'delivery_id' => $lockedDeliveryId]),
    ];
    foreach ($cleanupResponses as $response) if ($response['status'] !== 200) throw new RuntimeException('delivery to endpoint cleanup failed');
    $residual = $simulator->webhookState();
    $cleanupEndpointIds = [$endpointId, $otherEndpointId]; $cleanupDeliveryIds = [$deliveryId, $lockedDeliveryId];
    sort($cleanupEndpointIds, SORT_NUMERIC); sort($cleanupDeliveryIds, SORT_STRING);
    $cleanupOk = $actual['endpoint_ids'] === $cleanupEndpointIds
        && $actual['delivery_ids'] === $cleanupDeliveryIds
        && $residual === ['endpoint_ids' => [], 'delivery_ids' => []];
    return ['endpoint_id' => $endpointId, 'delivery_id' => $deliveryId, 'attempt_count' => 2, 'first_status' => 500, 'last_status' => 204, 'actual' => $actual, 'cleanup' => ['endpoint_ids' => $cleanupEndpointIds, 'delivery_ids' => $cleanupDeliveryIds, 'zero_residual' => $residual === ['endpoint_ids' => [], 'delivery_ids' => []], 'ok' => $cleanupOk], 'residual' => $residual, 'effects' => $simulator->effects()];
}

/** @return array<string,mixed> */
function simulatorChainFiveProtocol(): array
{
    $store = new TerminalAcceptanceOAuthCasFixtureStore();
    $service = new TerminalAcceptanceOAuthCasGovernanceService($store);
    $ids = $service->configure();
    $protocol = $service->protocols($ids);
    $service->publishPolicy($ids);
    $allow = $service->authorize($ids);
    $dependencySnapshot = $store->rows;
    foreach (['api_resource', 'oauth_token', 'cas_ticket', 'policy_version'] as $type) {
        unset($store->rows[$type]);
        if ($service->authorize($ids)) throw new RuntimeException("Chain5 {$type} deletion left authorization valid");
        $store->rows = $dependencySnapshot;
    }
    $oauthRevoked = $service->revokeOAuth($ids);
    $casRevoked = $service->revokeCas($ids);
    $service->revokePolicyAndRoute($ids);
    $deny = !$service->authorize($ids);
    $actual = $service->actual();
    try { $service->cleanup($actual, 'chain5-drain'); throw new RuntimeException('active Chain5 cleanup accepted'); } catch (RuntimeException $exception) { if (!str_starts_with($exception->getMessage(), 'CHAIN5_DRAIN_REQUIRED')) throw $exception; }
    $service->drain($ids);
    $submitted = $actual;
    foreach ([
        'missing-application' => static function (array &$rows) use ($ids): void { unset($rows['application'][$ids['application']]); },
        'disabled-application' => static function (array &$rows) use ($ids): void { $rows['application'][$ids['application']]['status'] = 2; },
        'missing-environment' => static function (array &$rows) use ($ids): void { unset($rows['environment'][$ids['environment']]); },
        'wrong-application-environment' => static function (array &$rows) use ($ids): void { $rows['environment'][$ids['environment']]['application_id'] = 999; },
        'missing-resource' => static function (array &$rows) use ($ids): void { unset($rows['resource'][$ids['resource']]); },
        'wrong-application-resource' => static function (array &$rows) use ($ids): void { $rows['resource'][$ids['resource']]['application_id'] = 999; },
    ] as $label => $breakScope) {
        $beforeScope = $store->rows;
        $breakScope($store->rows);
        $rejectedRows = $store->rows;
        try { $service->cleanup($submitted, 'chain5-scope-' . $label); throw new RuntimeException('invalid Chain5 prerequisite cleanup accepted:' . $label); } catch (RuntimeException $exception) { if (!str_starts_with($exception->getMessage(), 'CHAIN5_SCOPE_REJECTED:')) throw $exception; }
        if ($store->rows !== $rejectedRows) throw new RuntimeException('invalid Chain5 prerequisite changed 13 fixture rows:' . $label);
        $store->rows = $beforeScope;
    }
    foreach ([
        'authorization_code' => static function (array &$rows) use ($submitted): void { $rows['authorization_code'][$submitted['authorization_code'][0]]['authorization_request_id'] = 999; },
        'oauth_consent' => static function (array &$rows) use ($submitted): void { $rows['oauth_consent'][$submitted['oauth_consent'][0]]['authorization_request_id'] = 999; },
        'oauth_grant' => static function (array &$rows) use ($submitted): void { $rows['oauth_grant'][$submitted['oauth_grant'][0]]['authorization_code_id'] = 999; },
        'oauth_token' => static function (array &$rows) use ($submitted): void { $rows['oauth_token'][$submitted['oauth_token'][0]]['grant_id'] = 999; },
        'cas_ticket' => static function (array &$rows) use ($submitted): void { $rows['cas_ticket'][$submitted['cas_ticket'][0]]['cas_login_request_id'] = 999; },
        'api_route_binding' => static function (array &$rows) use ($submitted): void { $rows['api_route_binding'][$submitted['api_route_binding'][0]]['api_resource_id'] = 999; },
        'policy' => static function (array &$rows) use ($submitted): void { $rows['policy'][$submitted['policy'][0]]['identity_id'] = 999; },
    ] as $type => $misbind) {
        $beforeParent = $store->rows;
        $misbind($store->rows);
        $misboundRows = $store->rows;
        try { $service->cleanup($submitted, 'chain5-parent-' . $type); throw new RuntimeException('misbound Chain5 parent accepted:' . $type); } catch (RuntimeException $exception) { if (!str_starts_with($exception->getMessage(), 'CHAIN5_PARENT_REJECTED')) throw $exception; }
        if ($store->rows !== $misboundRows) throw new RuntimeException('misbound parent cleanup changed fixture state:' . $type);
        $store->rows = $beforeParent;
    }
    $missingToken = $submitted; array_pop($missingToken['oauth_token']);
    try { $service->cleanup($missingToken, 'chain5-missing-token'); throw new RuntimeException('missing Chain5 token cleanup accepted'); } catch (RuntimeException $exception) { if ($exception->getMessage() !== 'CHAIN5_FIXTURE_SET_MISMATCH:oauth_token') throw $exception; }
    try { $service->cleanup(['oauth_client' => $submitted['oauth_client']], 'chain5-incomplete'); throw new RuntimeException('incomplete Chain5 cleanup accepted'); } catch (RuntimeException $exception) { if (!str_starts_with($exception->getMessage(), 'CHAIN5_FIXTURE_SET_MISMATCH')) throw $exception; }
    $extra = $submitted; $extra['oauth_client'][] = 999;
    try { $service->cleanup($extra, 'chain5-extra'); throw new RuntimeException('extra Chain5 cleanup accepted'); } catch (RuntimeException $exception) { if (!str_starts_with($exception->getMessage(), 'CHAIN5_FIXTURE_SET_MISMATCH')) throw $exception; }
    $store->rows['oauth_token'][999] = ['id' => 999, 'application_id' => 99, 'client_id' => $ids['oauth_client'], 'status' => 2];
    $crossApplication = $service->actual();
    $beforeCrossApplication = $store->rows;
    try { $service->cleanup($crossApplication, 'chain5-cross-application'); throw new RuntimeException('cross-application Chain5 token cleanup accepted'); } catch (RuntimeException $exception) { if ($exception->getMessage() !== 'CHAIN5_SCOPE_REJECTED:oauth_token') throw $exception; }
    if ($store->rows !== $beforeCrossApplication) throw new RuntimeException('cross-application Chain5 token cleanup changed fixture state');
    unset($store->rows['oauth_token'][999]);
    $store->rows['oauth_grant'][$submitted['oauth_grant'][0]]['status'] = 1;
    try { $service->cleanup($submitted, 'chain5-derived-drain'); throw new RuntimeException('active derived Chain5 cleanup accepted'); } catch (RuntimeException $exception) { if ($exception->getMessage() !== 'CHAIN5_DRAIN_REQUIRED:oauth_grant') throw $exception; }
    $store->rows['oauth_grant'][$submitted['oauth_grant'][0]]['status'] = 2;
    $service->holdCleanupLock();
    try { $service->cleanup($submitted, 'chain5-locked'); throw new RuntimeException('locked Chain5 cleanup accepted'); } catch (RuntimeException $exception) { if ($exception->getMessage() !== 'CHAIN5_CLEANUP_LOCKED') throw $exception; }
    $service->releaseCleanupLock();
    $beforePartial = $store->rows;
    try { $service->cleanup($submitted, 'chain5-partial', 'oauth_client'); throw new RuntimeException('partial Chain5 cleanup accepted'); } catch (RuntimeException $exception) { if ($exception->getMessage() !== 'CHAIN5_PARTIAL_FAILURE:oauth_client') throw $exception; }
    if ($store->rows !== $beforePartial) throw new RuntimeException('partial Chain5 cleanup changed fixture state');
    $cleanup = $service->cleanup($submitted, 'chain5-cleanup');
    $replay = $service->cleanup($submitted, 'chain5-cleanup');
    $safeAudit = array_map(static fn (array $event): array => ['action' => $event['action'], 'outcome' => $event['outcome']], $store->audit);
    $result = [
        'actual' => $actual,
        'protocol' => $protocol,
        'policy_allow' => $allow,
        'oauth_revoked' => $oauthRevoked,
        'cas_revoked' => $casRevoked,
        'policy_and_route_denied' => $deny,
        'scope_guards' => [
            'missing_application' => true,
            'disabled_application' => true,
            'missing_environment' => true,
            'wrong_application_environment' => true,
            'missing_resource' => true,
            'wrong_application_resource' => true,
        ],
        'cleanup' => ['ok' => array_filter($cleanup['residual']) === [], 'zero_residual' => array_filter($cleanup['residual']) === []],
        'residual' => $cleanup['residual'],
        'replay' => ['replayed' => $replay['replayed'] ?? false],
        'audit' => $safeAudit,
    ];
    simulatorAssertNoSensitiveTree($result);
    return $result;
}

function simulatorSelfTest(): void
{
    $simulator = new TerminalAcceptanceSimulator(); $verifier = 'sand-iam-acceptance-verifier';
    if (TerminalAcceptanceSimulator::pkce($verifier) === TerminalAcceptanceSimulator::pkce('wrong') || $simulator->oauthRpCallback('state', 'code', $verifier)['status'] !== 200 || $simulator->casClient('ST-sand-iam-acceptance', 'https://client.example.test/cas')['status'] !== 200) throw new RuntimeException('PKCE, OAuth RP or CAS contract failed');
    if ($simulator->provider(['action' => 'document.read'], 'simulator-credential', 'sand_iam_acceptance_0123456789abcdef_chain4-allow')['status'] !== 200 || $simulator->provider(['action' => 'document.write'], 'simulator-credential', 'sand_iam_acceptance_0123456789abcdef_chain4-ungranted')['status'] !== 403) throw new RuntimeException('provider allow or denial failed'); $simulator->revokeProviderCredential('simulator-credential');
    $chainThree = simulatorChainThreeProtocol(); $chainFive = simulatorChainFiveProtocol(); $chainSeven = simulatorChainSevenProtocol();
    if ($simulator->provider(['action' => 'document.read'], 'simulator-credential', 'sand_iam_acceptance_0123456789abcdef_chain4-revoked')['status'] !== 401 || ($chainThree['cleanup']['ok'] ?? false) !== true || ($chainThree['drain_preserved'] ?? false) !== true || ($chainFive['cleanup']['ok'] ?? false) !== true || ($chainSeven['cleanup']['ok'] ?? false) !== true) throw new RuntimeException('provider revocation or controlled protocol failed');
    simulatorReaderSelfTest(); simulatorSensitiveTreeSelfTest();
}
/** @return array{method:string,target:string,headers:array<string,string>,body:string}|array{status:int,body:string} */
function simulatorReadRequest($connection, int $timeoutSeconds = 5, int $timeoutMicros = 0): array
{
    stream_set_timeout($connection, $timeoutSeconds, $timeoutMicros); $raw = '';
    while (!str_contains($raw, "\r\n\r\n")) { $chunk = fread($connection, 1024); if ($chunk === false || $chunk === '') return ['status' => 400, 'body' => '{"code":400,"message":"request header incomplete"}']; $raw .= $chunk; if (strlen($raw) > 16384) return ['status' => 413, 'body' => '{"code":413,"message":"request header too large"}']; }
    [$head, $body] = explode("\r\n\r\n", $raw, 2); $lines = explode("\r\n", $head); if (preg_match('#^(GET|POST) ([^ ]+) HTTP/1\.[01]$#', $lines[0] ?? '', $line) !== 1) return ['status' => 400, 'body' => '{"code":400,"message":"request line invalid"}']; $headers = [];
    foreach (array_slice($lines, 1) as $header) { if (!str_contains($header, ':')) return ['status' => 400, 'body' => '{"code":400,"message":"request header invalid"}']; [$name, $value] = explode(':', $header, 2); $headers[strtolower(trim($name))] = trim($value); }
    $length = $headers['content-length'] ?? '0'; if (preg_match('/^(0|[1-9]\d{0,4})$/', $length) !== 1 || (int) $length > 16384) return ['status' => 413, 'body' => '{"code":413,"message":"request body too large"}']; $remaining = (int) $length - strlen($body);
    while ($remaining > 0) { $chunk = fread($connection, min(4096, $remaining)); if ($chunk === false || $chunk === '') return ['status' => 400, 'body' => '{"code":400,"message":"request body incomplete"}']; $body .= $chunk; $remaining -= strlen($chunk); }
    return strlen($body) === (int) $length ? ['method' => $line[1], 'target' => $line[2], 'headers' => $headers, 'body' => $body] : ['status' => 400, 'body' => '{"code":400,"message":"request body length invalid"}'];
}
function simulatorReaderSelfTest(): void
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) throw new RuntimeException('reader self-test socket pair unavailable');
    [$reader, $writer] = $pair;
    fwrite($writer, "POST /webhook HTTP/1.1\r\nContent-Length: 2\r\n\r\n{}"); fclose($writer);
    $complete = simulatorReadRequest($reader); fclose($reader);
    if (($complete['body'] ?? null) !== '{}' || ($complete['headers']['content-length'] ?? null) !== '2') throw new RuntimeException('reader did not consume the declared Content-Length');
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) throw new RuntimeException('reader self-test socket pair unavailable');
    [$reader, $writer] = $pair;
    fwrite($writer, "POST /webhook HTTP/1.1\r\nContent-Length: 3\r\n\r\n{}"); fclose($writer);
    $incomplete = simulatorReadRequest($reader); fclose($reader);
    if (($incomplete['status'] ?? null) !== 400 || !str_contains((string) ($incomplete['body'] ?? ''), 'request body incomplete')) throw new RuntimeException('reader did not reject a closed incomplete Content-Length body');
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) throw new RuntimeException('reader self-test socket pair unavailable');
    [$reader, $writer] = $pair;
    fwrite($writer, "POST /webhook HTTP/1.1\r\nContent-Length: 16385\r\n\r\n"); fclose($writer);
    $tooLarge = simulatorReadRequest($reader); fclose($reader);
    if (($tooLarge['status'] ?? null) !== 413 || !str_contains((string) ($tooLarge['body'] ?? ''), 'request body too large')) throw new RuntimeException('reader did not reject an oversized Content-Length');
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) throw new RuntimeException('reader self-test socket pair unavailable');
    [$reader, $writer] = $pair;
    $timedOut = simulatorReadRequest($reader, 0, 1000); fclose($writer); fclose($reader);
    if (($timedOut['status'] ?? null) !== 400 || !str_contains((string) ($timedOut['body'] ?? ''), 'request header incomplete')) throw new RuntimeException('reader did not enforce the configured timeout');
}
function simulatorServe(int $port): never
{
    $cert = (string) getenv('SAND_IAM_ACCEPTANCE_SIMULATOR_TLS_CERT'); $key = (string) getenv('SAND_IAM_ACCEPTANCE_SIMULATOR_TLS_KEY'); $session = (string) getenv('SAND_IAM_ACCEPTANCE_SIMULATOR_SESSION'); $secret = (string) getenv('SAND_IAM_ACCEPTANCE_SIMULATOR_WEBHOOK_SECRET');
    if ($cert === '' || $key === '' || $secret === '' || strlen($session) < 24 || !is_file($cert) || !is_file($key)) throw new RuntimeException('启动本地 HTTPS 接收器需要 TLS_CERT、TLS_KEY、单轮 SESSION 和 WEBHOOK_SECRET。');
    $context = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false, 'allow_self_signed' => true]]); $server = stream_socket_server("tls://127.0.0.1:{$port}", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context); if ($server === false) throw new RuntimeException("无法绑定 HTTPS 接收器：{$error} ({$errno})"); $simulator = new TerminalAcceptanceSimulator();
    while ($connection = @stream_socket_accept($server, -1)) { $request = simulatorReadRequest($connection); $result = isset($request['status']) ? $request : simulatorRequest($simulator, $request['method'], $request['target'], $request['headers'], $request['body'], $secret, $session); $text = match ($result['status']) {200 => 'OK', 201 => 'Created', 204 => 'No Content', 400 => 'Bad Request', 401 => 'Unauthorized', 405 => 'Method Not Allowed', 409 => 'Conflict', 413 => 'Payload Too Large', 500 => 'Internal Server Error', default => 'Not Found'}; fwrite($connection, "HTTP/1.1 {$result['status']} {$text}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($result['body']) . "\r\nConnection: close\r\n\r\n" . $result['body']); fclose($connection); }
    exit(0);
}
try { $mode = $argv[1] ?? '--self-test'; if ($mode === '--self-test') { simulatorSelfTest(); echo "terminal acceptance local simulator self-test passed\n"; exit(0); } if ($mode === '--chain3-protocol') { echo json_encode(simulatorChainThreeProtocol(), JSON_THROW_ON_ERROR) . "\n"; exit(0); } if ($mode === '--chain5-protocol') { echo json_encode(simulatorChainFiveProtocol(), JSON_THROW_ON_ERROR) . "\n"; exit(0); } if ($mode === '--chain7-protocol') { echo json_encode(simulatorChainSevenProtocol(), JSON_THROW_ON_ERROR) . "\n"; exit(0); } if ($mode === '--serve') simulatorServe(max(1, min(65535, (int) (getenv('SAND_IAM_ACCEPTANCE_SIMULATOR_PORT') ?: 18443)))); throw new InvalidArgumentException('仅支持 --self-test、--chain3-protocol、--chain5-protocol、--chain7-protocol 或 --serve。'); } catch (Throwable $exception) { fwrite(STDERR, 'SandIAM local simulator failed: ' . $exception->getMessage() . "\n"); exit(1); }
