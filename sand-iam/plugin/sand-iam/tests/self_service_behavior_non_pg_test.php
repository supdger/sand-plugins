<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace think\facade {
    final class Db
    {
        public static int $starts = 0;
        public static int $commits = 0;
        public static int $rollbacks = 0;
        public static ?\Closure $beforeStart = null;
        public static ?\Closure $afterCommit = null;
        public static function startTrans(): void {
            if (self::$beforeStart !== null) { $callback = self::$beforeStart; self::$beforeStart = null; $callback(); }
            self::$starts++;
        }
        public static function commit(): void {
            self::$commits++;
            if (self::$afterCommit !== null) { $callback = self::$afterCommit; self::$afterCommit = null; $callback(); }
        }
        public static function rollback(): void { self::$rollbacks++; }
    }
}

namespace plugin\SandIam\app\model {
    final class Query
    {
        /** @var list<array{field:string,operator:string,value:mixed}> */
        private array $conditions = [];

        /** @param list<object> $rows */
        public function __construct(private array $rows, string $field, mixed $value)
        {
            $this->conditions[] = ['field' => $field, 'operator' => '=', 'value' => $value];
        }

        public function where(string $field, mixed $operatorOrValue, mixed $value = null): self
        {
            if (func_num_args() === 2) {
                $this->conditions[] = ['field' => $field, 'operator' => '=', 'value' => $operatorOrValue];
            } else {
                $this->conditions[] = ['field' => $field, 'operator' => (string) $operatorOrValue, 'value' => $value];
            }
            return $this;
        }

        public function whereNull(string $field): self
        {
            $this->conditions[] = ['field' => $field, 'operator' => 'null', 'value' => null];
            return $this;
        }

        public function lock(bool $forUpdate): self { return $this; }
        public function order(string $field): self { return $this; }

        public function find(): ?object
        {
            foreach ($this->rows as $row) if ($this->matches($row)) return $row;
            return null;
        }

        public function count(): int
        {
            return count(array_filter($this->rows, fn (object $row): bool => $this->matches($row)));
        }

        public function select(): self { return $this; }

        /** @return list<object> */
        public function all(): array
        {
            return array_values(array_filter($this->rows, fn (object $row): bool => $this->matches($row)));
        }

        private function matches(object $row): bool
        {
            foreach ($this->conditions as $condition) {
                $actual = $row->{$condition['field']} ?? null;
                $expected = $condition['value'];
                if ($condition['operator'] === 'null' && $actual !== null) return false;
                if ($condition['operator'] === '=' && $actual !== $expected) return false;
                if ($condition['operator'] === '>' && !($actual > $expected)) return false;
            }
            return true;
        }
    }

    #[\AllowDynamicProperties]
    class Record
    {
        /** @param array<string,mixed> $values */
        public function __construct(array $values) { foreach ($values as $field => $value) $this->{$field} = $value; }
        /** @param array<string,mixed> $values */
        public function save(array $values): void { foreach ($values as $field => $value) $this->{$field} = $value; }
    }

    final class Application extends Record
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
    final class Organization extends Record
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
    final class Identity extends Record
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
    final class IdentityBinding extends Record
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
    final class IdentityProvider extends Record
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
    final class IdentityProviderApplication extends Record
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
    final class IdentityAuth extends Record
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
    final class AuthSession extends Record
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
    final class MfaFactor extends Record
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
    final class WebauthnCredential extends Record
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
}

namespace plugin\SandIam\app\service {
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Identity;
    use plugin\sandadmin\exception\ApiException;

    final class HumanAuthService
    {
        public static bool $tokenValid = true;
        /** @var array<string,array{0:Application,1:Identity}> */ public static array $principals = [];
        /** @return array{0:Application,1:Identity} */
        public function authenticatedPrincipal(string $accessToken): array
        {
            $this->authenticatedSession($accessToken);
            return self::$principals[$accessToken] ?? throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        public function authenticatedSession(string $accessToken): \plugin\SandIam\app\model\AuthSession
        {
            $id = $accessToken === 'access-a' ? 1 : ($accessToken === 'access-b' ? 4 : 0);
            $session = \plugin\SandIam\app\model\AuthSession::where('id', $id)->where('status', 1)->find();
            if (!self::$tokenValid || $session === null || $session->revoked_time !== null
                || ($session->access_expire_time ?? '2099-01-01 00:00:00') <= date('Y-m-d H:i:s')) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
            return $session;
        }
    }

    final class IdentityEventPublisher
    {
        /** @var list<array{application_id:int,identity_id:int,event_type:string,fields:list<string>,request_id:string}> */ public static array $events = [];
        /** @param list<string> $changedFields */
        public function publish(Application $application, Identity $identity, string $eventType, array $changedFields, string $requestId): ?string
        {
            self::$events[] = [
                'application_id' => (int) $application->id,
                'identity_id' => (int) $identity->id,
                'event_type' => $eventType,
                'fields' => $changedFields,
                'request_id' => $requestId,
            ];
            return 'evt_fixture_001';
        }
    }

    final class AuditWriter
    {
        /** @var list<array{actor_type:string,actor_ref:string,organization_id:?int,application_id:?int,action:string,resource_type:string,resource_id:?int,outcome:string,request_id:string,detail:array<string,mixed>}> */ public static array $writes = [];
        /** @param array<string,mixed> $detail */
        public function write(string $actorType, string $actorRef, ?int $organizationId, ?int $applicationId, string $action, string $resourceType, ?int $resourceId, string $outcome, string $requestId, array $detail = []): void
        {
            self::$writes[] = compact('actorType', 'actorRef', 'organizationId', 'applicationId', 'action', 'resourceType', 'resourceId', 'outcome', 'requestId', 'detail');
        }
    }
}

namespace {
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\AuthSession;
    use plugin\SandIam\app\model\Identity;
    use plugin\SandIam\app\model\IdentityAuth;
    use plugin\SandIam\app\model\IdentityBinding;
    use plugin\SandIam\app\model\IdentityProvider;
    use plugin\SandIam\app\model\IdentityProviderApplication;
    use plugin\SandIam\app\model\MfaFactor;
    use plugin\SandIam\app\model\Organization;
    use plugin\SandIam\app\model\WebauthnCredential;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\service\HumanAuthService;
    use plugin\SandIam\app\service\IdentityEventPublisher;
    use plugin\SandIam\app\service\SelfServiceService;
    use think\facade\Db;

    function selfServiceAssert(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }

    require dirname(__DIR__) . '/app/service/SelfServiceService.php';

    $appA = new Application(['id' => 11, 'organization_id' => 101, 'status' => 1, 'code' => 'app-a', 'name' => 'Application A']);
    $appB = new Application(['id' => 22, 'organization_id' => 202, 'status' => 1, 'code' => 'app-b', 'name' => 'Application B']);
    $identityA = new Identity(['id' => 1001, 'application_id' => 11, 'status' => 1, 'code' => 'ida', 'display_name' => 'Alice', 'create_time' => '2026-09-08 09:00:00']);
    $identityB = new Identity(['id' => 2002, 'application_id' => 22, 'status' => 1, 'code' => 'idb', 'display_name' => 'Bob', 'create_time' => '2026-09-08 10:00:00']);
    Application::$rows = [$appA, $appB];
    Organization::$rows = [new Organization(['id' => 101, 'status' => 1, 'code' => 'org-a', 'name' => 'Organization A']), new Organization(['id' => 202, 'status' => 1, 'code' => 'org-b', 'name' => 'Organization B'])];
    Identity::$rows = [$identityA, $identityB];
    HumanAuthService::$principals = ['access-a' => [$appA, $identityA], 'access-b' => [$appB, $identityB]];

    IdentityBinding::$rows = [
        new IdentityBinding(['id' => 1, 'identity_id' => 1001, 'application_id' => 11, 'identity_provider_id' => 301, 'subject' => 'alice@example.test', 'source_state' => 'active', 'status' => 1, 'create_time' => '2026-09-08 09:00:00']),
        new IdentityBinding(['id' => 2, 'identity_id' => 1001, 'application_id' => 22, 'identity_provider_id' => 301, 'subject' => 'cross-app@example.test', 'status' => 1, 'create_time' => '2026-09-08 09:01:00']),
        new IdentityBinding(['id' => 3, 'identity_id' => 1001, 'application_id' => 11, 'identity_provider_id' => 302, 'subject' => 'wrong-org@example.test', 'status' => 1, 'create_time' => '2026-09-08 09:02:00']),
    ];
    IdentityProvider::$rows = [
        new IdentityProvider(['id' => 301, 'organization_id' => 101, 'status' => 1, 'name' => 'Directory A', 'provider_type' => 'ldap']),
        new IdentityProvider(['id' => 302, 'organization_id' => 202, 'status' => 1, 'name' => 'Directory B', 'provider_type' => 'oidc']),
    ];
    IdentityProviderApplication::$rows = [
        new IdentityProviderApplication(['id' => 1, 'identity_provider_id' => 301, 'application_id' => 11, 'organization_id' => 101, 'status' => 1]),
        new IdentityProviderApplication(['id' => 2, 'identity_provider_id' => 302, 'application_id' => 11, 'organization_id' => 101, 'status' => 1]),
    ];
    IdentityAuth::$rows = [new IdentityAuth(['id' => 1, 'application_id' => 11, 'identity_id' => 1001, 'status' => 1]), new IdentityAuth(['id' => 2, 'application_id' => 22, 'identity_id' => 2002, 'status' => 1])];
    AuthSession::$rows = [
        new AuthSession(['id' => 1, 'application_id' => 11, 'identity_id' => 1001, 'status' => 1, 'revoked_time' => null, 'refresh_expire_time' => '2099-01-01 00:00:00']),
        new AuthSession(['id' => 2, 'application_id' => 11, 'identity_id' => 1001, 'status' => 1, 'revoked_time' => '2026-09-08 09:00:00', 'refresh_expire_time' => '2099-01-01 00:00:00']),
        new AuthSession(['id' => 3, 'application_id' => 11, 'identity_id' => 1001, 'status' => 1, 'revoked_time' => null, 'refresh_expire_time' => '2000-01-01 00:00:00']),
        new AuthSession(['id' => 4, 'application_id' => 22, 'identity_id' => 2002, 'status' => 1, 'revoked_time' => null, 'refresh_expire_time' => '2099-01-01 00:00:00']),
    ];
    MfaFactor::$rows = [new MfaFactor(['id' => 1, 'application_id' => 11, 'identity_id' => 1001, 'status' => 1]), new MfaFactor(['id' => 2, 'application_id' => 11, 'identity_id' => 1001, 'status' => 2]), new MfaFactor(['id' => 3, 'application_id' => 22, 'identity_id' => 2002, 'status' => 1])];
    WebauthnCredential::$rows = [new WebauthnCredential(['id' => 1, 'application_id' => 11, 'identity_id' => 1001, 'status' => 1]), new WebauthnCredential(['id' => 2, 'application_id' => 11, 'identity_id' => 1001, 'status' => 2]), new WebauthnCredential(['id' => 3, 'application_id' => 22, 'identity_id' => 2002, 'status' => 1])];

    $service = new SelfServiceService(new AuditWriter());
    $profileA = $service->profile('access-a');
    selfServiceAssert($profileA === ['identity_id' => 1001, 'display_name' => 'Alice', 'organization' => ['code' => 'org-a', 'name' => 'Organization A'], 'application' => ['code' => 'app-a', 'name' => 'Application A'], 'create_time' => '2026-09-08 09:00:00'], 'profile must return only the authenticated identity and its application/organization context');
    selfServiceAssert($service->profile('access-b')['identity_id'] === 2002, 'a second application token must not read the first identity profile');

    $connections = $service->connections('access-a');
    selfServiceAssert($connections === [['binding_id' => 1, 'provider_name' => 'Directory A', 'provider_type' => 'ldap', 'account_hint' => 'al***@example.test', 'source_state' => 'active', 'linked_time' => '2026-09-08 09:00:00']], 'connections must reject cross-application bindings and providers outside the application organization');
    selfServiceAssert($service->securityOverview('access-a') === ['password_enabled' => true, 'active_sessions' => 1, 'totp_factors' => 1, 'passkeys' => 1, 'connected_accounts' => 1], 'security overview must exclude revoked/expired sessions and MFA/passkeys outside the authenticated application and identity');

    $updated = $service->updateProfile('access-a', '  Alice   Updated  ', 'self-service-profile-update-001');
    selfServiceAssert($updated['display_name'] === 'Alice Updated' && $identityA->display_name === 'Alice Updated', 'public profile update must normalize and persist the authenticated identity display name');
    selfServiceAssert(Db::$starts === 1 && Db::$commits === 1 && Db::$rollbacks === 0, 'profile update must complete one transaction without a rollback');
    selfServiceAssert(IdentityEventPublisher::$events === [['application_id' => 11, 'identity_id' => 1001, 'event_type' => 'identity.updated', 'fields' => ['display_name'], 'request_id' => 'self-service-profile-update-001']], 'profile update must publish the scoped identity.updated event');
    selfServiceAssert(AuditWriter::$writes === [['actorType' => 'identity', 'actorRef' => '1001', 'organizationId' => 101, 'applicationId' => 11, 'action' => 'identity.profile_update', 'resourceType' => 'identity', 'resourceId' => 1001, 'outcome' => 'succeeded', 'requestId' => 'self-service-profile-update-001', 'detail' => ['fields' => ['display_name']]]], 'profile update must write the matching scoped audit record');

    $before = serialize([$identityA, IdentityEventPublisher::$events, AuditWriter::$writes]);
    Db::$beforeStart = static function (): void { AuthSession::$rows[0]->status = 2; AuthSession::$rows[0]->revoked_time = '2026-09-14 12:00:00'; };
    try {
        $service->updateProfile('access-a', 'Revoked update', 'self-service-revoked-update');
        throw new \RuntimeException('revoked session changed profile');
    } catch (\plugin\sandadmin\exception\ApiException $exception) {
        selfServiceAssert($exception->getCode() === 401, 'revoked profile update must return 401');
    }
    selfServiceAssert(serialize([$identityA, IdentityEventPublisher::$events, AuditWriter::$writes]) === $before, 'revoked profile update changed identity, event or audit');
    AuthSession::$rows[0]->status = 1;
    AuthSession::$rows[0]->revoked_time = null;
    foreach (['rotation', 'expiry'] as $failure) {
        Db::$beforeStart = static function () use ($failure): void {
            if ($failure === 'rotation') HumanAuthService::$tokenValid = false;
            else AuthSession::$rows[0]->access_expire_time = '2000-01-01 00:00:00';
        };
        try {
            $service->updateProfile('access-a', 'Invalid token update', 'self-service-token-change');
            throw new \RuntimeException('invalidated token changed profile');
        } catch (\plugin\sandadmin\exception\ApiException $exception) {
            selfServiceAssert($exception->getCode() === 401, 'invalidated token must return 401');
        }
        selfServiceAssert(serialize([$identityA, IdentityEventPublisher::$events, AuditWriter::$writes]) === $before, 'invalidated token changed identity, event or audit');
        HumanAuthService::$tokenValid = true;
        AuthSession::$rows[0]->access_expire_time = '2099-01-01 00:00:00';
    }
    Db::$afterCommit = static function (): void { AuthSession::$rows[0]->status = 2; };
    $committed = $service->updateProfile('access-a', 'Committed name', 'self-service-commit-result');
    selfServiceAssert($committed['display_name'] === 'Committed name' && $identityA->display_name === 'Committed name', 'committed update must return its success snapshot without post-commit authentication');
    AuthSession::$rows[0]->status = 1;
    foreach (["\u{3000}", "\u{00A0}\u{2003}", "\u{200B}\u{3000}\t"] as $blankName) {
        $before = serialize([$identityA, IdentityEventPublisher::$events, AuditWriter::$writes]);
        try {
            $service->updateProfile('access-a', $blankName, 'unicode-blank-profile');
            throw new \RuntimeException('Unicode blank profile name accepted');
        } catch (\plugin\sandadmin\exception\ApiException $exception) {
            selfServiceAssert($exception->getCode() === 400, 'Unicode blank profile must return 400');
        }
        selfServiceAssert(serialize([$identityA, IdentityEventPublisher::$events, AuditWriter::$writes]) === $before, 'Unicode blank profile changed identity or audit');
    }
    $unicodeName = $service->updateProfile('access-a', "\u{3000}张\u{00A0}\u{2003}三\u{3000}", 'unicode-profile-name');
    selfServiceAssert($unicodeName['display_name'] === '张 三', 'Unicode whitespace must normalize and trim around visible text');
    echo "self service behavior non-pg test passed\n";
}
