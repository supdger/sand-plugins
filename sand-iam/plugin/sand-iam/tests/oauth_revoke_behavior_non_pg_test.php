<?php
declare(strict_types=1);

namespace OAuthRevokeTest {
    final class State {
        public static array $rows = [];
        public static array $audits = [];
        public static ?array $before = null;
        public static string $failure = '';
        public static array $config = [];
        public static array $locks = [];
    }
    class Model {
        public function __construct(private array $data) {}
        public function __get(string $name): mixed { return $this->data[$name] ?? null; }
        public function save(array $values): void {
            $this->data = array_replace($this->data, $values);
            State::$rows[static::class][$this->id] = $this->data;
        }
        public static function where(string $name, mixed $value): Query { return (new Query(static::class))->where($name, $value); }
        public static function whereIn(string $name, array $values): Query { return (new Query(static::class))->whereIn($name, $values); }
        public static function find(int $id): ?object { return static::where('id', $id)->find(); }
        public static function count(): int { return count(State::$rows[static::class] ?? []); }
        public static function create(array $values): static {
            $values['id'] = max([0, ...array_keys(State::$rows[static::class] ?? [])]) + 1;
            State::$rows[static::class][$values['id']] = $values;
            if (static::class === \plugin\SandIam\app\model\OAuthToken::class && State::$failure === 'token') {
                throw new \RuntimeException('token write failed');
            }
            if (static::class === \plugin\SandIam\app\model\OidcLogoutDelivery::class && State::$failure === 'delivery') {
                throw new \RuntimeException('delivery write failed');
            }
            return new static($values);
        }
    }
    final class Query implements \IteratorAggregate {
        private array $filters = [];
        private array $sets = [];
        private bool $locked = false;
        public function __construct(private string $model) {}
        public function where(string $name, mixed $value): self { $this->filters[$name] = $value; return $this; }
        public function whereNull(string $name): self { return $this->where($name, null); }
        public function whereIn(string $name, array $values): self { $this->sets[$name] = $values; return $this; }
        public function lock(bool $locked): self { $this->locked = $locked; return $this; }
        private function matches(array $row): bool {
            foreach ($this->filters as $name => $value) if (($row[$name] ?? null) !== $value) return false;
            foreach ($this->sets as $name => $values) if (!in_array($row[$name] ?? null, $values, true)) return false;
            return true;
        }
        public function select(): self { return $this; }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->all()); }
        public function all(): array {
            return array_map(fn (array $row): object => new ($this->model)($row), array_values(array_filter(State::$rows[$this->model] ?? [], fn (array $row): bool => $this->matches($row))));
        }
        public function find(): ?object {
            $row = $this->all()[0] ?? null;
            if ($row !== null && $this->locked) {
                check(State::$before !== null, 'Row lock outside transaction');
                State::$locks[] = [$this->model, $row->id];
            }
            return $row;
        }
        public function update(array $values): void {
            foreach (State::$rows[$this->model] ?? [] as $id => $row) {
                if (!$this->matches($row)) continue;
                State::$rows[$this->model][$id] = array_replace($row, $values);
                if ($this->model === \plugin\SandIam\app\model\OAuthToken::class && State::$failure === 'token') {
                    throw new \RuntimeException('token write failed');
                }
            }
        }
    }
    function check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    class Application extends \OAuthRevokeTest\Model {}
    class Organization extends \OAuthRevokeTest\Model {}
    class OAuthClient extends \OAuthRevokeTest\Model {}
    class OAuthGrant extends \OAuthRevokeTest\Model {}
    class OAuthToken extends \OAuthRevokeTest\Model {}
    class OidcSigningKey extends \OAuthRevokeTest\Model {}
    class Identity extends \OAuthRevokeTest\Model {}
    class AuthSession extends \OAuthRevokeTest\Model {}
    class AuthRefreshToken extends \OAuthRevokeTest\Model {}
    class OidcLogoutDelivery extends \OAuthRevokeTest\Model {}
    class AuthorizationCode extends \OAuthRevokeTest\Model {}
}
namespace think\facade {
    use OAuthRevokeTest\State;
    class Db {
        public static function startTrans(): void {
            \OAuthRevokeTest\check(State::$before === null, 'Unexpected nested transaction');
            State::$before = [State::$rows, State::$audits];
            State::$locks = [];
        }
        public static function commit(): void { State::$before = null; }
        public static function rollback(): void {
            if (State::$before !== null) [State::$rows, State::$audits] = State::$before;
            State::$before = null;
        }
    }
}
namespace plugin\SandIam\app\service {
    class AuditWriter {
        public function write(...$arguments): void {
            \OAuthRevokeTest\State::$audits[] = $arguments;
            if (\OAuthRevokeTest\State::$failure === 'audit') throw new \RuntimeException('audit write failed');
        }
    }
}
namespace {
    use OAuthRevokeTest\State;
    use function OAuthRevokeTest\check;
    use plugin\SandIam\app\model\{Application, Organization, OAuthClient, OAuthGrant, OAuthToken, Identity, AuthSession, AuthorizationCode};
    use plugin\SandIam\app\service\OAuthOidcService;
    function config(string $name, mixed $default = null): mixed { return State::$config[$name] ?? $default; }
    require dirname(__DIR__) . '/app/service/OAuthOidcService.php';
    require dirname(__DIR__) . '/app/service/OidcLogoutTokenCipher.php';
    require dirname(__DIR__) . '/app/service/OidcBackchannelLogoutService.php';

    // Ephemeral fixture only: no host key/config files or database are accessed.
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    check($key !== false && openssl_pkey_export($key, $pem), 'Cannot create in-memory signing fixture');
    State::$config = [
        'plugin.sand-iam.app.oidc_issuer' => 'https://issuer.example/api/sand-iam/v1',
        'plugin.sand-iam.app.oidc_kid' => 'offline',
        'plugin.sand-iam.app.oidc_private_key_base64' => base64_encode($pem),
        'plugin.sand-iam.app.auth_pepper' => 'offline-pepper',
        'plugin.sand-iam.app.oidc_subject_key' => str_repeat('s', 32),
    ];
    $hash = static fn (string $value): string => hash_hmac('sha256', $value, 'offline-pepper');
    State::$rows = [
        Organization::class => [1 => ['id' => 1, 'status' => 1]],
        Application::class => [10 => ['id' => 10, 'organization_id' => 1, 'status' => 1]],
        OAuthClient::class => [20 => ['id' => 20, 'application_id' => 10, 'code' => 'client', 'status' => 1, 'client_type' => 'confidential', 'secret_hash' => password_hash($hash('client-secret:correct'), PASSWORD_DEFAULT)]],
        OAuthGrant::class => [30 => ['id' => 30, 'status' => 1, 'revoked_time' => null], 31 => ['id' => 31, 'status' => 1, 'revoked_time' => null]],
        OAuthToken::class => [
            40 => ['id' => 40, 'grant_id' => 30, 'client_id' => 20, 'application_id' => 10, 'token_hash' => $hash('token:access'), 'status' => 1, 'revoked_time' => null],
            41 => ['id' => 41, 'grant_id' => 30, 'client_id' => 20, 'application_id' => 10, 'token_hash' => $hash('token:refresh'), 'status' => 1, 'revoked_time' => null],
            42 => ['id' => 42, 'grant_id' => 31, 'client_id' => 21, 'application_id' => 11, 'token_hash' => $hash('token:foreign'), 'status' => 1, 'revoked_time' => null],
        ],
    ];
    $initial = State::$rows;
    $payload = ['client_id' => 'client', 'client_secret' => 'correct', 'token' => 'access'];
    $service = new OAuthOidcService();
    foreach (['token', 'audit'] as $failure) {
        State::$failure = $failure;
        $before = [State::$rows, State::$audits];
        try { $service->revoke($payload, 'failed-' . $failure); throw new \RuntimeException('Failure ignored'); }
        catch (\RuntimeException $error) { check($error->getMessage() === $failure . ' write failed', $error->getMessage()); }
        check([State::$rows, State::$audits] === $before && State::$before === null, 'Partial grant/token/audit state after ' . $failure . ' failure');
    }
    State::$failure = '';
    $service->revoke($payload, 'recovered');
    check(State::$rows[OAuthGrant::class][30]['status'] === 2 && State::$rows[OAuthToken::class][40]['status'] === 2 && State::$rows[OAuthToken::class][41]['status'] === 2, 'Recovery did not revoke whole grant');
    check(State::$rows[OAuthGrant::class][31] === $initial[OAuthGrant::class][31] && State::$rows[OAuthToken::class][42] === $initial[OAuthToken::class][42], 'Revocation changed unrelated grant/token');
    check(count(State::$audits) === 1 && State::$audits[0][9] === ['known_token' => true], 'Wrong successful revocation audit');
    $revoked = State::$rows;
    $service->revoke($payload, 'repeat');
    check(State::$rows === $revoked && count(State::$audits) === 2, 'Repeated revoke changed established state/audit behavior');
    foreach (['missing', '', 'foreign'] as $token) {
        $service->revoke(array_replace($payload, ['token' => $token]), 'unknown');
        check(State::$rows === $revoked && end(State::$audits)[9] === ['known_token' => false], 'Unknown or foreign token revealed or changed grant');
    }
    foreach (['secret', 'client', 'application', 'organization'] as $disabled) {
        $before = [State::$rows, State::$audits];
        $request = $payload;
        if ($disabled === 'secret') $request['client_secret'] = 'wrong';
        if ($disabled === 'client') State::$rows[OAuthClient::class][20]['status'] = 2;
        if ($disabled === 'application') State::$rows[Application::class][10]['status'] = 2;
        if ($disabled === 'organization') State::$rows[Organization::class][1]['status'] = 2;
        $rows = State::$rows;
        try { $service->revoke($request, 'invalid-client'); throw new \RuntimeException('Invalid client accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 401, 'Wrong client error'); }
        check(State::$rows === $rows && State::$audits === $before[1] && State::$before === null, 'Invalid client changed revocation state');
        State::$rows = $before[0];
    }
    echo "OAuth revoke actual-entry rollback and isolation non-PG behavior PASS\n";

    State::$rows = $initial;
    State::$audits = [];
    State::$rows[OAuthClient::class][20] += [
        'allowed_scopes' => ['invoice.read', 'openid', 'offline_access'],
        'allowed_audiences' => ['https://resource.example'],
        'default_audience' => 'https://resource.example',
    ];
    $machine = ['client_id' => 'client', 'client_secret' => 'correct', 'grant_type' => 'client_credentials', 'scope' => 'invoice.read'];
    foreach ([
        ['audience' => 'https://other.example'],
        ['scope' => 'openid'],
        ['scope' => 'offline_access'],
        ['scope' => 'unknown'],
    ] as $invalid) {
        $before = [State::$rows, State::$audits];
        try { $service->token(array_replace($machine, $invalid), 'invalid-machine'); throw new \RuntimeException('Invalid machine request accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 400, 'Wrong machine validation error'); }
        check([State::$rows, State::$audits] === $before && State::$before === null, 'Invalid machine request persisted a grant');
    }
    foreach (['token', 'audit'] as $failure) {
        State::$failure = $failure;
        $before = [State::$rows, State::$audits];
        try { $service->token($machine, 'failed-machine'); throw new \RuntimeException('Issuance failure ignored'); }
        catch (\RuntimeException $error) { check($error->getMessage() === $failure . ' write failed', $error->getMessage()); }
        check([State::$rows, State::$audits] === $before && State::$before === null, 'Partial machine issuance after failure');
    }
    State::$failure = '';
    $beforeCounts = [count(State::$rows[OAuthGrant::class]), count(State::$rows[OAuthToken::class])];
    $response = $service->token($machine, 'machine-recovered');
    check(count(State::$rows[OAuthGrant::class]) === $beforeCounts[0] + 1 && count(State::$rows[OAuthToken::class]) === $beforeCounts[1] + 1 && count(State::$audits) === 1, 'Machine recovery did not create exactly one grant, token and audit');
    check(!array_key_exists('refresh_token', $response) && !array_key_exists('id_token', $response) && $response['token_type'] === 'Bearer' && $response['scope'] === 'invoice.read', 'Machine response changed token contract');
    [$headerPart, $claimsPart, $signaturePart] = explode('.', $response['access_token']);
    $claims = json_decode(base64_decode(strtr($claimsPart, '-_', '+/'), true), true, 32, JSON_THROW_ON_ERROR);
    check($claims['aud'] === 'https://resource.example' && $claims['sub'] === 'client:client' && $claims['identity_id'] === null && $claims['application_id'] === 10 && $claims['scope'] === 'invoice.read', 'Machine JWT lost audience or client ownership');
    $public = openssl_pkey_get_details($key)['key'];
    check(openssl_verify($headerPart . '.' . $claimsPart, base64_decode(strtr($signaturePart, '-_', '+/'), true), $public, OPENSSL_ALGO_SHA256) === 1, 'Machine JWT signature invalid');
    $stored = end(State::$rows[OAuthToken::class]);
    check($stored['token_hash'] === $hash('token:' . $response['access_token']) && $stored['grant_id'] === $claims['grant_id'], 'Returned token does not match persisted grant');
    $verifyMachine = static fn (): array => $service->verifyAccessTokenForAudience($response['access_token'], 'https://resource.example', ['invoice.read']);
    check($verifyMachine() === $claims, 'Actual resource verifier rejected issued machine token');
    foreach ([
        [OAuthToken::class, $stored['id'], 'status', 2],
        [OAuthToken::class, $stored['id'], 'revoked_time', '2000-01-01 00:00:00'],
        [OAuthToken::class, $stored['id'], 'expire_time', '2000-01-01 00:00:00'],
        [OAuthGrant::class, $claims['grant_id'], 'status', 2],
        [OAuthGrant::class, $claims['grant_id'], 'revoked_time', '2000-01-01 00:00:00'],
        [OAuthClient::class, 20, 'status', 2],
        [Application::class, 10, 'status', 2],
        [Organization::class, 1, 'status', 2],
    ] as [$model, $id, $field, $value]) {
        $baseline = State::$rows;
        State::$rows[$model][$id][$field] = $value;
        try { $verifyMachine(); throw new \RuntimeException('Inactive resource dependency accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 401, 'Wrong resource dependency rejection'); }
        State::$rows = $baseline;
        check($verifyMachine() === $claims, 'Restored resource dependency remained stale');
    }
    foreach ([['https://other.example', ['invoice.read'], 401], ['https://resource.example', ['invoice.write'], 403]] as [$audience, $scopes, $expected]) {
        try { $service->verifyAccessTokenForAudience($response['access_token'], $audience, $scopes); throw new \RuntimeException('Wrong resource audience/scope accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === $expected, 'Wrong audience/scope error'); }
    }
    $tamperedClaims = array_replace($claims, ['scope' => 'invoice.write']);
    $tamperedBody = rtrim(strtr(base64_encode(json_encode($tamperedClaims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    try { $service->verifyAccessTokenForAudience($headerPart . '.' . $tamperedBody . '.' . $signaturePart, 'https://resource.example', ['invoice.write']); throw new \RuntimeException('Tampered signed claims accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 401, 'Wrong tampered JWT error'); }
    $service->revoke(array_replace($payload, ['token' => $response['access_token']]), 'machine-revoke');
    check(State::$rows[OAuthToken::class][$stored['id']]['status'] === 2 && State::$rows[OAuthGrant::class][$claims['grant_id']]['status'] === 2, 'Issued machine token could not be revoked');
    try { $verifyMachine(); throw new \RuntimeException('Revoked machine token still accepted by resource verifier'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 401, 'Wrong revoked machine token error'); }
    echo "OAuth machine issuance validation, rollback and revocation non-PG behavior PASS\n";

    State::$rows = $initial;
    State::$audits = [];
    State::$rows[Identity::class] = [50 => ['id' => 50, 'application_id' => 10, 'status' => 1]];
    State::$rows[AuthSession::class] = [60 => ['id' => 60, 'application_id' => 10, 'identity_id' => 50, 'status' => 1, 'revoked_time' => null]];
    State::$rows[OAuthGrant::class][30] += ['application_id' => 10, 'client_id' => 20, 'identity_id' => 50, 'auth_session_id' => 60, 'scope' => 'invoice.read offline_access'];
    State::$rows[OAuthToken::class][41] += ['token_type' => 'refresh', 'used_time' => null, 'expire_time' => date('Y-m-d H:i:s', time() + 3600)];
    $refresh = ['client_id' => 'client', 'client_secret' => 'correct', 'grant_type' => 'refresh_token', 'refresh_token' => 'refresh'];
    foreach (['token', 'audit'] as $failure) {
        State::$failure = $failure;
        $before = [State::$rows, State::$audits];
        try { $service->token($refresh, 'failed-refresh'); throw new \RuntimeException('Refresh failure ignored'); }
        catch (\RuntimeException $error) { check($error->getMessage() === $failure . ' write failed', $error->getMessage()); }
        check([State::$rows, State::$audits] === $before && State::$before === null, 'Refresh failed but consumed token or persisted replacement/audit');
    }
    State::$failure = '';
    $response = $service->token($refresh, 'refresh-recovered');
    check(State::$locks === [[OAuthGrant::class, 30], [OAuthToken::class, 41]], 'Refresh does not lock grant before token');
    check(State::$rows[OAuthToken::class][41]['status'] === 2 && State::$rows[OAuthToken::class][41]['used_time'] !== null, 'Refresh did not consume old token');
    check(count(State::$rows[OAuthToken::class]) === 5 && count(State::$audits) === 1 && isset($response['access_token'], $response['refresh_token']), 'Refresh retry did not issue one replacement pair');
    check(end(State::$audits)[4] === 'oauth.refresh', 'Refresh success audit missing');
    try { $service->token($refresh, 'real-replay'); throw new \RuntimeException('Consumed refresh accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getMessage() === 'SAND_IAM_OAUTH_REFRESH_REPLAY_DETECTED' && $error->getCode() === 401, 'Replay error changed'); }
    check(State::$rows[OAuthGrant::class][30]['status'] === 2 && State::$before === null, 'Replay did not commit grant revocation');
    foreach (State::$rows[OAuthToken::class] as $row) {
        if ($row['grant_id'] === 30) check($row['status'] === 2, 'Replay left an active replacement token');
    }
    check(end(State::$audits)[4] === 'oauth.refresh_replay' && end(State::$audits)[7] === 'denied', 'Replay audit changed');
    echo "OAuth refresh rollback, lock order and replay non-PG behavior PASS\n";

    State::$rows = $initial;
    State::$audits = [];
    $expires = date('Y-m-d H:i:s', time() + 3600);
    State::$rows[Identity::class] = [50 => ['id' => 50, 'application_id' => 10, 'status' => 1]];
    State::$rows[AuthSession::class] = [60 => ['id' => 60, 'application_id' => 10, 'identity_id' => 50, 'status' => 1, 'revoked_time' => null, 'access_expire_time' => $expires]];
    State::$rows[OAuthClient::class][20]['redirect_uris'] = ['https://client.example/callback'];
    $verifier = str_repeat('v', 43);
    $b64 = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $nonceBytes = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $encryptedNonce = $b64($nonceBytes . sodium_crypto_secretbox('offline-oidc-nonce', $nonceBytes, hash('sha256', 'sand-iam:oidc-nonce:offline-pepper', true)));
    State::$rows[AuthorizationCode::class] = [70 => [
        'id' => 70, 'application_id' => 10, 'client_id' => 20, 'identity_id' => 50, 'auth_session_id' => 60,
        'code_hash' => $hash('code:authorization'), 'status' => 1, 'consumed_time' => null, 'expire_time' => $expires,
        'redirect_uri' => 'https://client.example/callback', 'code_challenge' => $b64(hash('sha256', $verifier, true)),
        'scope' => 'openid offline_access', 'auth_time' => date('Y-m-d H:i:s'), 'encrypted_nonce' => $encryptedNonce,
    ]];
    $exchange = ['client_id' => 'client', 'client_secret' => 'correct', 'grant_type' => 'authorization_code', 'code' => 'authorization', 'redirect_uri' => 'https://client.example/callback', 'code_verifier' => $verifier];
    foreach ([['code_verifier' => str_repeat('x', 43)], ['redirect_uri' => 'https://client.example/other'], ['code' => 'unknown']] as $invalid) {
        $before = [State::$rows, State::$audits];
        try { $service->token(array_replace($exchange, $invalid), 'invalid-code'); throw new \RuntimeException('Invalid authorization code accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 400, 'Wrong code rejection'); }
        check([State::$rows, State::$audits] === $before && State::$before === null, 'Invalid code consumed state');
    }
    foreach ([
        [AuthorizationCode::class, 70, 'application_id', 11],
        [AuthorizationCode::class, 70, 'client_id', 21],
        [AuthorizationCode::class, 70, 'expire_time', '2000-01-01 00:00:00'],
        [AuthSession::class, 60, 'identity_id', 51],
        [AuthSession::class, 60, 'status', 2],
        [AuthSession::class, 60, 'revoked_time', '2000-01-01 00:00:00'],
        [AuthSession::class, 60, 'access_expire_time', '2000-01-01 00:00:00'],
        [Identity::class, 50, 'status', 2],
    ] as [$model, $id, $field, $value]) {
        $baseline = State::$rows;
        State::$rows[$model][$id][$field] = $value;
        $before = [State::$rows, State::$audits];
        try { $service->token($exchange, 'invalid-code-context'); throw new \RuntimeException('Invalid code context accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 400, 'Wrong code context rejection'); }
        check([State::$rows, State::$audits] === $before && State::$before === null, 'Invalid code context changed state');
        State::$rows = $baseline;
    }
    foreach (['token', 'audit'] as $failure) {
        State::$failure = $failure;
        $before = [State::$rows, State::$audits];
        try { $service->token($exchange, 'failed-exchange'); throw new \RuntimeException('Code exchange failure ignored'); }
        catch (\RuntimeException $error) { check($error->getMessage() === $failure . ' write failed', $error->getMessage()); }
        check([State::$rows, State::$audits] === $before && State::$before === null, 'Code exchange failed but consumed code or persisted tokens/audit');
    }
    State::$failure = '';
    $response = $service->token($exchange, 'exchange-recovered');
    check(State::$rows[AuthorizationCode::class][70]['status'] === 2 && State::$rows[AuthorizationCode::class][70]['consumed_time'] !== null, 'Exchange did not consume code');
    check(count(State::$rows[OAuthGrant::class]) === 3 && count(State::$rows[OAuthToken::class]) === 6 && count(State::$audits) === 1, 'Exchange recovery duplicated grant or token set');
    check(isset($response['access_token'], $response['refresh_token'], $response['id_token']), 'OIDC exchange omitted expected token');
    [$idHeader, $idBody, $idSignature] = explode('.', $response['id_token']);
    $idClaims = json_decode(base64_decode(strtr($idBody, '-_', '+/'), true), true, 32, JSON_THROW_ON_ERROR);
    check($idClaims['nonce'] === 'offline-oidc-nonce' && $idClaims['aud'] === 'client' && $idClaims['sid'] === '60', 'OIDC nonce, audience or session lost');
    check(openssl_verify($idHeader . '.' . $idBody, base64_decode(strtr($idSignature, '-_', '+/'), true), $public, OPENSSL_ALGO_SHA256) === 1, 'ID token signature invalid');
    $before = [State::$rows, State::$audits];
    try { $service->token($exchange, 'code-replay'); throw new \RuntimeException('Consumed code accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 400, 'Wrong consumed code error'); }
    check([State::$rows, State::$audits] === $before && State::$before === null, 'Code replay changed existing grant');
    echo "OAuth authorization code exchange and OIDC rollback non-PG behavior PASS\n";
    $verifyUser = static fn (): array => $service->verifyAccessTokenForAudience($response['access_token'], 'https://issuer.example/api/sand-iam/v1/oauth/userinfo', ['openid']);
    check($verifyUser()['identity_id'] === 50, 'Issued user token rejected');
    foreach ([
        [Identity::class, 50, 'status', 2],
        [Identity::class, 50, 'application_id', 11],
        [AuthSession::class, 60, 'status', 2],
        [AuthSession::class, 60, 'revoked_time', '2000-01-01 00:00:00'],
        [AuthSession::class, 60, 'identity_id', 51],
        [AuthSession::class, 60, 'application_id', 11],
    ] as [$model, $id, $field, $value]) {
        $baseline = State::$rows;
        State::$rows[$model][$id][$field] = $value;
        try { $verifyUser(); throw new \RuntimeException('User token accepted after identity/session invalidation: ' . $field); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 401, 'Wrong invalid user context error'); }
        State::$rows = $baseline;
        check($verifyUser()['identity_id'] === 50, 'Restored user context rejected');
    }
    foreach ([Identity::class => 50, AuthSession::class => 60] as $model => $id) {
        $baseline = State::$rows;
        unset(State::$rows[$model][$id]);
        try { $verifyUser(); throw new \RuntimeException('User token accepted after context removal'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 401, 'Wrong removed user context error'); }
        State::$rows = $baseline;
    }
    echo "OAuth user access token live identity/session non-PG behavior PASS\n";
    State::$rows[OAuthClient::class][20]['post_logout_redirect_uris'] = ['https://client.example/logout'];
    $refreshModel = \plugin\SandIam\app\model\AuthRefreshToken::class;
    State::$rows[$refreshModel][90] = ['id' => 90, 'session_id' => 60, 'status' => 1];
    State::$rows[$refreshModel][91] = ['id' => 91, 'session_id' => 61, 'status' => 1];
    $logout = ['id_token_hint' => $response['id_token'], 'post_logout_redirect_uri' => 'https://client.example/logout', 'state' => 'return here'];
    $logoutBaseline = [State::$rows, State::$audits];
    foreach ([str_repeat('s', 1025), "bad\nstate"] as $invalidState) {
        $before = [State::$rows, State::$audits];
        try { $service->logout(array_replace($logout, ['state' => $invalidState]), 'bad-logout'); throw new \RuntimeException('Invalid logout state accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getMessage() === 'SAND_IAM_OIDC_LOGOUT_STATE_INVALID', 'Wrong logout state error'); }
        check([State::$rows, State::$audits] === $before, 'Invalid logout state revoked session or tokens');
    }
    foreach (['token', 'audit'] as $failure) {
        State::$failure = $failure;
        $before = [State::$rows, State::$audits];
        try { $service->logout($logout, 'failed-logout'); throw new \RuntimeException('Logout failure ignored'); }
        catch (\RuntimeException $error) { check($error->getMessage() === $failure . ' write failed', $error->getMessage()); }
        check([State::$rows, State::$audits] === $before && State::$before === null, 'Failed logout left partial revocation');
    }
    State::$failure = '';
    $result = $service->logout($logout, 'logout-recovered');
    check($result['redirect_uri'] === 'https://client.example/logout?state=return%20here', 'Logout state redirect lost');
    check(State::$rows[AuthSession::class][60]['status'] === 2 && State::$rows[$refreshModel][90]['status'] === 2, 'Logout left human session active');
    check(State::$rows[$refreshModel][91]['status'] === 1 && State::$rows[OAuthToken::class][42]['status'] === 1, 'Logout changed unrelated session/token');
    try { $verifyUser(); throw new \RuntimeException('Access token usable after logout'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 401, 'Wrong post-logout rejection'); }
    echo "OAuth logout validation and atomic revocation non-PG behavior PASS\n";
    [State::$rows, State::$audits] = $logoutBaseline;
    State::$config['plugin.sand-iam.app.oidc_frontchannel_logout_enabled'] = 1;
    State::$config['plugin.sand-iam.app.oidc_backchannel_logout_enabled'] = 1;
    State::$config['plugin.sand-iam.app.oidc_logout_encryption_key'] = base64_encode(random_bytes(32));
    State::$rows[OAuthClient::class][20]['frontchannel_logout_uri'] = 'https://client.example/front';
    State::$rows[OAuthClient::class][20]['backchannel_logout_uri'] = 'https://client.example/back';
    $deliveryModel = \plugin\SandIam\app\model\OidcLogoutDelivery::class;
    foreach (['delivery', 'token', 'audit'] as $failure) {
        State::$failure = $failure;
        $before = [State::$rows, State::$audits];
        try { $service->logout($logout, 'notification-failure'); throw new \RuntimeException('Notification logout failure ignored'); }
        catch (\RuntimeException $error) { check($error->getMessage() === $failure . ' write failed', $error->getMessage()); }
        check([State::$rows, State::$audits] === $before && State::$before === null, 'Failed logout retained notification or partial revocation');
    }
    State::$failure = '';
    $result = $service->logout($logout, 'notification-recovered');
    check(count($result['frontchannel_uris']) === 1, 'Frontchannel URI missing');
    parse_str((string) parse_url($result['frontchannel_uris'][0], PHP_URL_QUERY), $frontQuery);
    check($frontQuery === ['iss' => 'https://issuer.example/api/sand-iam/v1', 'sid' => '60'], 'Frontchannel session/issuer incorrect');
    check(count(State::$rows[$deliveryModel]) === 1, 'Recovery duplicated notification');
    $delivery = array_values(State::$rows[$deliveryModel])[0];
    check($delivery['state'] === 'pending' && $delivery['application_id'] === 10 && $delivery['oauth_client_id'] === 20 && $delivery['auth_session_id'] === 60, 'Notification scope/state incorrect');
    $cipher = new \plugin\SandIam\app\service\OidcLogoutTokenCipher();
    $wire = json_decode($cipher->decrypt($delivery['encrypted_logout_token']), true, 32, JSON_THROW_ON_ERROR);
    check($wire['target_uri'] === 'https://client.example/back', 'Encrypted notification target incorrect');
    [$header, $body, $signature] = explode('.', $wire['logout_token']);
    $claims = json_decode(base64_decode(strtr($body, '-_', '+/'), true), true, 32, JSON_THROW_ON_ERROR);
    $headerFields = json_decode(base64_decode(strtr($header, '-_', '+/'), true), true, 32, JSON_THROW_ON_ERROR);
    check($headerFields['typ'] === 'logout+jwt' && $claims['aud'] === 'client' && $claims['sid'] === '60' && $claims['jti'] === $delivery['event_id'], 'Logout JWT binding incorrect');
    check(isset($claims['events']['http://schemas.openid.net/event/backchannel-logout']) && !isset($claims['nonce']), 'Logout JWT event invalid');
    check(openssl_verify($header . '.' . $body, base64_decode(strtr($signature, '-_', '+/'), true), $public, OPENSSL_ALGO_SHA256) === 1, 'Logout JWT signature invalid');
    $rows = State::$rows;
    $result = $service->logout($logout, 'notification-replay');
    check(State::$rows === $rows && $result['frontchannel_uris'] === [], 'Repeated logout re-enqueued notification');
    check($service->logout(['id_token_hint' => $response['id_token']], 'no-session-no-redirect') === null, 'Repeated logout without redirect should have no body');
    echo "OAuth logout notification atomicity and replay non-PG behavior PASS\n";
}
