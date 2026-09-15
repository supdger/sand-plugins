<?php
declare(strict_types=1);

namespace GroupMemberTest {
    final class State {
        public static array $rows = [];
        public static array $events = [];
        public static array $audits = [];
        public static array $locks = [];
        public static ?array $before = null;
        public static string $failure = '';
    }
    class Model {
        public function __construct(private array $data) {}
        public function __get(string $key): mixed { return $this->data[$key] ?? null; }
        public function __isset(string $key): bool { return isset($this->data[$key]); }
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
                    check(State::$before !== null, 'Lock outside transaction');
                    State::$locks[] = [$this->model, $row['id']];
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
    class Application extends \GroupMemberTest\Model {}
    class IdentityGroup extends \GroupMemberTest\Model {}
    class Identity extends \GroupMemberTest\Model {}
    class IdentityGroupMember extends \GroupMemberTest\Model {}
}
namespace think\facade {
    use GroupMemberTest\State;
    class Db {
        public static function startTrans(): void {
            \GroupMemberTest\check(State::$before === null, 'Nested transaction');
            State::$before = [State::$rows, State::$events, State::$audits];
            State::$locks = [];
        }
        public static function commit(): void { State::$before = null; }
        public static function rollback(): void {
            if (State::$before !== null) [State::$rows, State::$events, State::$audits] = State::$before;
            State::$before = null;
        }
    }
}
namespace plugin\SandIam\app\service {
    use GroupMemberTest\State;
    class AuditWriter {
        public function write(...$arguments): void {
            \GroupMemberTest\check(State::$before !== null, 'Audit outside transaction');
            State::$audits[] = $arguments;
            if (State::$failure === 'audit') throw new \RuntimeException('audit failed');
        }
    }
    class IdentityEventPublisher {
        public function publish(object $application, object $identity, string $event, array $fields, string $request): void {
            \GroupMemberTest\check(State::$before !== null, 'Event outside transaction');
            State::$events[] = [$application->id, $identity->id, $event, $fields, $request];
            if (State::$failure === 'event') throw new \RuntimeException('event failed');
        }
    }
}
namespace {
    use GroupMemberTest\State;
    use function GroupMemberTest\check;
    use plugin\SandIam\app\model\{Application, IdentityGroup, Identity, IdentityGroupMember};
    use plugin\SandIam\app\service\IdentityGroupService;
    use plugin\sandadmin\exception\ApiException;
    require dirname(__DIR__) . '/app/service/IdentityGroupService.php';
    State::$rows = [
        Application::class => [10 => ['id' => 10, 'organization_id' => 1, 'status' => 1]],
        IdentityGroup::class => [20 => ['id' => 20, 'application_id' => 10, 'status' => 1]],
        Identity::class => [30 => ['id' => 30, 'application_id' => 10, 'status' => 1, 'lifecycle_state' => 'active']],
        IdentityGroupMember::class => [40 => ['id' => 40, 'identity_group_id' => 20, 'identity_id' => 30, 'application_id' => 10, 'status' => 2]],
    ];
    $service = new IdentityGroupService();
    check($service->addMember(20, 30, 10, 'admin', 'add') === 40, 'Restore must reuse member');
    $addLocks = State::$locks;
    $service->removeMember(20, 30, 10, 'admin', 'remove');
    check($addLocks === [[IdentityGroup::class, 20], [Identity::class, 30], [IdentityGroupMember::class, 40]], 'Unexpected add lock order');
    check(State::$locks === $addLocks, 'Add and remove lock the same rows in different orders');
    check(State::$rows[IdentityGroupMember::class][40]['status'] === 2 && count(State::$events) === 2 && count(State::$audits) === 2, 'Removal lost state, event or audit');
    check(State::$events[1] === [10, 30, 'identity.updated', ['groups'], 'remove'], 'Removal event lost scope or group-change context');

    foreach (['event', 'audit'] as $failure) {
        foreach (['add', 'remove'] as $operation) {
            State::$rows[IdentityGroupMember::class][40]['status'] = $operation === 'add' ? 2 : 1;
            $before = [State::$rows, State::$events, State::$audits];
            State::$failure = $failure;
            try {
                if ($operation === 'add') $service->addMember(20, 30, 10, 'admin', 'failed-add');
                else $service->removeMember(20, 30, 10, 'admin', 'failed-remove');
                throw new \RuntimeException('Failure ignored');
            } catch (\RuntimeException $error) { check($error->getMessage() === $failure . ' failed', $error->getMessage()); }
            check([State::$rows, State::$events, State::$audits] === $before && State::$before === null, 'Partial membership change after failure');
            State::$failure = '';
        }
    }
    State::$rows[IdentityGroup::class][20]['status'] = 2;
    State::$rows[Identity::class][30]['status'] = 2;
    $service->removeMember(20, 30, 10, 'admin', 'disabled-remove');
    check(State::$rows[IdentityGroupMember::class][40]['status'] === 2, 'Removal from disabled group/identity regressed');
    foreach (['group', 'identity', 'member', 'inactive-member'] as $case) {
        $baseline = State::$rows;
        State::$rows[IdentityGroupMember::class][40]['status'] = 1;
        if ($case === 'group') State::$rows[IdentityGroup::class][20]['application_id'] = 11;
        if ($case === 'identity') State::$rows[Identity::class][30]['application_id'] = 11;
        if ($case === 'member') State::$rows[IdentityGroupMember::class][40]['application_id'] = 11;
        if ($case === 'inactive-member') State::$rows[IdentityGroupMember::class][40]['status'] = 2;
        $before = [State::$rows, State::$events, State::$audits];
        try { $service->removeMember(20, 30, 10, 'admin', 'wrong-scope'); throw new \RuntimeException('Invalid membership accepted'); }
        catch (ApiException $error) { check($error->getCode() === 404, 'Wrong membership rejection'); }
        check([State::$rows, State::$events, State::$audits] === $before, 'Rejected removal changed state');
        State::$rows = $baseline;
    }
    State::$rows[IdentityGroup::class][20]['status'] = 1;
    State::$rows[Identity::class][30]['status'] = 1;
    check($service->addMember(20, 30, 10, 'admin', 'recover') === 40 && count(State::$rows[IdentityGroupMember::class]) === 1, 'Recovery duplicated membership');
    echo "identity group membership lock and rollback non-PG behavior PASS\n";
}
