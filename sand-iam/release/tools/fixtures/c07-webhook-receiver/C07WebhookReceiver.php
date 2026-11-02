<?php

declare(strict_types=1);

final class C07WebhookReceiver
{
    private const PREFIX_PATTERN = '/^sand_iam_acceptance_[a-f0-9]{16}_$/';

    public function __construct(
        private readonly string $stateDirectory,
        private readonly string $controlToken,
        private readonly int $clockSkewSeconds = 300,
    ) {
        if ($controlToken === '' || strlen($controlToken) < 32) {
            throw new InvalidArgumentException('C07 receiver control token must contain at least 32 characters.');
        }
        if ($stateDirectory === '' || !str_starts_with($stateDirectory, '/')) {
            throw new InvalidArgumentException('C07 receiver state directory must be an absolute path.');
        }
        if (!is_dir($stateDirectory) && !mkdir($stateDirectory, 0700, true) && !is_dir($stateDirectory)) {
            throw new RuntimeException('C07 receiver state directory cannot be created.');
        }
        chmod($stateDirectory, 0700);
    }

    /** @param array<string,string> $headers @return array{status:int,body:array<string,mixed>|null} */
    public function handle(string $method, string $path, array $query, array $headers, string $body): array
    {
        if ($path === '/webhook/receive' && $method === 'POST') return $this->receive($query, $headers, $body);
        if ($path === '/webhook/proof' && $method === 'GET') return $this->proof($query);
        if ($path === '/webhook/control/configure' && $method === 'POST') return $this->configure($headers, $body);
        if ($path === '/webhook/control/cleanup' && $method === 'POST') return $this->cleanup($headers, $body);
        if ($path === '/webhook/control/status' && $method === 'GET') return $this->status($query, $headers);
        return $this->json(404, ['error' => 'not_found']);
    }

    /** @param array<string,string> $headers */
    private function configure(array $headers, string $body): array
    {
        $this->assertControl($headers);
        $payload = $this->decode($body);
        $scope = $this->scope($payload['scope'] ?? null);
        $secret = is_string($payload['secret'] ?? null) ? $payload['secret'] : '';
        $applicationId = filter_var($payload['application_id'] ?? null, FILTER_VALIDATE_INT);
        $endpointId = filter_var($payload['endpoint_id'] ?? null, FILTER_VALIDATE_INT);
        $requestId = is_string($payload['credential_issue_request_id'] ?? null) ? $payload['credential_issue_request_id'] : '';
        if (!str_starts_with($secret, 'siwh_') || strlen($secret) < 32
            || !is_int($applicationId) || $applicationId <= 0
            || !is_int($endpointId) || $endpointId <= 0
            || !str_starts_with($requestId, $scope)) {
            return $this->json(400, ['error' => 'invalid_configuration']);
        }
        $configId = hash('sha256', "c07\0{$scope}\0{$endpointId}");
        $state = [
            'config_id' => $configId,
            'scope' => $scope,
            'secret' => $secret,
            'application_id' => $applicationId,
            'endpoint_id' => $endpointId,
            'credential_issue_request_id' => $requestId,
            'attempts' => [],
            'event_id' => null,
            'credential_id' => null,
            'signature_verified' => false,
        ];
        $this->write($scope, $state);
        return $this->json(200, ['receiver_config_id' => $configId, 'scope' => $scope]);
    }

    /** @param array<string,mixed> $query @param array<string,string> $headers */
    private function receive(array $query, array $headers, string $body): array
    {
        $scope = $this->scope($query['scope'] ?? null);
        $state = $this->read($scope);
        if ($state === null) return $this->json(404, ['error' => 'scope_not_configured']);
        $timestamp = $headers['x-sandiam-timestamp'] ?? '';
        $signature = $headers['x-sandiam-signature'] ?? '';
        $eventId = $headers['x-sandiam-event-id'] ?? '';
        $eventType = $headers['x-sandiam-event-type'] ?? '';
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $this->clockSkewSeconds
            || $eventType !== 'credential.changed'
            || !str_starts_with($eventId, 'evt_')
            || !hash_equals('v1=' . hash_hmac('sha256', $timestamp . '.' . $body, (string) $state['secret']), $signature)) {
            return $this->json(401, ['error' => 'signature_invalid']);
        }
        $event = $this->decode($body);
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        if (($event['id'] ?? null) !== $eventId
            || ($event['type'] ?? null) !== 'credential.changed'
            || (int) ($event['application_id'] ?? 0) !== (int) $state['application_id']
            || ($data['action'] ?? null) !== 'credential.issue'
            || ($data['outcome'] ?? null) !== 'succeeded'
            || ($data['resource_type'] ?? null) !== 'credential'
            || !is_int($data['resource_id'] ?? null)
            || ($data['request_id'] ?? null) !== $state['credential_issue_request_id']) {
            return $this->json(422, ['error' => 'event_contract_invalid']);
        }
        if ($state['event_id'] !== null && $state['event_id'] !== $eventId) {
            return $this->json(409, ['error' => 'scope_event_conflict']);
        }
        $attempt = count($state['attempts']) + 1;
        $status = $attempt === 1 ? 500 : 204;
        $state['attempts'][] = ['status' => $status, 'received_at' => gmdate('c')];
        $state['event_id'] = $eventId;
        $state['credential_id'] = $data['resource_id'];
        $state['signature_verified'] = true;
        $this->write($scope, $state);
        return ['status' => $status, 'body' => $status === 204 ? null : ['error' => 'controlled_first_attempt_failure']];
    }

    /** @param array<string,mixed> $query */
    private function proof(array $query): array
    {
        $scope = $this->scope($query['scope'] ?? null);
        $state = $this->read($scope);
        if ($state === null) return $this->json(404, ['error' => 'scope_not_configured']);
        $attempts = is_array($state['attempts'] ?? null) ? $state['attempts'] : [];
        return $this->json(200, [
            'receiver_config_id' => $state['config_id'],
            'endpoint_id' => $state['endpoint_id'],
            'event_id' => $state['event_id'],
            'credential_id' => $state['credential_id'],
            'credential_issue_request_id' => $state['credential_issue_request_id'],
            'signature_verified' => $state['signature_verified'],
            'attempt_count' => count($attempts),
            'first_status' => $attempts[0]['status'] ?? null,
            'last_status' => $attempts === [] ? null : $attempts[array_key_last($attempts)]['status'],
        ]);
    }

    /** @param array<string,string> $headers */
    private function cleanup(array $headers, string $body): array
    {
        $this->assertControl($headers);
        $payload = $this->decode($body);
        $scope = $this->scope($payload['scope'] ?? null);
        $configId = is_string($payload['receiver_config_id'] ?? null) ? $payload['receiver_config_id'] : '';
        $state = $this->read($scope);
        if ($state !== null && !hash_equals((string) $state['config_id'], $configId)) {
            return $this->json(409, ['error' => 'config_id_mismatch']);
        }
        $path = $this->statePath($scope);
        if (is_file($path) && !unlink($path)) throw new RuntimeException('C07 receiver state cannot be deleted.');
        return $this->json(200, ['receiver_config_id' => $configId, 'residual' => 0]);
    }

    /** @param array<string,mixed> $query @param array<string,string> $headers */
    private function status(array $query, array $headers): array
    {
        $this->assertControl($headers);
        $scope = $this->scope($query['scope'] ?? null);
        return $this->json(200, ['residual' => is_file($this->statePath($scope)) ? 1 : 0]);
    }

    /** @param array<string,string> $headers */
    private function assertControl(array $headers): void
    {
        if (!hash_equals($this->controlToken, $headers['x-c07-control-token'] ?? '')) {
            throw new RuntimeException('C07 receiver control authorization failed.');
        }
    }

    /** @return array<string,mixed> */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('C07 receiver JSON body must be an object.');
        return $decoded;
    }

    private function scope(mixed $scope): string
    {
        if (!is_string($scope) || preg_match(self::PREFIX_PATTERN, $scope) !== 1) {
            throw new RuntimeException('C07 receiver scope is invalid.');
        }
        return $scope;
    }

    /** @return array<string,mixed>|null */
    private function read(string $scope): ?array
    {
        $path = $this->statePath($scope);
        if (!is_file($path)) return null;
        $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('C07 receiver state is invalid.');
        return $decoded;
    }

    /** @param array<string,mixed> $state */
    private function write(string $scope, array $state): void
    {
        $path = $this->statePath($scope);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('C07 receiver state cannot be written.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) throw new RuntimeException('C07 receiver state cannot be committed.');
    }

    private function statePath(string $scope): string
    {
        return $this->stateDirectory . '/' . hash('sha256', $scope) . '.json';
    }

    /** @param array<string,mixed> $body */
    private function json(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
