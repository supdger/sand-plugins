<?php
declare(strict_types=1);

namespace GroupRoleTest {
    final class State {
        public static array $rows = [];
        public static array $audits = [];
        public static ?array $before = null;
        public static array $locks = [];
        public static ?\Closure $afterGroupLock = null;
    }
    class Model {
        public function __construct(private array $data) {}
        public function __get(string $key): mixed { return $this->data[$key] ?? null; }
        public static function where(string $key, mixed $value): Query { return (new Query(static::class))->where($key, $value); }
        public static function create(array $values): static {
            $values['id'] = max([0, ...array_keys(State::$rows[static::class] ?? [])]) + 1;
            State::$rows[static::class][$values['id']] = $values;
            return new static($values);
        }
        public function save(array $values): void {
            $this->data = array_replace($this->data, $values);
            State::$rows[static::class][$this->id] = $this->data;
        }
    }
    final class Query {
        private array $filters = [];
        private bool $locked = false;
        public function __construct(private string $model) {}
        public function where(string $key, mixed $value): self { $this->filters[$key] = $value; return $this; }
        public function lock(bool $lock): self { $this->locked = $lock; return $this; }
        public function find(): ?object {
            foreach (State::$rows[$this->model] ?? [] as $row) {
                foreach ($this->filters as $key => $value) if (($row[$key] ?? null) !== $value) continue 2;
                if ($this->locked) {
                    if (State::$before === null) throw new \RuntimeException('Row lock outside transaction');
                    State::$locks[] = [$this->model, $row['id']];
                    if ($this->model === \plugin\SandIam\app\model\IdentityGroup::class && State::$afterGroupLock !== null) {
                        $callback = State::$afterGroupLock;
                        State::$afterGroupLock = null;
                        $callback();
                    }
                }
                return new ($this->model)($row);
            }
            return null;
        }
    }
    function check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    class Application extends \GroupRoleTest\Model {}
    class IdentityGroup extends \GroupRoleTest\Model {}
    class Role extends \GroupRoleTest\Model {}
    class IdentityGroupRole extends \GroupRoleTest\Model {}
}
namespace think\facade {
    use GroupRoleTest\State;
    class Db {
        public static function startTrans(): void {
            if (State::$before !== null) throw new \RuntimeException('Nested transaction');
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
namespace {
    use GroupRoleTest\State;
    use function GroupRoleTest\check;
    use plugin\SandIam\app\model\{Application, IdentityGroup, Role, IdentityGroupRole};
    use plugin\SandIam\app\service\IdentityGroupRoleService;
    require dirname(__DIR__) . '/app/service/IdentityGroupRoleService.php';
    State::$rows = [
        Application::class => [10 => ['id' => 10, 'organization_id' => 1]],
        IdentityGroup::class => [20 => ['id' => 20, 'application_id' => 10, 'status' => 1]],
        Role::class => [30 => ['id' => 30, 'application_id' => 10, 'status' => 1]],
        IdentityGroupRole::class => [],
    ];
    $audit = static function (...$arguments): void {
        check(State::$before !== null, 'Audit outside transaction');
        State::$audits[] = $arguments;
    };
    $service = new IdentityGroupRoleService($audit);
    $id = $service->grant(20, 30, 10, 'admin', 'group-role-grant');
    check(State::$rows[IdentityGroupRole::class][$id]['status'] === 1 && count(State::$audits) === 1, 'Grant failed');
    $grantLocks = State::$locks;
    $service->revoke($id, 10, 'admin', 'group-role-revoke');
    check(State::$rows[IdentityGroupRole::class][$id]['status'] === 2 && count(State::$audits) === 2, 'Revoke failed');
    check($grantLocks[0] === [IdentityGroup::class, 20] && State::$locks[0] === $grantLocks[0], 'Grant and revoke acquire different first row locks');
    check($service->grant(20, 30, 10, 'admin', 'group-role-restore') === $id && count(State::$rows[IdentityGroupRole::class]) === 1, 'Regrant duplicated binding');

    $fault = new IdentityGroupRoleService(static function (...$arguments) use ($audit): void { $audit(...$arguments); throw new \RuntimeException('audit failed'); });
    foreach (['grant', 'revoke'] as $operation) {
        State::$rows[IdentityGroupRole::class][$id]['status'] = $operation === 'grant' ? 2 : 1;
        $before = [State::$rows, State::$audits];
        try {
            if ($operation === 'grant') $fault->grant(20, 30, 10, 'admin', 'fault-grant');
            else $fault->revoke($id, 10, 'admin', 'fault-revoke');
            throw new \RuntimeException('Audit failure ignored');
        } catch (\RuntimeException $error) { check($error->getMessage() === 'audit failed', $error->getMessage()); }
        check([State::$rows, State::$audits] === $before && State::$before === null, 'Audit failure left state');
    }
    State::$rows[IdentityGroup::class][20]['status'] = 2;
    $service->revoke($id, 10, 'admin', 'disabled-group-revoke');
    check(State::$rows[IdentityGroupRole::class][$id]['status'] === 2, 'Cannot revoke disabled group');
    State::$rows[IdentityGroup::class][20]['status'] = 1;
    foreach ([0, 11] as $application) {
        $before = [State::$rows, State::$audits];
        try { $service->revoke($id, $application, 'admin', 'wrong-scope'); throw new \RuntimeException('Wrong application accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 400, 'Wrong boundary error'); }
        check([State::$rows, State::$audits] === $before, 'Cross application revoke wrote state');
    }
    // A changed binding must be re-read after acquiring the group lock.
    foreach (['identity_group_id' => 21, 'role_id' => 31, 'application_id' => 11, 'deleted' => 0] as $field => $value) {
        $before = [State::$rows, State::$audits];
        State::$afterGroupLock = static function () use ($id, $field, $value): void {
            if ($field === 'deleted') unset(State::$rows[IdentityGroupRole::class][$id]);
            else State::$rows[IdentityGroupRole::class][$id][$field] = $value;
        };
        try { $service->revoke($id, 10, 'admin', 'changed-binding'); throw new \RuntimeException('Changed binding accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 400, 'Wrong changed binding error'); }
        check([State::$rows, State::$audits] === $before, 'Changed binding caused mutation');
    }
    $auditCount = count(State::$audits);
    $service->revoke($id, 10, 'admin', 'revoke-again');
    check(State::$rows[IdentityGroupRole::class][$id]['status'] === 2 && count(State::$audits) === $auditCount + 1, 'Repeated revoke changed established audit behavior');
    echo "identity group role lock and transaction non-PG behavior PASS\n";
}
