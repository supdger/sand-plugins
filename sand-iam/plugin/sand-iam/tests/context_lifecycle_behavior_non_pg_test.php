<?php
declare(strict_types=1);
namespace ContextLifecycleTest {
    final class State
    {
        public static array $models = [];
        public static array $tables = [];
        public static array $audits = [];
        public static int $grantReads = 0;
    }
    class Model
    {
        public function __construct(private readonly array $values) {}
        public function __get(string $key): mixed { return $this->values[$key] ?? null; }
        public static function where(string $key, mixed $value): ModelQuery
        { return (new ModelQuery(static::class))->where($key, $value); }
    }
    final class ModelQuery
    {
        private array $filters = [];
        public function __construct(private readonly string $model) {}
        public function where(string $key, mixed $value): self
        { $this->filters[] = [$key, $value]; return $this; }
        public function find(): ?object
        {
            foreach (State::$models[$this->model] ?? [] as $row) {
                foreach ($this->filters as [$key, $value])
                    if (($row[$key] ?? null) !== $value) continue 2;
                return new ($this->model)($row);
            }
            return null;
        }
    }
    final class OrGroup
    {
        public array $conditions = [];
        public function whereNull(string $key): self
        { $this->conditions[] = [$key, 'null', null]; return $this; }
        public function whereOr(string $key, string $operator, mixed $value): self
        { $this->conditions[] = [$key, $operator, $value]; return $this; }
    }
    final class DbQuery
    {
        private string $alias = '';
        private array $joins = [];
        private array $filters = [];
        private string $fields = '';
        public function __construct(private readonly string $table) {}
        public function alias(string $alias): self { $this->alias = $alias; return $this; }
        public function join(string $table, string $condition): self
        {
            [$left, $right] = array_map('trim', explode('=', $condition, 2));
            $this->joins[] = [$table, $left, $right];
            return $this;
        }
        public function where(mixed $key, mixed $operatorOrValue = null, mixed $value = null): self
        {
            if (is_callable($key)) {
                $group = new OrGroup();
                $key($group);
                $this->filters[] = static fn (array $joined): bool =>
                    matchesAny($group->conditions, static fn (array $condition): bool =>
                        self::compare(self::column($joined, $condition[0]), $condition[1], $condition[2]));
                return $this;
            }
            $operator = func_num_args() === 2 ? '=' : (string) $operatorOrValue;
            $expected = func_num_args() === 2 ? $operatorOrValue : $value;
            $this->filters[] = static fn (array $joined): bool =>
                self::compare(self::column($joined, (string) $key), $operator, $expected);
            return $this;
        }
        public function whereNull(string $key): self { return $this->where($key, 'null', null); }
        public function whereIn(string $key, array $values): self
        {
            $this->filters[] = static fn (array $joined): bool =>
                in_array(self::column($joined, $key), $values, true);
            return $this;
        }
        public function field(string $fields): self { $this->fields = $fields; return $this; }
        public function select(): Result
        {
            State::$grantReads++;
            $baseAlias = $this->alias !== '' ? $this->alias : $this->table;
            $rows = array_map(
                static fn (array $row): array => [$baseAlias => $row],
                array_values(State::$tables[$this->table] ?? []),
            );
            foreach ($this->joins as [$table, $left, $right]) {
                $alias = str_contains($table, ' ') ? substr($table, strrpos($table, ' ') + 1) : $table;
                $joinedRows = [];
                foreach ($rows as $joined) {
                    foreach (State::$tables[strtok($table, ' ')] ?? [] as $row) {
                        $candidate = $joined + [$alias => $row];
                        if (self::column($candidate, $left) === self::column($candidate, $right)) {
                            $joinedRows[] = $candidate;
                        }
                    }
                }
                $rows = $joinedRows;
            }
            $rows = array_values(array_filter($rows, fn (array $row): bool =>
                matchesAll($this->filters, static fn (callable $filter): bool => $filter($row))));
            return new Result(array_map(fn (array $row): array => $this->project($row), $rows));
        }
        private function project(array $joined): array
        {
            $result = [];
            foreach (array_map('trim', explode(',', $this->fields)) as $field) {
                $parts = preg_split('/\s+AS\s+/i', $field);
                $source = $parts[0];
                $name = $parts[1] ?? substr($source, strrpos($source, '.') + 1);
                $result[$name] = self::column($joined, $source);
            }
            return $result;
        }
        private static function column(array $joined, string $reference): mixed
        {
            [$alias, $column] = array_pad(explode('.', $reference, 2), 2, '');
            if ($column !== '') return $joined[$alias][$column] ?? null;
            foreach ($joined as $row) if (array_key_exists($alias, $row)) return $row[$alias];
            return null;
        }
        private static function compare(mixed $actual, string $operator, mixed $expected): bool
        {
            return match (strtolower($operator)) {
                '=', '==' => $actual === $expected,
                'null' => $actual === null,
                '>' => is_scalar($actual) && (string) $actual > (string) $expected,
                default => false,
            };
        }
    }
    final class Result
    {
        public function __construct(private readonly array $rows) {}
        public function toArray(): array { return $this->rows; }
    }
    function check(bool $condition, string $message): void
    { if (!$condition) throw new \RuntimeException($message); }
    function matchesAny(array $values, callable $predicate): bool
    { foreach ($values as $value) if ($predicate($value)) return true; return false; }
    function matchesAll(array $values, callable $predicate): bool
    { foreach ($values as $value) if (!$predicate($value)) return false; return true; }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    class Application extends \ContextLifecycleTest\Model {}
    class Credential extends \ContextLifecycleTest\Model {}
    class Environment extends \ContextLifecycleTest\Model {}
    class Organization extends \ContextLifecycleTest\Model {}
    class WorkloadClient extends \ContextLifecycleTest\Model {}
}
namespace think\facade {
    final class Db
    {
        public static function table(string $table): \ContextLifecycleTest\DbQuery
        { return new \ContextLifecycleTest\DbQuery($table); }
    }
}
namespace plugin\SandIam\app\service {
    class AuditWriter
    {
        public function write(mixed ...$values): void
        { \ContextLifecycleTest\State::$audits[] = $values; }
    }
}
namespace {
    use ContextLifecycleTest\State;
    use function ContextLifecycleTest\check;
    use plugin\SandIam\app\model\{Application, Credential, Environment, Organization, WorkloadClient};
    use plugin\SandIam\app\runtime\IdentityContextProvider;
    use plugin\sandadmin\exception\ApiException;
    const SIGNING_KEY = 'offline-context-lifecycle-signing-key-32-bytes';
    function config(string $key, mixed $default = null): mixed
    { return $key === 'plugin.sand-iam.app.context_signing_key' ? SIGNING_KEY : $default; }
    $app = dirname(__DIR__) . '/app/';
    foreach ([
        'service/RequestId.php',
        'radius/RadiusNetwork.php',
        'security/NetworkPolicy.php',
        'security/ServiceGrantConstraintNormalizer.php',
        'runtime/EnvironmentReferenceVerifier.php',
        'runtime/IdentityContextProvider.php',
    ] as $file) require_once $app . $file;
    $plainCredential = 'ctx_credential_0123456789abcdef';
    State::$models = [
        Organization::class => [101 => ['id' => 101, 'status' => 1]],
        Application::class => [201 => ['id' => 201, 'organization_id' => 101, 'status' => 1]],
        Environment::class => [301 => ['id' => 301, 'application_id' => 201, 'status' => 1]],
        WorkloadClient::class => [401 => ['id' => 401, 'environment_id' => 301, 'audience' => 'records-api', 'status' => 1]],
        Credential::class => [501 => [
            'id' => 501, 'workload_client_id' => 401, 'key_prefix' => substr($plainCredential, 0, 16),
            'secret_hash' => password_hash($plainCredential, PASSWORD_DEFAULT),
            'status' => 1, 'revoked_time' => null, 'expire_time' => null,
        ]],
    ];
    $grant = [
        'id' => 601, 'workload_client_id' => 401, 'service_action_id' => 701,
        'audience' => 'records-api', 'status' => 1, 'revoked_time' => null, 'expire_time' => null,
        'network_policy' => ['allow_cidrs' => ['192.0.2.0/24'], 'deny_cidrs' => []],
        'quota_policy' => ['max_invocation_attempts' => 25, 'window_seconds' => 60],
        'data_class' => 'internal',
    ];
    State::$tables = [
        'sand_iam_service_grant' => [601 => $grant],
        'sand_iam_service_action' => [701 => ['id' => 701, 'service_id' => 801, 'code' => 'record.read', 'status' => 1]],
        'sand_iam_service' => [801 => ['id' => 801, 'code' => 'records', 'status' => 1]],
    ];
    $provider = new IdentityContextProvider();
    $issued = $provider->issue($plainCredential, 'records-api', ['record.read'], ['tenant_ref' => 'alpha'], 'request-issue-001', '192.0.2.10', 'records');
    check(password_verify($plainCredential, State::$models[Credential::class][501]['secret_hash']), 'credential fixture does not use a real password hash');
    check(substr_count($issued['context'], '.') === 1, 'issued context is not HMAC signed');
    $verify = static fn (string $context = ''): array => $provider->verifyForService(
        $context !== '' ? $context : $issued['context'],
        'records',
        'records-api',
        'record.read',
        '192.0.2.10',
        'request-verify-001',
    );
    $claims = $verify();
    foreach (['organization_id' => 101, 'application_id' => 201, 'environment_id' => 301, 'workload_client_id' => 401, 'credential_id' => 501, 'service_code' => 'records', 'audience' => 'records-api', 'subject_scope_trust' => 'caller_asserted'] as $key => $value) {
        check($claims[$key] === $value, 'Incorrect verified claim: ' . $key);
    }
    check(
        $claims['subject_scope'] === ['tenant_ref' => 'alpha']
        && $claims['grant_ids'] === [601]
        && $claims['action_grants']['record.read'] === [
            'grant_id' => 601,
            'service_code' => 'records',
            'data_class' => 'internal',
            'quota_policy' => ['max_invocation_attempts' => 25, 'window_seconds' => 60],
        ],
        'verified scope or live grant constraints are incorrect',
    );
    $reads = State::$grantReads;
    $allowed = count(array_filter(State::$audits, static fn (array $audit): bool => ($audit[7] ?? null) === 'allowed'));
    check($verify()['context_id'] === $issued['context_id'], 'same context could not be verified again');
    check(State::$grantReads === $reads + 1, 'repeated verification did not re-read live grants');
    check(count(array_filter(State::$audits, static fn (array $audit): bool => ($audit[7] ?? null) === 'allowed')) === $allowed + 1, 'repeated verification did not append an allowed audit');
    $reject = static function (callable $operation, string $code, bool $deniedAudit = true): void {
        $before = count(State::$audits);
        try {
            $operation();
            throw new \RuntimeException("expected rejection {$code}");
        } catch (ApiException $exception) {
            check(str_contains($exception->getMessage(), $code), "wrong rejection: {$exception->getMessage()}");
        }
        $new = array_slice(State::$audits, $before);
        check(array_filter($new, static fn (array $audit): bool => ($audit[7] ?? null) === 'allowed') === [], "{$code} emitted allowed audit");
        check(!$deniedAudit || count($new) === 1 && ($new[0][7] ?? null) === 'denied', "{$code} missing denied audit");
    };
    $restoreCheck = static function (callable $mutate, callable $restore, string $code, bool $deniedAudit = true) use ($reject, $verify): void {
        $mutate();
        $reject($verify, $code, $deniedAudit);
        $restore();
        check($verify()['grant_ids'] === [601], "same provider did not observe recovery after {$code}");
    };
    $tampered = substr($issued['context'], 0, -1) . (str_ends_with($issued['context'], 'A') ? 'B' : 'A');
    $reject(static fn () => $verify($tampered), 'SAND_IAM_AUTHENTICATION_FAILED');
    $signatureAudit = State::$audits[array_key_last(State::$audits)];
    check($signatureAudit[1] === 'unknown' && $signatureAudit[2] === null && $signatureAudit[3] === null, 'Unverified context supplied trusted audit identity');
    check(!str_contains(json_encode($signatureAudit, JSON_THROW_ON_ERROR), $tampered), 'Signature rejection audit leaked context');
    $reject(static fn () => $provider->verifyForService($issued['context'], 'records', 'other-api', 'record.read', '192.0.2.10'), 'SAND_IAM_CONTEXT_AUDIENCE_MISMATCH');
    $reject(static fn () => $provider->verifyForService($issued['context'], 'records', 'records-api', 'record.write', '192.0.2.10'), 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
    $reject(static fn () => $provider->verifyForService($issued['context'], 'search', 'records-api', 'record.read', '192.0.2.10'), 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
    $restoreCheck(
        static fn () => State::$models[Credential::class][501]['revoked_time'] = '2026-01-01 00:00:00',
        static fn () => State::$models[Credential::class][501]['revoked_time'] = null,
        'SAND_IAM_CREDENTIAL_REVOKED',
    );
    $restoreCheck(
        static fn () => State::$models[WorkloadClient::class][401]['status'] = 2,
        static fn () => State::$models[WorkloadClient::class][401]['status'] = 1,
        'SAND_IAM_CREDENTIAL_REVOKED',
    );
    foreach ([
        [Organization::class, 101],
        [Application::class, 201],
        [Environment::class, 301],
    ] as [$model, $id]) {
        $restoreCheck(
            static fn () => State::$models[$model][$id]['status'] = 2,
            static fn () => State::$models[$model][$id]['status'] = 1,
            'SAND_IAM_SERVICE_ACTION_FORBIDDEN',
        );
        State::$models[$model][$id]['status'] = 2;
        $reject(static fn () => $provider->issue($plainCredential, 'records-api', ['record.read'], null, 'request-denied-issue', '192.0.2.10', 'records'), 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
        $issueAudit = State::$audits[array_key_last(State::$audits)];
        check($issueAudit[4] === 'context.issue' && $issueAudit[2] === null && $issueAudit[3] === null, 'Unavailable environment reference supplied trusted audit scope');
        State::$models[$model][$id]['status'] = 1;
    }
    $restoreCheck(
        static fn () => State::$tables['sand_iam_service_grant'][601]['revoked_time'] = '2026-01-01 00:00:00',
        static fn () => State::$tables['sand_iam_service_grant'][601]['revoked_time'] = null,
        'SAND_IAM_SERVICE_ACTION_FORBIDDEN',
    );
    State::$tables['sand_iam_service_grant'][601]['status'] = 2;
    State::$tables['sand_iam_service_grant'][602] = array_replace($grant, ['id' => 602]);
    $reject($verify, 'SAND_IAM_SERVICE_ACTION_FORBIDDEN');
    unset(State::$tables['sand_iam_service_grant'][602]);
    State::$tables['sand_iam_service_grant'][601]['status'] = 1;
    check($verify()['grant_ids'] === [601], 'original grant recovery was not observed');
    $restoreCheck(
        static fn () => State::$tables['sand_iam_service_grant'][601]['network_policy'] = ['allow_cidrs' => ['198.51.100.0/24'], 'deny_cidrs' => []],
        static fn () => State::$tables['sand_iam_service_grant'][601]['network_policy'] = ['allow_cidrs' => ['192.0.2.0/24'], 'deny_cidrs' => []],
        'SAND_IAM_SERVICE_NETWORK_FORBIDDEN',
    );
    echo "Identity context issue, live lifecycle revalidation, recovery and repeat verification PASS (non-PG)\n";
}
