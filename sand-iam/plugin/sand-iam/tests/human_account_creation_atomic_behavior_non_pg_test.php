<?php
declare(strict_types=1);

namespace AccountCreationAtomicTest {
    final class State
    {
        public static bool $failSuccessAudit = false;
        public static array $audits = [];
        public static array $events = [];
    }
    #[\AllowDynamicProperties]
    class Record
    {
        public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; }
        public function __get(string $key): mixed { return null; }
        public function save(array $values): void
        {
            foreach ($values as $key => $value) $this->$key = $value;
            static::$rows[$this->id] = $this;
        }
        public static function create(array $values): static
        {
            $values['id'] ??= max([0, ...array_keys(static::$rows)]) + 1;
            return static::$rows[$values['id']] = new static($values);
        }
        public static function where(string $field, mixed $value): Query
        { return (new Query(static::class))->where($field, $value); }
        public static function alias(string $alias): Query { return (new Query(static::class))->alias($alias); }
    }
    final class Query
    {
        private bool $joinOrganization = false;
        private array $filters = [];
        public function __construct(private readonly string $model) {}
        public function alias(string $alias): self { return $this; }
        public function join(string $table, string $condition): self
        {
            $this->joinOrganization = $table === 'sand_iam_organization organization'
                && $condition === 'organization.id = application.organization_id';
            return $this;
        }
        public function field(string $fields): self { return $this; }
        public function where(string $field, mixed $value): self
        {
            $this->filters[] = fn (Record $row): bool => $this->value($row, $field) === $value;
            return $this;
        }
        public function lock(bool $lock): self
        {
            if (!$lock || !\think\facade\Db::$active) throw new \RuntimeException('lock outside transaction');
            return $this;
        }
        public function find(): ?Record
        {
            foreach ($this->model::$rows as $row) {
                if ($this->joinOrganization && $this->organization($row) === null) continue;
                foreach ($this->filters as $filter) if (!$filter($row)) continue 2;
                return $row;
            }
            return null;
        }
        private function value(Record $row, string $field): mixed
        {
            [$prefix, $name] = array_pad(explode('.', $field, 2), 2, '');
            if ($name === '') return $row->$prefix;
            return $prefix === 'organization' ? $this->organization($row)?->$name : $row->$name;
        }
        private function organization(Record $row): ?Record
        {
            $class = \plugin\SandIam\app\model\Organization::class;
            return $class::$rows[$row->organization_id ?? 0] ?? null;
        }
    }
    function check(bool $condition, string $message): void
    { if (!$condition) throw new \RuntimeException($message); }
}

namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }

namespace plugin\SandIam\app\model {
    class Application extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class ApplicationExperience extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class ApplicationNetworkPolicy extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class AuthChallenge extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class AuthPolicy extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class AuthRateLimit extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class AuthRefreshToken extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class AuthSession extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class AuthVerification extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class Identity extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class IdentityAuth extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class IdentityBinding extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class IdentityProvider extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class IdentityProviderApplication extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
    class Organization extends \AccountCreationAtomicTest\Record { public static array $rows = []; }
}

namespace think\facade {
    final class Db
    {
        public static bool $active = false;
        private static string $snapshot = '';
        private const MODELS = [
            \plugin\SandIam\app\model\Identity::class,
            \plugin\SandIam\app\model\IdentityAuth::class,
            \plugin\SandIam\app\model\AuthRateLimit::class,
        ];
        public static function startTrans(): void
        {
            if (self::$active) throw new \RuntimeException('nested transaction');
            $rows = [];
            foreach (self::MODELS as $model) $rows[$model] = $model::$rows;
            self::$snapshot = serialize([$rows, \AccountCreationAtomicTest\State::$events, \AccountCreationAtomicTest\State::$audits]);
            self::$active = true;
        }
        public static function commit(): void { self::$active = false; self::$snapshot = ''; }
        public static function rollback(): void
        {
            [$rows, \AccountCreationAtomicTest\State::$events, \AccountCreationAtomicTest\State::$audits] = unserialize(self::$snapshot);
            foreach (self::MODELS as $model) $model::$rows = $rows[$model];
            self::$active = false;
            self::$snapshot = '';
        }
    }
}

namespace plugin\SandIam\app\service {
    class AuditWriter
    {
        public function write(mixed ...$values): void
        {
            \AccountCreationAtomicTest\State::$audits[] = $values;
            if (
                \AccountCreationAtomicTest\State::$failSuccessAudit
                && in_array($values[4] ?? '', ['identity.register', 'identity.invitation_activate'], true)
            ) throw new \RuntimeException('account success audit unavailable');
        }
    }
    class IdentityEventPublisher
    {
        public function publish(mixed ...$values): void
        { \AccountCreationAtomicTest\State::$events[] = $values; }
    }
}

namespace {
    use AccountCreationAtomicTest\State;
    use function AccountCreationAtomicTest\check;
    use plugin\SandIam\app\model\{
        Application, ApplicationExperience, ApplicationNetworkPolicy, AuthPolicy, AuthRateLimit,
        Identity, IdentityAuth, Organization
    };
    use plugin\SandIam\app\service\HumanAuthService;
    use plugin\sandadmin\exception\ApiException;

    const PEPPER = 'account-creation-offline-pepper';
    function config(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'plugin.sand-iam.app.auth_pepper' => PEPPER,
            'plugin.sand-iam.app.auth_pepper_version' => 'v1',
            'plugin.sand-iam.app.identity_lifecycle_enabled',
            'plugin.sand-iam.app.application_experience_enabled',
            'plugin.sand-iam.app.application_network_policy_enabled' => 1,
            default => $default,
        };
    }
    function verifies(string $password, string $hash): bool
    { return password_verify(hash_hmac('sha256', 'password:' . $password, PEPPER), $hash); }
    function creationState(): string
    { return serialize([Identity::$rows, IdentityAuth::$rows, State::$events, State::$audits]); }

    $app = dirname(__DIR__) . '/app/';
    require $app . 'radius/RadiusNetwork.php';
    require $app . 'security/NetworkPolicy.php';
    require $app . 'service/HumanAuthService.php';
    Organization::$rows = [20 => new Organization(['id' => 20, 'code' => 'acme', 'status' => 1])];
    Application::$rows = [10 => new Application(['id' => 10, 'organization_id' => 20, 'code' => 'portal', 'status' => 1])];
    ApplicationExperience::$rows = [1 => new ApplicationExperience([
        'id' => 1, 'application_id' => 10, 'login_methods' => ['password'],
        'registration_mode' => 'open', 'registration_fields' => ['username', 'display_name', 'email'], 'status' => 1,
    ])];
    ApplicationNetworkPolicy::$rows = [1 => new ApplicationNetworkPolicy([
        'id' => 1, 'application_id' => 10, 'allow_cidrs' => ['192.0.2.0/24'], 'deny_cidrs' => [], 'status' => 1,
    ])];
    $policy = [
        'id' => 1, 'application_id' => 10, 'registration_enabled' => 1,
        'password_min_length' => 12, 'password_max_length' => 128, 'require_uppercase' => 1,
        'require_lowercase' => 1, 'require_digit' => 1, 'require_symbol' => 1,
        'require_email_verification' => 1, 'require_phone_verification' => 0, 'require_captcha' => 0,
        'access_token_ttl_seconds' => 900, 'refresh_token_ttl_seconds' => 2592000,
        'verification_ttl_seconds' => 600, 'max_login_failures' => 5, 'lock_seconds' => 900,
        'rate_limit_per_minute' => 10, 'status' => 1,
    ];
    AuthPolicy::$rows = [1 => new AuthPolicy($policy)];
    AuthRateLimit::$rows = Identity::$rows = IdentityAuth::$rows = State::$events = State::$audits = [];
    $application = Application::$rows[10];
    $service = new HumanAuthService();
    $register = [
        'organization_code' => 'acme', 'application_code' => 'portal', 'username' => 'account-one',
        'display_name' => 'Account One', 'email' => 'one@example.test', 'password' => 'Register-pass-1!',
    ];

    $before = creationState();
    State::$failSuccessAudit = true;
    try {
        $service->register($register, '192.0.2.10', 'register-audit-failure');
        throw new \RuntimeException('register audit failure hidden');
    } catch (\RuntimeException $exception) {
        check($exception->getMessage() === 'account success audit unavailable', 'unexpected register failure');
    } finally { State::$failSuccessAudit = false; }
    check(!\think\facade\Db::$active, 'register leaked transaction');
    check(creationState() === $before, 'register audit failure did not roll back account, auth, event and audit');
    check(count(AuthRateLimit::$rows) === 1, 'transaction-external register rate admission was not retained');
    $registered = $service->register($register, '192.0.2.10', 'register-retry');
    check($registered['verification_required'] === true, 'register unexpectedly entered session or MFA flow');
    check(count(Identity::$rows) === 1 && count(IdentityAuth::$rows) === 1 && count(State::$events) === 1, 'register retry duplicated account or event');
    check(verifies('Register-pass-1!', IdentityAuth::$rows[1]->password_hash), 'register did not use the real password hash');
    AuthPolicy::$rows[1]->registration_enabled = 0;
    $counts = [count(Identity::$rows), count(IdentityAuth::$rows), count(State::$events), count(State::$audits)];
    try {
        $service->register(array_replace($register, ['username' => 'blocked', 'email' => 'blocked@example.test']), '192.0.2.10', 'register-disabled');
        throw new \RuntimeException('disabled registration allowed');
    } catch (ApiException $exception) {
        check($exception->getCode() === 403 && $exception->getMessage() === 'SAND_IAM_AUTH_REGISTRATION_DISABLED', 'registration policy error changed');
    }
    check($counts === [count(Identity::$rows), count(IdentityAuth::$rows), count(State::$events), count(State::$audits)], 'disabled registration created state');
    AuthPolicy::$rows[1]->registration_enabled = 1;

    $before = creationState();
    State::$failSuccessAudit = true;
    try {
        $service->activateInvitation($application, 'email', 'invite@example.test', ['username' => 'invite-email', 'password' => 'Invitation-pass-1!'], 'invite-managed-failure');
        throw new \RuntimeException('managed invitation audit failure hidden');
    } catch (\RuntimeException $exception) {
        check($exception->getMessage() === 'account success audit unavailable', 'unexpected managed invitation failure');
    } finally { State::$failSuccessAudit = false; }
    check(creationState() === $before && !\think\facade\Db::$active, 'managed invitation audit failure was not atomic');
    $emailIdentity = $service->activateInvitation($application, 'email', 'invite@example.test', ['username' => 'invite-email', 'password' => 'Invitation-pass-1!'], 'invite-managed-retry');
    $emailAuth = IdentityAuth::$rows[(int) $emailIdentity->id];
    check($emailAuth->email_verified_time !== null && $emailAuth->phone_verified_time === null && verifies('Invitation-pass-1!', $emailAuth->password_hash), 'managed email invitation auth is incorrect');

    $before = creationState();
    State::$failSuccessAudit = true;
    \think\facade\Db::startTrans();
    try {
        $service->activateInvitation($application, 'phone', '+12025550123', ['username' => 'invite-phone', 'password' => 'Invitation-pass-2!'], 'invite-external-failure', false);
        throw new \RuntimeException('external invitation audit failure hidden');
    } catch (\RuntimeException $exception) {
        \think\facade\Db::rollback();
        check($exception->getMessage() === 'account success audit unavailable', 'unexpected external invitation failure');
    } finally { State::$failSuccessAudit = false; }
    check(creationState() === $before && !\think\facade\Db::$active, 'external caller rollback did not restore invitation state');
    \think\facade\Db::startTrans();
    $phoneIdentity = $service->activateInvitation($application, 'phone', '+12025550123', ['username' => 'invite-phone', 'password' => 'Invitation-pass-2!'], 'invite-external-success', false);
    \think\facade\Db::commit();
    $phoneAuth = IdentityAuth::$rows[(int) $phoneIdentity->id];
    check($phoneAuth->phone_verified_time !== null && $phoneAuth->email_verified_time === null && verifies('Invitation-pass-2!', $phoneAuth->password_hash), 'external phone invitation auth is incorrect');
    check(count(Identity::$rows) === 3 && count(IdentityAuth::$rows) === 3 && count(State::$events) === 3, 'account creation retries duplicated state');
    echo "Human register and invitation creation audit atomicity PASS (non-PG)\n";
    foreach (['email', 'phone'] as $required) {
        $other = $required === 'email' ? 'phone' : 'email';
        AuthPolicy::$rows[1]->require_email_verification = $required === 'email' ? 1 : 0;
        AuthPolicy::$rows[1]->require_phone_verification = $required === 'phone' ? 1 : 0;
        ApplicationExperience::$rows[1]->registration_fields = ['username', $other];
        $payload = ['organization_code' => 'acme', 'application_code' => 'portal', 'username' => 'missing-' . $required, 'password' => 'Register-pass-1!', $other => $other === 'email' ? 'other@example.test' : '+12025550124'];
        foreach (['configured', 'default'] as $mode) {
            $experience = ApplicationExperience::$rows;
            if ($mode === 'default') ApplicationExperience::$rows = [];
            $before = creationState();
            $rates = serialize(AuthRateLimit::$rows);
            try {
                $service->register($payload, '192.0.2.10', 'missing-contact-test');
                throw new \RuntimeException('Unverifiable account was created');
            } catch (ApiException $exception) {
                $expected = $mode === 'configured' ? 'SAND_IAM_AUTH_REGISTRATION_CONFIGURATION_INVALID' : 'SAND_IAM_AUTH_REGISTRATION_FIELD_REQUIRED';
                check($exception->getMessage() === $expected && $exception->getCode() === ($mode === 'configured' ? 503 : 400), 'Wrong missing-contact error');
            } finally { ApplicationExperience::$rows = $experience; }
            check(creationState() === $before && serialize(AuthRateLimit::$rows) === $rates, 'Invalid contact requirements changed account or rate state');
        }
    }
    AuthPolicy::$rows[1]->require_email_verification = AuthPolicy::$rows[1]->require_phone_verification = 1;
    ApplicationExperience::$rows[1]->registration_fields = ['username', 'email', 'phone'];
    $verifiedContacts = $service->register([
        'organization_code' => 'acme', 'application_code' => 'portal', 'username' => 'both-contacts',
        'password' => 'Register-pass-1!', 'email' => 'both@example.test', 'phone' => '+12025550125',
    ], '192.0.2.10', 'both-contacts-test');
    check($verifiedContacts['verification_required'] === true && count(Identity::$rows) === 4, 'Complete contact requirements prevented registration');
    echo "Registration verification contact requirements PASS (non-PG)\n";
}
