<?php
declare(strict_types=1);

namespace PasswordResetAtomicTest {
    final class State
    {
        public static bool $failResetAudit = false;
        public static array $audits = [];
    }
    #[\AllowDynamicProperties]
    class Record
    {
        public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; }
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
        public static function where(mixed $field, mixed $value = null): Query
        { return (new Query(static::class))->where($field, $value); }
        public static function whereIn(string $field, array $values): Query
        { return (new Query(static::class))->whereIn($field, $values); }
        public static function alias(string $alias): Query { return (new Query(static::class))->alias($alias); }
    }
    final class OrGroup
    {
        public array $conditions = [];
        public function where(string $field, mixed $value): self
        { $this->conditions[] = [$field, $value]; return $this; }
        public function whereOr(string $field, mixed $value): self { return $this->where($field, $value); }
    }
    final class Query
    {
        private string $alias = '';
        private bool $joinOrganization = false;
        private array $filters = [];
        private ?array $order = null;
        public function __construct(private readonly string $model) {}
        public function alias(string $alias): self { $this->alias = $alias; return $this; }
        public function join(string $table, string $condition): self
        {
            $this->joinOrganization = $table === 'sand_iam_organization organization'
                && $condition === 'organization.id = application.organization_id';
            return $this;
        }
        public function field(string $fields): self { return $this; }
        public function where(mixed $field, mixed $value = null): self
        {
            if (is_callable($field)) {
                $group = new OrGroup();
                $field($group);
                $this->filters[] = fn (Record $row): bool => $this->matchesAny($row, $group->conditions);
                return $this;
            }
            $this->filters[] = fn (Record $row): bool => $this->value($row, (string) $field) === $value;
            return $this;
        }
        public function whereIn(string $field, array $values): self
        {
            $this->filters[] = fn (Record $row): bool => in_array($this->value($row, $field), $values, true);
            return $this;
        }
        public function order(string $field, string $direction): self
        { $this->order = [$field, strtolower($direction)]; return $this; }
        public function lock(bool $lock): self
        {
            if (!$lock || !\think\facade\Db::$active) throw new \RuntimeException('lock outside transaction');
            return $this;
        }
        public function find(): ?Record { return $this->rows()[0] ?? null; }
        public function column(string $field): array
        { return array_map(fn (Record $row): mixed => $this->value($row, $field), $this->rows()); }
        public function update(array $values): int
        {
            $rows = $this->rows();
            foreach ($rows as $row) $row->save($values);
            return count($rows);
        }
        private function rows(): array
        {
            $rows = array_values($this->model::$rows);
            $rows = array_values(array_filter($rows, function (Record $row): bool {
                if ($this->joinOrganization && $this->organization($row) === null) return false;
                foreach ($this->filters as $filter) if (!$filter($row)) return false;
                return true;
            }));
            if ($this->order !== null) {
                [$field, $direction] = $this->order;
                usort($rows, fn (Record $left, Record $right): int =>
                    ($direction === 'desc' ? -1 : 1) * ($this->value($left, $field) <=> $this->value($right, $field)));
            }
            return $rows;
        }
        private function matchesAny(Record $row, array $conditions): bool
        {
            foreach ($conditions as [$field, $value]) if ($this->value($row, $field) === $value) return true;
            return false;
        }
        private function value(Record $row, string $field): mixed
        {
            [$prefix, $name] = array_pad(explode('.', $field, 2), 2, '');
            if ($name === '') return $row->$prefix ?? null;
            if ($prefix === 'organization') return $this->organization($row)?->$name;
            return $row->$name ?? null;
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
    class Application extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class ApplicationExperience extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class ApplicationNetworkPolicy extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class AuthChallenge extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class AuthPolicy extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class AuthRateLimit extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class AuthRefreshToken extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class AuthSession extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class AuthVerification extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class Identity extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class IdentityAuth extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class IdentityBinding extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class IdentityProvider extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class IdentityProviderApplication extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
    class Organization extends \PasswordResetAtomicTest\Record { public static array $rows = []; }
}

namespace think\facade {
    final class Db
    {
        public static bool $active = false;
        private static string $snapshot = '';
        private const MODELS = [
            \plugin\SandIam\app\model\Application::class,
            \plugin\SandIam\app\model\AuthRateLimit::class,
            \plugin\SandIam\app\model\AuthRefreshToken::class,
            \plugin\SandIam\app\model\AuthSession::class,
            \plugin\SandIam\app\model\AuthVerification::class,
            \plugin\SandIam\app\model\IdentityAuth::class,
        ];
        public static function startTrans(): void
        {
            if (self::$active) throw new \RuntimeException('nested transaction');
            $rows = [];
            foreach (self::MODELS as $model) $rows[$model] = $model::$rows;
            self::$snapshot = serialize([$rows, \PasswordResetAtomicTest\State::$audits]);
            self::$active = true;
        }
        public static function commit(): void { self::$active = false; self::$snapshot = ''; }
        public static function rollback(): void
        {
            [$rows, \PasswordResetAtomicTest\State::$audits] = unserialize(self::$snapshot);
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
            \PasswordResetAtomicTest\State::$audits[] = $values;
            if (\PasswordResetAtomicTest\State::$failResetAudit && ($values[4] ?? '') === 'identity.password_reset') {
                throw new \RuntimeException('password reset audit unavailable');
            }
        }
    }
}

namespace {
    use PasswordResetAtomicTest\State;
    use function PasswordResetAtomicTest\check;
    use plugin\SandIam\app\model\{
        Application, ApplicationNetworkPolicy, AuthPolicy, AuthRateLimit, AuthRefreshToken,
        AuthSession, AuthVerification, IdentityAuth, Organization
    };
    use plugin\SandIam\app\service\HumanAuthService;
    use plugin\sandadmin\exception\ApiException;

    const PEPPER = 'password-reset-offline-pepper';
    function config(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'plugin.sand-iam.app.auth_pepper' => PEPPER,
            'plugin.sand-iam.app.auth_pepper_version' => 'v1',
            'plugin.sand-iam.app.application_network_policy_enabled' => 0,
            default => $default,
        };
    }
    function secret(string $value): string { return hash_hmac('sha256', $value, PEPPER); }
    function passwordHash(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash(secret('password:' . $password), $algorithm);
    }
    function verifies(string $password, string $hash): bool
    { return password_verify(secret('password:' . $password), $hash); }

    require dirname(__DIR__) . '/app/service/HumanAuthService.php';
    $oldPassword = 'Old-password-1!';
    $newPassword = 'New-password-2!';
    $email = 'owner@example.test';
    $code = '12345678';
    Organization::$rows = [
        20 => new Organization(['id' => 20, 'code' => 'acme', 'status' => 1]),
        21 => new Organization(['id' => 21, 'code' => 'other', 'status' => 1]),
    ];
    Application::$rows = [
        10 => new Application(['id' => 10, 'organization_id' => 20, 'code' => 'portal', 'status' => 1]),
        11 => new Application(['id' => 11, 'organization_id' => 21, 'code' => 'portal', 'status' => 1]),
    ];
    ApplicationNetworkPolicy::$rows = AuthPolicy::$rows = AuthRateLimit::$rows = [];
    IdentityAuth::$rows = [
        30 => new IdentityAuth(['id' => 30, 'application_id' => 10, 'identity_id' => 300, 'username' => 'owner', 'email' => $email, 'phone' => null, 'password_hash' => passwordHash($oldPassword), 'pepper_version' => 'v1', 'failed_login_count' => 4, 'locked_until' => '2099-01-01 00:00:00', 'status' => 1]),
        31 => new IdentityAuth(['id' => 31, 'application_id' => 11, 'identity_id' => 301, 'username' => 'owner-other', 'email' => $email, 'phone' => null, 'password_hash' => passwordHash($oldPassword), 'pepper_version' => 'v1', 'failed_login_count' => 0, 'locked_until' => null, 'status' => 1]),
    ];
    $destinationHash = secret($email);
    $verificationHash = static fn (int $applicationId, int $identityId, string $purpose, string $channel): string =>
        secret(implode('|', ['verification', $applicationId, $purpose, $channel, $destinationHash, $code]));
    AuthVerification::$rows = [
        40 => new AuthVerification(['id' => 40, 'application_id' => 10, 'identity_id' => 300, 'purpose' => 'password_reset', 'channel' => 'email', 'destination_hash' => $destinationHash, 'code_hash' => $verificationHash(10, 300, 'password_reset', 'email'), 'expire_time' => '2099-01-01 00:00:00', 'pepper_version' => 'v1', 'consumed_time' => null, 'attempt_count' => 0, 'status' => 1]),
        41 => new AuthVerification(['id' => 41, 'application_id' => 11, 'identity_id' => 301, 'purpose' => 'password_reset', 'channel' => 'email', 'destination_hash' => $destinationHash, 'code_hash' => $verificationHash(11, 301, 'password_reset', 'email'), 'expire_time' => '2099-01-01 00:00:00', 'pepper_version' => 'v1', 'consumed_time' => null, 'attempt_count' => 0, 'status' => 1]),
        42 => new AuthVerification(['id' => 42, 'application_id' => 10, 'identity_id' => 300, 'purpose' => 'email_verify', 'channel' => 'email', 'destination_hash' => $destinationHash, 'code_hash' => $verificationHash(10, 300, 'email_verify', 'email'), 'expire_time' => '2099-01-01 00:00:00', 'pepper_version' => 'v1', 'consumed_time' => null, 'attempt_count' => 0, 'status' => 1]),
    ];
    AuthSession::$rows = [
        50 => new AuthSession(['id' => 50, 'identity_id' => 300, 'application_id' => 10, 'status' => 1, 'revoked_time' => null]),
        51 => new AuthSession(['id' => 51, 'identity_id' => 300, 'application_id' => 10, 'status' => 1, 'revoked_time' => null]),
        52 => new AuthSession(['id' => 52, 'identity_id' => 301, 'application_id' => 11, 'status' => 1, 'revoked_time' => null]),
        53 => new AuthSession(['id' => 53, 'identity_id' => 302, 'application_id' => 10, 'status' => 1, 'revoked_time' => null]),
    ];
    AuthRefreshToken::$rows = [];
    foreach (array_keys(AuthSession::$rows) as $id) {
        AuthRefreshToken::$rows[$id] = new AuthRefreshToken(['id' => $id, 'session_id' => $id, 'status' => 1, 'revoked_time' => null]);
    }
    $payload = ['organization_code' => 'acme', 'application_code' => 'portal', 'identifier' => $email, 'channel' => 'email', 'code' => $code, 'password' => $newPassword, '_ip' => '192.0.2.10'];
    $service = new HumanAuthService();
    try {
        $service->resetPassword(array_replace($payload, ['organization_code' => 'missing']), 'reset-invalid-app');
        throw new \RuntimeException('unknown application accepted');
    } catch (ApiException $exception) {
        check($exception->getCode() === 401 && $exception->getMessage() === 'SAND_IAM_AUTHENTICATION_FAILED', 'application lookup did not fail closed');
    }

    $before = serialize([IdentityAuth::$rows, AuthVerification::$rows, AuthSession::$rows, AuthRefreshToken::$rows, State::$audits]);
    State::$failResetAudit = true;
    try {
        $service->resetPassword($payload, 'reset-audit-failure');
        throw new \RuntimeException('audit failure hidden');
    } catch (\RuntimeException $exception) {
        check($exception->getMessage() === 'password reset audit unavailable', 'unexpected reset failure');
    } finally {
        State::$failResetAudit = false;
    }
    check(!\think\facade\Db::$active, 'password reset leaked a transaction');
    check(serialize([IdentityAuth::$rows, AuthVerification::$rows, AuthSession::$rows, AuthRefreshToken::$rows, State::$audits]) === $before, 'audit failure did not atomically roll back password, code, sessions, refresh tokens and audits');
    check(count(AuthRateLimit::$rows) === 1 && AuthRateLimit::$rows[array_key_first(AuthRateLimit::$rows)]->attempt_count === 1, 'transaction-external rate-limit admission was not retained');

    $service->resetPassword($payload, 'reset-retry');
    check(verifies($newPassword, IdentityAuth::$rows[30]->password_hash), 'new password does not verify');
    check(!verifies($oldPassword, IdentityAuth::$rows[30]->password_hash), 'old password still verifies');
    foreach ([50, 51] as $id) check(AuthSession::$rows[$id]->status === 2 && AuthRefreshToken::$rows[$id]->status === 2, 'target session family was not revoked');
    foreach ([52, 53] as $id) check(AuthSession::$rows[$id]->status === 1 && AuthRefreshToken::$rows[$id]->status === 1, 'unrelated session family changed');
    check(verifies($oldPassword, IdentityAuth::$rows[31]->password_hash), 'other application password changed');
    check(AuthVerification::$rows[41]->status === 1 && AuthVerification::$rows[42]->status === 1, 'verification query consumed a cross-scope code');
    try {
        $service->resetPassword($payload, 'reset-replay');
        throw new \RuntimeException('verification code replay accepted');
    } catch (ApiException $exception) {
        check($exception->getCode() === 400 && $exception->getMessage() === 'SAND_IAM_AUTH_VERIFICATION_INVALID', 'verification replay error changed');
    }
    echo "Password reset audit atomicity, retry, revocation scope and replay behavior PASS (non-PG)\n";
}
