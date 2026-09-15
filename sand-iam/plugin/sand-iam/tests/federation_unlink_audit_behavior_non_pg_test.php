<?php
declare(strict_types=1);

namespace UnlinkTest {
    final class Store {
        public static array $rows = [];
        public static array $audits = [];
        public static ?array $before = null;
    }
    class Row {
        public function __construct(private array $data) {}
        public function __get(string $key): mixed { return $this->data[$key] ?? null; }
        public function __isset(string $key): bool { return isset($this->data[$key]); }
        public static function where(string $key, mixed $value): Query { return (new Query(static::class))->where($key, $value); }
        public static function whereIn(string $key, array $values): Query { return (new Query(static::class))->whereIn($key, $values); }
        public static function create(array $values): static {
            $values['id'] = max([0, ...array_keys(Store::$rows[static::class] ?? [])]) + 1;
            Store::$rows[static::class][$values['id']] = $values;
            return new static($values);
        }
        public function save(array $values): void { Store::$rows[static::class][$this->id] = array_replace($this->data, $values); }
    }
    final class Query {
        private array $filters = [];
        public function __construct(private string $model) {}
        public function where(string $key, mixed $operator, mixed $value = null): self {
            $this->filters[] = func_num_args() === 2 ? [$key, '=', $operator] : [$key, $operator, $value];
            return $this;
        }
        public function whereIn(string $key, array $values): self { $this->filters[] = [$key, 'in', $values]; return $this; }
        public function lock(bool $lock): self {
            if (!$lock || Store::$before === null) throw new \RuntimeException('Lock outside transaction');
            return $this;
        }
        private function rows(): array {
            return array_filter(Store::$rows[$this->model] ?? [], function (array $row): bool {
                foreach ($this->filters as [$key, $operator, $value]) {
                    $actual = $row[$key] ?? null;
                    if (!match ($operator) { '=' => $actual === $value, '<>' => $actual !== $value, 'in' => in_array($actual, $value, true), default => throw new \RuntimeException('Unsupported predicate') }) return false;
                }
                return true;
            });
        }
        public function select(): array { return array_map(fn (array $row): object => new ($this->model)($row), array_values($this->rows())); }
        public function find(): ?object { return $this->select()[0] ?? null; }
        public function column(string $key): array { return array_column($this->rows(), $key); }
        public function update(array $values): void { foreach ($this->rows() as $id => $row) Store::$rows[$this->model][$id] = array_replace($row, $values); }
    }
    function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    class Application extends \UnlinkTest\Row {}
    class Organization extends \UnlinkTest\Row {}
    class Identity extends \UnlinkTest\Row {}
    class IdentityBinding extends \UnlinkTest\Row {}
    class IdentityProvider extends \UnlinkTest\Row {}
    class IdentityProviderApplication extends \UnlinkTest\Row {}
    class AuthSession extends \UnlinkTest\Row {}
    class AuthRefreshToken extends \UnlinkTest\Row {}
    class IdentityAuth extends \UnlinkTest\Row {}
    class FederationTransaction extends \UnlinkTest\Row {}
    class FederationHandoff extends \UnlinkTest\Row {}
}
namespace plugin\SandIam\app\federation {
    interface FederationHttpAdapter {}
    interface LdapDirectoryAdapter {}
    interface SamlAssertionVerifier {}
    class NativeFederationHttpAdapter implements FederationHttpAdapter {
        public static array $calls = [];
        public static array $responses = [];
        public function json(string $method, string $url, array $headers = [], array $body = []): array {
            \UnlinkTest\check(\UnlinkTest\Store::$before === null, 'HTTP held a database transaction');
            self::$calls[] = [$method, $url, $headers, $body];
            if (isset(self::$responses[$url])) return self::$responses[$url];
            return $method === 'POST' ? ['access_token' => 'external-token'] : ['sub' => 'external-subject'];
        }
    }
    class NativeLdapDirectoryAdapter implements LdapDirectoryAdapter {}
    class OneLoginSamlAssertionVerifier implements SamlAssertionVerifier {
        public static int $calls = 0;
        public function verify(string $response, array $config, string $requestId, string $uri): array {
            \UnlinkTest\check(\UnlinkTest\Store::$before === null, 'SAML verification held transaction');
            self::$calls++;
            return ['sub' => 'external-subject', 'assertion_id' => 'test-assertion'];
        }
    }
}
namespace plugin\SandIam\app\service {
    class FederationConfigCipher { public function decrypt(array $value): array { return $value; } }
    class HumanAuthService {
        public function authenticatedSession(string $token): \plugin\SandIam\app\model\AuthSession { return new \plugin\SandIam\app\model\AuthSession(\UnlinkTest\Store::$rows[\plugin\SandIam\app\model\AuthSession::class][50]); }
    }
    class MfaService { public function hasUsablePasskey(object $application, int $identity): bool { return false; } }
    class AuditWriter {
        public static bool $failSuccess = false;
        public static bool $failFailure = false;
        public static ?string $failAction = null;
        public function write(mixed ...$args): void {
            if ($args[7] === 'failed' && self::$failFailure) throw new \RuntimeException('failure audit unavailable');
            \UnlinkTest\Store::$audits[] = $args;
            if ($args[7] === 'succeeded' && self::$failSuccess && (self::$failAction === null || self::$failAction === $args[4])) throw new \RuntimeException('success audit unavailable');
        }
    }
}
namespace think\facade {
    class Db {
        public static function startTrans(): void {
            if (\UnlinkTest\Store::$before !== null) throw new \RuntimeException('Nested transaction');
            \UnlinkTest\Store::$before = [\UnlinkTest\Store::$rows, \UnlinkTest\Store::$audits];
        }
        public static function commit(): void { \UnlinkTest\Store::$before = null; }
        public static function rollback(): void {
            if (\UnlinkTest\Store::$before !== null) [\UnlinkTest\Store::$rows, \UnlinkTest\Store::$audits] = \UnlinkTest\Store::$before;
            \UnlinkTest\Store::$before = null;
        }
    }
}
namespace {
    use UnlinkTest\Store;
    use function UnlinkTest\check;
    use plugin\SandIam\app\model\{Application, Organization, Identity, IdentityBinding, IdentityProvider, IdentityProviderApplication, AuthSession, AuthRefreshToken, IdentityAuth};
    use plugin\SandIam\app\service\{FederationService, AuditWriter};
    function config(string $key, mixed $default = null): mixed { return $key === 'plugin.sand-iam.app.auth_pepper' ? 'test-pepper' : $default; }
    require dirname(__DIR__) . '/app/service/FederationService.php';
    $session = ['id' => 50, 'application_id' => 10, 'identity_id' => 20, 'identity_binding_id' => 30, 'status' => 1, 'revoked_time' => null, 'pepper_version' => 'v1', 'access_expire_time' => date('Y-m-d H:i:s', time() + 600), 'step_up_time' => date('Y-m-d H:i:s')];
    Store::$rows = [
        Application::class => [10 => ['id' => 10, 'organization_id' => 1, 'status' => 1]],
        Organization::class => [1 => ['id' => 1, 'status' => 1]],
        Identity::class => [20 => ['id' => 20, 'application_id' => 10, 'status' => 1]],
        IdentityBinding::class => [30 => ['id' => 30, 'application_id' => 10, 'identity_id' => 20, 'identity_provider_id' => 40, 'status' => 1, 'source_state' => 'active']],
        IdentityProvider::class => [40 => ['id' => 40, 'organization_id' => 1, 'status' => 1]],
        IdentityProviderApplication::class => [1 => ['id' => 1, 'identity_provider_id' => 40, 'application_id' => 10, 'organization_id' => 1, 'status' => 1]],
        AuthSession::class => [50 => $session, 51 => array_replace($session, ['id' => 51, 'identity_binding_id' => 99])],
        AuthRefreshToken::class => [60 => ['id' => 60, 'session_id' => 50, 'status' => 1], 61 => ['id' => 61, 'session_id' => 51, 'status' => 1]],
        IdentityAuth::class => [1 => ['id' => 1, 'application_id' => 10, 'identity_id' => 20, 'status' => 1, 'pepper_version' => 'v1']],
    ];
    $service = new FederationService();
    $baseline = Store::$rows;
    AuditWriter::$failSuccess = true;
    try { $service->unlink('session', 30, 'unlink-audit-fault'); throw new \RuntimeException('Audit fault ignored'); }
    catch (\RuntimeException $error) { check($error->getMessage() === 'success audit unavailable', $error->getMessage()); }
    check(Store::$rows === $baseline && Store::$before === null, 'Audit failure left binding/session/refresh revoked');
    check(count(Store::$audits) === 1 && Store::$audits[0][7] === 'failed' && Store::$audits[0][9]['reason'] === 'audit_failed', 'Failed audit missing or mislabeled');
    AuditWriter::$failFailure = true;
    Store::$audits = [];
    try { $service->unlink('session', 30, 'unlink-both-fault'); throw new \RuntimeException('Double audit fault ignored'); }
    catch (\RuntimeException $error) { check($error->getMessage() === 'failure audit unavailable', $error->getMessage()); }
    check(Store::$rows === $baseline && Store::$before === null && Store::$audits === [], 'Double audit failure left state');
    AuditWriter::$failSuccess = AuditWriter::$failFailure = false;
    Store::$rows[IdentityAuth::class] = [];
    $withoutPassword = Store::$rows;
    try { $service->unlink('session', 30, 'unlink-last-method'); throw new \RuntimeException('Last method removed'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 409, 'Wrong last-method error'); }
    check(Store::$rows === $withoutPassword && end(Store::$audits)[7] === 'failed', 'Last-method rejection changed state');
    Store::$rows = $baseline;
    Store::$audits = [];
    $service->unlink('session', 30, 'unlink-audit-fault');
    check(Store::$rows[IdentityBinding::class][30]['status'] === 2 && Store::$rows[AuthSession::class][50]['status'] === 2 && Store::$rows[AuthRefreshToken::class][60]['status'] === 2, 'Retry did not revoke target');
    check(Store::$rows[AuthSession::class][51] === $baseline[AuthSession::class][51] && Store::$rows[AuthRefreshToken::class][61] === $baseline[AuthRefreshToken::class][61], 'Unrelated session was revoked');
    check(count(Store::$audits) === 1 && Store::$audits[0][7] === 'succeeded' && Store::$before === null, 'Success audit or transaction incorrect');
    echo "federation unlink audit non-PG behavior PASS\n";

    Store::$rows = $baseline;
    Store::$audits = [];
    Store::$rows[IdentityBinding::class] = [];
    Store::$rows[IdentityProvider::class][40] += ['provider_type' => 'oauth2', 'config_version' => 1, 'attribute_mapping' => ['subject' => 'sub'], 'encrypted_config' => ['token_endpoint' => 'https://idp.example/token', 'userinfo_endpoint' => 'https://idp.example/userinfo', 'client_id' => 'client']];
    $state = 'fo_' . str_repeat('a', 48);
    Store::$rows[\plugin\SandIam\app\model\FederationTransaction::class][1] = [
        'id' => 1, 'state_hash' => hash_hmac('sha256', 'oauth2-state:' . $state, 'test-pepper'), 'protocol' => 'oauth2',
        'status' => 1, 'consumed_time' => null, 'expire_time' => date('Y-m-d H:i:s', time() + 600),
        'browser_binding_hash' => hash_hmac('sha256', 'browser:browser', 'test-pepper'),
        'identity_provider_id' => 40, 'application_id' => 10, 'provider_config_version' => 1,
        'encrypted_pkce_verifier' => ['verifier' => 'verifier'], 'redirect_uri' => 'https://iam.example/callback',
        'purpose' => 'link', 'link_identity_id' => 20, 'link_session_id' => 50,
        'handoff_return_uri' => 'https://app.example/return', 'handoff_state' => 'consumer-state', 'handoff_code_challenge' => str_repeat('a', 43),
    ];
    Store::$rows[\plugin\SandIam\app\model\FederationHandoff::class] = [];
    $callbackBaseline = Store::$rows;
    AuditWriter::$failSuccess = true;
    AuditWriter::$failAction = 'identity_provider.oauth2_callback';
    try { $service->completeOauth2($state, 'external-code', 'browser', '', '', 'callback-fault'); throw new \RuntimeException('Callback audit fault ignored'); }
    catch (\RuntimeException $error) { check($error->getMessage() === 'success audit unavailable', $error->getMessage()); }
    check(Store::$rows === $callbackBaseline && Store::$before === null, 'Callback audit failure left binding/state/handoff');
    check(count(Store::$audits) === 1 && Store::$audits[0][4] === 'identity_provider.oauth2_callback' && Store::$audits[0][7] === 'failed', 'Callback failure audit missing or success audit survived');
    AuditWriter::$failSuccess = false;
    $result = $service->completeOauth2($state, 'external-code-retry', 'browser', '', '', 'callback-retry');
    check(count(Store::$rows[IdentityBinding::class]) === 1 && count(Store::$rows[\plugin\SandIam\app\model\FederationHandoff::class]) === 1, 'Callback retry did not commit one binding and handoff');
    check(Store::$rows[\plugin\SandIam\app\model\FederationTransaction::class][1]['status'] === 2 && $result['return_uri'] === 'https://app.example/return' && str_starts_with($result['code'], 'fh_'), 'Callback result or state consumption invalid');
    $callCount = count(\plugin\SandIam\app\federation\NativeFederationHttpAdapter::$calls);
    try { $service->completeOauth2($state, 'replay', 'browser', '', '', 'callback-replay'); throw new \RuntimeException('Consumed callback accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check(str_contains($error->getMessage(), 'SAND_IAM_FEDERATION_STATE_INVALID'), 'Wrong replay error'); }
    check(count(\plugin\SandIam\app\federation\NativeFederationHttpAdapter::$calls) === $callCount, 'Consumed callback performed HTTP');
    echo "federation OAuth2 callback audit non-PG behavior PASS\n";

    // Ephemeral test signing material stays in memory; no host/provider keys are read or changed.
    $testKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    check($testKey !== false, 'Cannot create in-memory test signer');
    $details = openssl_pkey_get_details($testKey);
    check(is_array($details) && isset($details['rsa']), 'Cannot inspect test public key');
    $b64 = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $signed = $b64(json_encode(['alg' => 'RS256', 'kid' => 'fixture'], JSON_THROW_ON_ERROR)) . '.' . $b64(json_encode(['iss' => 'https://idp.example', 'sub' => 'external-subject', 'aud' => 'client', 'exp' => time() + 600, 'iat' => time(), 'nonce' => 'nonce'], JSON_THROW_ON_ERROR));
    check(openssl_sign($signed, $signature, $testKey, OPENSSL_ALGO_SHA256), 'Cannot sign test token');
    \plugin\SandIam\app\federation\NativeFederationHttpAdapter::$responses = [
        'https://idp.example/token' => ['id_token' => $signed . '.' . $b64($signature)],
        'https://idp.example/jwks' => ['keys' => [['kty' => 'RSA', 'kid' => 'fixture', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]]],
    ];
    foreach (['oidc' => ['fi_', 'state:'], 'saml' => ['fs_', 'saml-relay:']] as $protocol => [$prefix, $hashPrefix]) {
        Store::$rows = $callbackBaseline;
        Store::$audits = [];
        $state = $prefix . str_repeat('b', 48);
        Store::$rows[IdentityProvider::class][40]['provider_type'] = $protocol;
        Store::$rows[IdentityProvider::class][40]['encrypted_config'] += ['issuer' => 'https://idp.example', 'authorization_endpoint' => 'https://idp.example/authorize', 'jwks_uri' => 'https://idp.example/jwks'];
        Store::$rows[\plugin\SandIam\app\model\FederationTransaction::class][1] = array_replace(Store::$rows[\plugin\SandIam\app\model\FederationTransaction::class][1], [
            'state_hash' => hash_hmac('sha256', $hashPrefix . $state, 'test-pepper'), 'protocol' => $protocol,
            'nonce_hash' => hash_hmac('sha256', 'nonce:nonce', 'test-pepper'), 'saml_request_id' => 'request-1', 'assertion_hash' => null,
        ]);
        $before = Store::$rows;
        $callback = static fn (): array => $protocol === 'oidc'
            ? $service->completeOidc($state, 'test-code', 'browser', '', '', 'callback-test')
            : $service->completeSaml($state, 'test-assertion-envelope', 'browser', '', '', 'callback-test');
        AuditWriter::$failSuccess = true;
        AuditWriter::$failAction = 'identity_provider.' . $protocol . '_callback';
        try { $callback(); throw new \RuntimeException('Callback audit fault ignored'); }
        catch (\RuntimeException $error) { check($error->getMessage() === 'success audit unavailable', $error->getMessage()); }
        check(Store::$rows === $before && Store::$before === null, $protocol . ' audit failure left callback state');
        check(count(Store::$audits) === 1 && Store::$audits[0][7] === 'failed', $protocol . ' callback success audit survived rollback');
        AuditWriter::$failSuccess = false;
        $result = $callback();
        check(str_starts_with($result['code'], 'fh_') && count(Store::$rows[IdentityBinding::class]) === 1 && count(Store::$rows[\plugin\SandIam\app\model\FederationHandoff::class]) === 1, $protocol . ' local recovery failed');
        check(Store::$rows[\plugin\SandIam\app\model\FederationTransaction::class][1]['status'] === 2 && Store::$before === null, $protocol . ' state not consumed');
        $callsBeforeReplay = [count(\plugin\SandIam\app\federation\NativeFederationHttpAdapter::$calls), \plugin\SandIam\app\federation\OneLoginSamlAssertionVerifier::$calls];
        try { $callback(); throw new \RuntimeException('Consumed callback accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check(str_contains($error->getMessage(), 'SAND_IAM_FEDERATION_STATE_INVALID'), 'Wrong consumed-state error'); }
        check($callsBeforeReplay === [count(\plugin\SandIam\app\federation\NativeFederationHttpAdapter::$calls), \plugin\SandIam\app\federation\OneLoginSamlAssertionVerifier::$calls], 'Consumed callback repeated external verification');
    }
    echo "federation OIDC/SAML callback audit non-PG behavior PASS\n";
}
