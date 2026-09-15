<?php
declare(strict_types=1);

namespace IdentityRelationAtomicTest {
    final class State
    {
        public static bool $failAudit = false;
        public static bool $allowApplication = true;
        public static array $audits = [];
    }
    #[\AllowDynamicProperties]
    class Record
    {
        public function __construct(array $values = [], private bool $empty = false)
        { foreach ($values as $key => $value) $this->$key = $value; }
        public function isEmpty(): bool { return $this->empty; }
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
        public static function find(int $id): ?static { return static::$rows[$id] ?? null; }
        public static function findOrEmpty(int $id): static
        { return static::$rows[$id] ?? new static([], true); }
        public static function where(string $field, mixed $value): Query
        { return (new Query(static::class))->where($field, $value); }
    }
    final class Query
    {
        private array $filters = [];
        public function __construct(private readonly string $model) {}
        public function where(string $field, mixed $value): self
        { $this->filters[] = [$field, $value]; return $this; }
        public function order(string $field, string $direction): self { return $this; }
        public function find(): ?Record
        {
            foreach ($this->model::$rows as $row) {
                foreach ($this->filters as [$field, $value]) if (($row->$field ?? null) !== $value) continue 2;
                return $row;
            }
            return null;
        }
        public function select(): Result
        {
            $rows = [];
            foreach ($this->model::$rows as $row) {
                foreach ($this->filters as [$field, $value]) if (($row->$field ?? null) !== $value) continue 2;
                $rows[] = $row;
            }
            return new Result($rows);
        }
    }
    final class Result
    {
        public function __construct(private readonly array $rows) {}
        public function toArray(): array { return array_map(fn (Record $row): array => get_object_vars($row), $this->rows); }
    }
    function check(bool $condition, string $message): void
    { if (!$condition) throw new \RuntimeException($message); }
}

namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }

namespace plugin\sandadmin\service {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class Permission { public function __construct(string $name, string $code) {} }
}

namespace support {
    class Request
    {
        public function __construct(private readonly array $values) {}
        public function post(?string $key = null, mixed $default = null): mixed
        { return $key === null ? $this->values : ($this->values[$key] ?? $default); }
        public function input(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
        public function header(string $key, mixed $default = null): mixed
        { return $key === 'X-Request-Id' ? ($this->values['_request_id'] ?? $default) : $default; }
    }
    class Response { public function __construct(public readonly mixed $data = null) {} }
}

namespace plugin\sandadmin\basic {
    class BaseController
    {
        protected int $adminId = 7;
        protected array $adminInfo = ['id' => 7];
        protected function success(mixed $data = null, string $message = ''): \support\Response
        { return new \support\Response($data); }
    }
}

namespace plugin\SandIam\app\admin\support {
    class AdminOrganizationAccess
    {
        public function __construct(mixed ...$arguments) {}
        public function assertApplication(int $applicationId): void
        {
            if (!\IdentityRelationAtomicTest\State::$allowApplication) {
                throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_ORGANIZATION_ACCESS_DENIED', 403);
            }
        }
    }
}

namespace plugin\SandIam\app\model {
    class Application extends \IdentityRelationAtomicTest\Record { public static array $rows = []; }
    class Identity extends \IdentityRelationAtomicTest\Record { public static array $rows = []; }
    class IdentityRole extends \IdentityRelationAtomicTest\Record { public static array $rows = []; }
    class IdentityUserType extends \IdentityRelationAtomicTest\Record { public static array $rows = []; }
    class Role extends \IdentityRelationAtomicTest\Record { public static array $rows = []; }
    class UserType extends \IdentityRelationAtomicTest\Record { public static array $rows = []; }
}

namespace think\facade {
    final class Db
    {
        public static bool $active = false;
        private static string $snapshot = '';
        public static function startTrans(): void
        {
            if (self::$active) throw new \RuntimeException('nested transaction');
            self::$snapshot = serialize([
                \plugin\SandIam\app\model\IdentityRole::$rows,
                \plugin\SandIam\app\model\IdentityUserType::$rows,
                \IdentityRelationAtomicTest\State::$audits,
            ]);
            self::$active = true;
        }
        public static function commit(): void { self::$active = false; self::$snapshot = ''; }
        public static function rollback(): void
        {
            [
                \plugin\SandIam\app\model\IdentityRole::$rows,
                \plugin\SandIam\app\model\IdentityUserType::$rows,
                \IdentityRelationAtomicTest\State::$audits,
            ] = unserialize(self::$snapshot);
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
            \IdentityRelationAtomicTest\State::$audits[] = $values;
            if (\IdentityRelationAtomicTest\State::$failAudit) throw new \RuntimeException('identity relation audit unavailable');
        }
    }
}

namespace {
    use IdentityRelationAtomicTest\State;
    use function IdentityRelationAtomicTest\check;
    use plugin\SandIam\app\admin\controller\{IdentityRoleController, IdentityUserTypeController};
    use plugin\SandIam\app\model\{Application, Identity, IdentityRole, IdentityUserType, Role, UserType};
    use plugin\sandadmin\exception\ApiException;
    use support\Request;

    require dirname(__DIR__) . '/app/admin/controller/IdentityRoleController.php';
    require dirname(__DIR__) . '/app/admin/controller/IdentityUserTypeController.php';
    Application::$rows = [
        1 => new Application(['id' => 1, 'organization_id' => 101, 'status' => 1]),
        2 => new Application(['id' => 2, 'organization_id' => 102, 'status' => 1]),
    ];
    Identity::$rows = [
        10 => new Identity(['id' => 10, 'application_id' => 1, 'status' => 1]),
        11 => new Identity(['id' => 11, 'application_id' => 2, 'status' => 1]),
    ];
    Role::$rows = [
        20 => new Role(['id' => 20, 'application_id' => 1, 'status' => 1]),
        21 => new Role(['id' => 21, 'application_id' => 2, 'status' => 1]),
    ];
    UserType::$rows = [
        30 => new UserType(['id' => 30, 'application_id' => 1, 'status' => 1]),
        31 => new UserType(['id' => 31, 'application_id' => 2, 'status' => 1]),
    ];
    IdentityRole::$rows = [80 => new IdentityRole(['id' => 80, 'identity_id' => 11, 'role_id' => 21, 'status' => 1])];
    IdentityUserType::$rows = [81 => new IdentityUserType(['id' => 81, 'identity_id' => 11, 'user_type_id' => 31, 'status' => 1])];
    $roles = new IdentityRoleController();
    $types = new IdentityUserTypeController();
    $state = static fn (): string => serialize([IdentityRole::$rows, IdentityUserType::$rows, State::$audits]);
    $auditFailure = static function (callable $operation, string $message) use ($state): void {
        $before = $state();
        State::$failAudit = true;
        try {
            $operation();
            throw new \RuntimeException('audit failure hidden');
        } catch (\RuntimeException $exception) {
            check($exception->getMessage() === 'identity relation audit unavailable', "unexpected {$message} failure");
        } finally { State::$failAudit = false; }
        check(!\think\facade\Db::$active && $state() === $before, "{$message} audit failure left partial state");
    };
    $rejectsWithoutChange = static function (callable $operation, int $status, string $message) use ($state): void {
        $before = $state();
        try {
            $operation();
            throw new \RuntimeException("{$message} accepted");
        } catch (ApiException $exception) {
            check($exception->getCode() === $status, "{$message} returned wrong status");
        }
        check($state() === $before, "{$message} changed relations or audit");
    };
    $cases = [
        [
            'name' => 'identity role', 'controller' => $roles, 'model' => IdentityRole::class,
            'reference' => 'role_id', 'local' => 20, 'foreign' => 21,
        ],
        [
            'name' => 'identity user type', 'controller' => $types, 'model' => IdentityUserType::class,
            'reference' => 'user_type_id', 'local' => 30, 'foreign' => 31,
        ],
    ];
    foreach ($cases as $case) {
        $controller = $case['controller'];
        $model = $case['model'];
        $grant = static fn () => $controller->grant(new Request([
            'identity_id' => 10, $case['reference'] => $case['local'], '_request_id' => 'relation-grant',
        ]));
        $auditFailure($grant, $case['name'] . ' new grant');
        $grant();
        $relation = $model::where('identity_id', 10)->where($case['reference'], $case['local'])->find();
        check($relation !== null && $relation->status === 1, $case['name'] . ' retry did not create relation');
        $id = (int) $relation->id;
        $revoke = static fn () => $controller->revoke(new Request(['id' => $id, '_request_id' => 'relation-revoke']));
        $auditFailure($revoke, $case['name'] . ' revoke');
        $revoke();
        check($model::$rows[$id]->status === 2, $case['name'] . ' retry did not revoke relation');
        $auditFailure($grant, $case['name'] . ' restore');
        check($model::$rows[$id]->status === 2, $case['name'] . ' failed restore changed status');
        $grant();
        check($model::$rows[$id]->status === 1, $case['name'] . ' retry did not restore relation');
        $matches = array_filter($model::$rows, static fn ($row): bool =>
            ($row->identity_id ?? 0) === 10 && ($row->{$case['reference']} ?? 0) === $case['local']);
        check(count($matches) === 1, $case['name'] . ' retry duplicated relation');
        $rejectsWithoutChange(
            static fn () => $controller->grant(new Request(['identity_id' => 10, $case['reference'] => $case['foreign']])),
            400,
            $case['name'] . ' foreign reference',
        );
        State::$allowApplication = false;
        $rejectsWithoutChange($grant, 403, $case['name'] . ' missing application scope');
        State::$allowApplication = true;
        check($model::$rows[$case['name'] === 'identity role' ? 80 : 81]->status === 1, $case['name'] . ' changed existing foreign relation');
    }
    echo "Identity role and user-type relation atomic grant/revoke behavior PASS (non-PG)\n";
}
