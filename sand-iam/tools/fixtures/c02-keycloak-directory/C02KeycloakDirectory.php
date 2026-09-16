<?php

declare(strict_types=1);

final class C02KeycloakDirectory
{
    private const PREFIX_PATTERN = '/^sand_iam_acceptance_[a-f0-9]{16}_$/';
    private const REALM_PATTERN = '/^[A-Za-z0-9._-]{1,255}$/';

    public function __construct(
        private readonly string $stateDirectory,
        private readonly string $controlToken,
    ) {
        if (strlen($controlToken) < 32) {
            throw new InvalidArgumentException('C02 directory control token must contain at least 32 characters.');
        }
        if ($stateDirectory === '' || !str_starts_with($stateDirectory, '/')) {
            throw new InvalidArgumentException('C02 directory state directory must be an absolute path.');
        }
        if (!is_dir($stateDirectory) && !mkdir($stateDirectory, 0700, true) && !is_dir($stateDirectory)) {
            throw new RuntimeException('C02 directory state directory cannot be created.');
        }
        chmod($stateDirectory, 0700);
    }

    /** @param array<string,mixed> $query @param array<string,string> $headers @return array{status:int,headers?:array<string,string>,body:array<string,mixed>|list<array<string,mixed>>} */
    public function handle(string $method, string $path, array $query, array $headers, string $body): array
    {
        if ($method === 'GET' && preg_match('#^/admin/realms/([^/]+)/users$#', $path, $matches) === 1) {
            return $this->users(rawurldecode($matches[1]), $query, $headers);
        }
        if ($method === 'POST' && $path === '/directory/control/configure') return $this->configure($headers, $body);
        if ($method === 'POST' && $path === '/directory/control/mutate') return $this->mutate($headers, $body);
        if ($method === 'POST' && $path === '/directory/control/cleanup') return $this->cleanup($headers, $body);
        if ($method === 'GET' && $path === '/directory/control/proof') return $this->proof($query, $headers);
        if ($method === 'GET' && $path === '/directory/control/status') return $this->status($query, $headers);
        return $this->json(404, ['error' => 'not_found']);
    }

    /** @param array<string,string> $headers */
    private function configure(array $headers, string $body): array
    {
        $this->assertControl($headers);
        $payload = $this->decode($body);
        $scope = $this->scope($payload['scope'] ?? null);
        $realm = $this->realm($payload['realm'] ?? null);
        $accessToken = is_string($payload['access_token'] ?? null) ? $payload['access_token'] : '';
        if (strlen($accessToken) < 32 || strlen($accessToken) > 16_384 || preg_match('/[\x00-\x1f\x7f]/', $accessToken)) {
            return $this->json(400, ['error' => 'invalid_access_token']);
        }
        $records = $this->records($payload['records'] ?? null, $scope);
        $configId = hash('sha256', "c02\0{$scope}\0{$realm}");
        $this->write($scope, [
            'config_id' => $configId,
            'scope' => $scope,
            'realm' => $realm,
            'access_token' => $accessToken,
            'records' => $records,
            'generation' => 1,
            'calls' => [],
        ]);
        return $this->json(200, ['directory_config_id' => $configId, 'scope' => $scope, 'generation' => 1]);
    }

    /** @param array<string,string> $headers */
    private function mutate(array $headers, string $body): array
    {
        $this->assertControl($headers);
        $payload = $this->decode($body);
        $scope = $this->scope($payload['scope'] ?? null);
        $state = $this->read($scope);
        if ($state === null || !hash_equals((string) $state['config_id'], (string) ($payload['directory_config_id'] ?? ''))) {
            return $this->json(409, ['error' => 'config_id_mismatch']);
        }
        $state['records'] = $this->records($payload['records'] ?? null, $scope);
        $state['generation'] = (int) $state['generation'] + 1;
        $this->write($scope, $state);
        return $this->json(200, ['directory_config_id' => $state['config_id'], 'generation' => $state['generation']]);
    }

    /** @param array<string,mixed> $query @param array<string,string> $headers */
    private function users(string $realm, array $query, array $headers): array
    {
        $realm = $this->realm($realm);
        [$scope, $state] = $this->stateForRealm($realm);
        if ($state === null) return $this->json(404, ['error' => 'scope_not_configured']);
        if (!hash_equals('Bearer ' . (string) $state['access_token'], $headers['authorization'] ?? '')) {
            return $this->json(401, ['error' => 'directory_authorization_failed']);
        }
        $first = $this->integer($query['first'] ?? null, 0, 999_999_999);
        $maximum = $this->integer($query['max'] ?? null, 1, 500);
        if (($query['briefRepresentation'] ?? null) !== 'true') {
            return $this->json(400, ['error' => 'brief_representation_required']);
        }
        $state['calls'][] = [
            'first' => $first,
            'max' => $maximum,
            'generation' => (int) $state['generation'],
            'received_at' => gmdate('c'),
        ];
        $this->write($scope, $state);
        return $this->json(200, array_slice($state['records'], $first, $maximum));
    }

    /** @return array{0:string,1:array<string,mixed>|null} */
    private function stateForRealm(string $realm): array
    {
        foreach (glob($this->stateDirectory . '/*.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !is_string($decoded['scope'] ?? null) || !is_string($decoded['realm'] ?? null)) {
                throw new RuntimeException('C02 directory state is invalid.');
            }
            if (hash_equals($decoded['realm'], $realm)) return [$this->scope($decoded['scope']), $decoded];
        }
        return ['', null];
    }

    /** @param array<string,mixed> $query @param array<string,string> $headers */
    private function proof(array $query, array $headers): array
    {
        $this->assertControl($headers);
        $scope = $this->scope($query['scope'] ?? null);
        $state = $this->read($scope);
        if ($state === null) return $this->json(404, ['error' => 'scope_not_configured']);
        $calls = is_array($state['calls'] ?? null) ? $state['calls'] : [];
        return $this->json(200, [
            'directory_config_id' => $state['config_id'],
            'realm' => $state['realm'],
            'generation' => $state['generation'],
            'record_count' => count($state['records']),
            'call_count' => count($calls),
            'generations_seen' => array_values(array_unique(array_map(
                static fn (array $call): int => (int) ($call['generation'] ?? 0),
                $calls,
            ))),
            'last_first' => $calls === [] ? null : $calls[array_key_last($calls)]['first'],
            'last_max' => $calls === [] ? null : $calls[array_key_last($calls)]['max'],
        ]);
    }

    /** @param array<string,string> $headers */
    private function cleanup(array $headers, string $body): array
    {
        $this->assertControl($headers);
        $payload = $this->decode($body);
        $scope = $this->scope($payload['scope'] ?? null);
        $state = $this->read($scope);
        $configId = is_string($payload['directory_config_id'] ?? null) ? $payload['directory_config_id'] : '';
        if ($state !== null && !hash_equals((string) $state['config_id'], $configId)) {
            return $this->json(409, ['error' => 'config_id_mismatch']);
        }
        $path = $this->statePath($scope);
        if (is_file($path) && !unlink($path)) throw new RuntimeException('C02 directory state cannot be deleted.');
        return $this->json(200, ['directory_config_id' => $configId, 'residual' => 0]);
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
        if (!hash_equals($this->controlToken, $headers['x-c02-control-token'] ?? '')) {
            throw new RuntimeException('C02 directory control authorization failed.');
        }
    }

    /** @return list<array<string,mixed>> */
    private function records(mixed $records, string $scope): array
    {
        if (!is_array($records) || !array_is_list($records) || count($records) < 1 || count($records) > 20) {
            throw new RuntimeException('C02 directory records are invalid.');
        }
        $result = [];
        $ids = [];
        foreach ($records as $record) {
            if (!is_array($record)) throw new RuntimeException('C02 directory record is invalid.');
            $id = is_string($record['id'] ?? null) ? trim($record['id']) : '';
            $username = is_string($record['username'] ?? null) ? trim($record['username']) : '';
            $email = is_string($record['email'] ?? null) ? strtolower(trim($record['email'])) : '';
            $firstName = is_string($record['firstName'] ?? null) ? trim($record['firstName']) : '';
            $lastName = is_string($record['lastName'] ?? null) ? trim($record['lastName']) : '';
            $enabled = $record['enabled'] ?? null;
            if (!str_starts_with($id, $scope) || isset($ids[$id])
                || $username === '' || strlen($username) > 128
                || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || strlen($firstName) > 128 || strlen($lastName) > 128
                || !is_bool($enabled)) {
                throw new RuntimeException('C02 directory record contract is invalid.');
            }
            $ids[$id] = true;
            $result[] = compact('id', 'username', 'email', 'firstName', 'lastName', 'enabled');
        }
        return $result;
    }

    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,8})$/', $value) === 1))
            || (int) $value < $minimum || (int) $value > $maximum) {
            throw new RuntimeException('C02 directory pagination is invalid.');
        }
        return (int) $value;
    }

    /** @return array<string,mixed> */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('C02 directory JSON body must be an object.');
        return $decoded;
    }

    private function scope(mixed $scope): string
    {
        if (!is_string($scope) || preg_match(self::PREFIX_PATTERN, $scope) !== 1) {
            throw new RuntimeException('C02 directory scope is invalid.');
        }
        return $scope;
    }

    private function realm(mixed $realm): string
    {
        if (!is_string($realm) || preg_match(self::REALM_PATTERN, $realm) !== 1) {
            throw new RuntimeException('C02 directory realm is invalid.');
        }
        return $realm;
    }

    /** @return array<string,mixed>|null */
    private function read(string $scope): ?array
    {
        $path = $this->statePath($scope);
        if (!is_file($path)) return null;
        $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('C02 directory state is invalid.');
        return $decoded;
    }

    /** @param array<string,mixed> $state */
    private function write(string $scope, array $state): void
    {
        $path = $this->statePath($scope);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('C02 directory state cannot be written.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) throw new RuntimeException('C02 directory state cannot be committed.');
    }

    private function statePath(string $scope): string
    {
        return $this->stateDirectory . '/' . hash('sha256', $scope) . '.json';
    }

    /** @param array<string,mixed>|list<array<string,mixed>> $body */
    private function json(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
