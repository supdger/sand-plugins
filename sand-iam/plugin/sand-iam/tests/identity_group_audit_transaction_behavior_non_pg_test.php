<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception { final class ApiException extends \RuntimeException {} }

namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    abstract class Record
    {
        /** @param array<string,mixed> $values */
        public function __construct(array $values) { foreach ($values as $key => $value) $this->{$key} = $value; }
        /** @param array<string,mixed> $values */
        public function save(array $values): void { foreach ($values as $key => $value) $this->{$key} = $value; static::$rows[(int) $this->id] = $this; }
    }

    final class Query
    {
        /** @var list<array{0:string,1:mixed}> */
        private array $conditions = [];
        /** @param array<int,object> $rows */
        public function __construct(private readonly array $rows, ?string $field = null, mixed $value = null) { if ($field !== null) $this->conditions[] = [$field, $value]; }
        public function where(string $field, mixed $value): self { $this->conditions[] = [$field, $value]; return $this; }
        public function lock(bool $lock): self { return $this; }
        public function find(): ?object { return $this->matches()[0] ?? null; }
        public function count(): int { return count($this->matches()); }
        /** @return list<object> */
        private function matches(): array
        {
            return array_values(array_filter($this->rows, function (object $row): bool {
                foreach ($this->conditions as [$field, $value]) if (($row->{$field} ?? null) !== $value) return false;
                return true;
            }));
        }
    }

    final class Application extends Record
    {
        /** @var array<int,self> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }

    final class Identity extends Record
    {
        /** @var array<int,self> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }

    final class IdentityGroup extends Record
    {
        /** @var array<int,self> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
        /** @param array<string,mixed> $values */
        public static function create(array $values): self { $id = self::$rows === [] ? 1 : max(array_keys(self::$rows)) + 1; return self::$rows[$id] = new self(['id' => $id, ...$values]); }
    }

    final class IdentityGroupMember extends Record
    {
        /** @var array<int,self> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
        /** @param array<string,mixed> $values */
        public static function create(array $values): self { $id = self::$rows === [] ? 1 : max(array_keys(self::$rows)) + 1; return self::$rows[$id] = new self(['id' => $id, ...$values]); }
    }
}

namespace plugin\SandIam\app\service {
    final class AuditWriter
    {
        public static bool $fail = true;
        public function write(string $actorType, string $actorRef, ?int $organizationId, ?int $applicationId, string $action, string $resourceType, ?int $resourceId, string $outcome, string $requestId, array $context = []): void
        {
            if (self::$fail) throw new \RuntimeException('injected audit failure');
        }
    }

    final class IdentityEventPublisher
    {
        /** @var list<string> */ public static array $events = [];
        /** @param list<string> $changedFields */
        public function publish(object $application, object $identity, string $event, array $changedFields, string $requestId): void { self::$events[] = $event; }
    }
}

namespace think\facade {
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Identity;
    use plugin\SandIam\app\model\IdentityGroup;
    use plugin\SandIam\app\model\IdentityGroupMember;
    use plugin\SandIam\app\service\IdentityEventPublisher;

    final class Db
    {
        /** @var array<string,mixed>|null */ private static ?array $snapshot = null;
        public static function startTrans(): void
        {
            self::$snapshot = unserialize(serialize([
                'applications' => Application::$rows,
                'identities' => Identity::$rows,
                'groups' => IdentityGroup::$rows,
                'members' => IdentityGroupMember::$rows,
                'events' => IdentityEventPublisher::$events,
            ]));
        }
        public static function commit(): void { self::$snapshot = null; }
        public static function rollback(): void
        {
            if (self::$snapshot === null) return;
            Application::$rows = self::$snapshot['applications'];
            Identity::$rows = self::$snapshot['identities'];
            IdentityGroup::$rows = self::$snapshot['groups'];
            IdentityGroupMember::$rows = self::$snapshot['members'];
            IdentityEventPublisher::$events = self::$snapshot['events'];
            self::$snapshot = null;
        }
    }
}

namespace {
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Identity;
    use plugin\SandIam\app\model\IdentityGroup;
    use plugin\SandIam\app\model\IdentityGroupMember;
    use plugin\SandIam\app\service\IdentityEventPublisher;
    use plugin\SandIam\app\service\IdentityGroupService;

    function groupAuditAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    function groupAuditExpect(callable $operation, string $message): void
    {
        try { $operation(); } catch (RuntimeException $exception) { groupAuditAssert($exception->getMessage() === 'injected audit failure', $message . ' must expose the audit fault'); return; }
        throw new RuntimeException($message . ' unexpectedly succeeded');
    }
    function groupAuditReset(bool $member = false): void
    {
        Application::$rows = [1 => new Application(['id' => 1, 'organization_id' => 9, 'status' => 1])];
        Identity::$rows = [20 => new Identity(['id' => 20, 'application_id' => 1, 'status' => 1, 'lifecycle_state' => 'active'])];
        IdentityGroup::$rows = [10 => new IdentityGroup(['id' => 10, 'application_id' => 1, 'parent_id' => null, 'code' => 'team-a', 'name' => 'Before', 'description' => '', 'depth' => 1, 'status' => 1])];
        IdentityGroupMember::$rows = $member ? [30 => new IdentityGroupMember(['id' => 30, 'identity_group_id' => 10, 'identity_id' => 20, 'application_id' => 1, 'status' => 1])] : [];
        IdentityEventPublisher::$events = [];
    }

    require dirname(__DIR__) . '/app/service/IdentityGroupService.php';

    groupAuditReset();
    groupAuditExpect(static fn () => (new IdentityGroupService())->create(1, 'team-b', 'Created', null, '', '7', 'group-create-0001'), 'create');
    groupAuditAssert(count(IdentityGroup::$rows) === 1 && !isset(IdentityGroup::$rows[11]), 'create audit failure must leave no new group');

    groupAuditReset();
    groupAuditExpect(static fn () => (new IdentityGroupService())->update(10, 1, 'After', null, 'changed', 1, '7', 'group-update-0002'), 'update');
    groupAuditAssert(IdentityGroup::$rows[10]->name === 'Before' && IdentityGroup::$rows[10]->description === '', 'update audit failure must restore the original group');

    groupAuditReset();
    groupAuditExpect(static fn () => (new IdentityGroupService())->addMember(10, 20, 1, '7', 'group-add-0003'), 'member add');
    groupAuditAssert(IdentityGroupMember::$rows === [] && IdentityEventPublisher::$events === [], 'member-add audit failure must leave neither membership nor event');

    groupAuditReset(true);
    groupAuditExpect(static fn () => (new IdentityGroupService())->removeMember(10, 20, 1, '7', 'group-remove-0004'), 'member removal');
    groupAuditAssert((int) IdentityGroupMember::$rows[30]->status === 1 && IdentityEventPublisher::$events === [], 'member-remove audit failure must restore membership and event state');

    echo "identity group audit transaction behavior non-PG test passed\n";
}
