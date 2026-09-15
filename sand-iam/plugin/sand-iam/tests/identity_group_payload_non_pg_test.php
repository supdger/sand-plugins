<?php

declare(strict_types=1);

namespace plugin\sandadmin\basic {
    class BaseController {}
}

namespace plugin\SandIam\app\model {
    final class IdentityGroup
    {
        /** @var array<int,self> */ public static array $rows = [];
        /** @var list<int> */ public static array $lookups = [];
        public function __construct(
            public int $id,
            public ?int $parent_id,
            public string $name,
            public int $application_id = 7,
            public string $code = 'group',
            public string $description = 'Description',
            public int $status = 2,
            public int $depth = 1,
        ) {}
        public static function find(int $id): ?self
        {
            self::$lookups[] = $id;
            return self::$rows[$id] ?? null;
        }
    }

    final class IdentityGroupMember
    {
        /** @var list<array{identity_group_id:int,status:int}> */
        public static array $rows = [
            ['identity_group_id' => 3, 'status' => 1],
            ['identity_group_id' => 3, 'status' => 2],
            ['identity_group_id' => 4, 'status' => 1],
        ];
        public static function where(string $field, int $value): MemberQuery
        {
            return (new MemberQuery())->where($field, $value);
        }
    }

    final class MemberQuery
    {
        /** @var array<string,int> */ private array $conditions = [];
        public function where(string $field, int $value): self
        {
            $this->conditions[$field] = $value;
            return $this;
        }
        public function count(): int
        {
            return count(array_filter(IdentityGroupMember::$rows, function (array $row): bool {
                foreach ($this->conditions as $field => $value) {
                    if ($row[$field] !== $value) return false;
                }
                return true;
            }));
        }
    }
}

namespace {
    use plugin\SandIam\app\admin\controller\IdentityGroupController;
    use plugin\SandIam\app\model\IdentityGroup;

    require dirname(__DIR__) . '/app/admin/controller/IdentityGroupController.php';
    $controller = new IdentityGroupController();
    $payload = new ReflectionMethod($controller, 'payload');
    $checks = 0;
    $expect = static function (mixed $actual, mixed $expected, string $label) use (&$checks): void {
        if ($actual !== $expected) throw new RuntimeException($label);
        $checks++;
    };
    IdentityGroup::$rows = [
        1 => new IdentityGroup(1, null, 'Same name'),
        2 => new IdentityGroup(2, null, 'Same name'),
    ];
    $root = $payload->invoke($controller, IdentityGroup::$rows[1]);
    $expect($root['parent_id'], null, 'Root parent must be null');
    $expect($root['parent_name'], '', 'Root parent label remains empty');
    $expect(IdentityGroup::$lookups, [], 'Root must not query a parent');
    $child = $payload->invoke($controller, new IdentityGroup(3, 2, 'Child'));
    $expect($child['parent_id'], 2, 'Parent must use stored ID despite duplicate names');
    $expect(IdentityGroup::$lookups, [2], 'Parent lookup must use exact ID');
    $expect($child['parent_name'], 'Same name', 'Existing parent label remains compatible');
    $expect($child['description'], 'Description', 'Description preserved');
    $expect($child['status'], 2, 'Disabled state preserved');
    $expect($child['member_count'], 1, 'Only active members of this group counted');
    $missing = $payload->invoke($controller, new IdentityGroup(4, 99, 'Missing parent'));
    $expect($missing['parent_id'], 99, 'Missing referenced parent must not erase stored ID');
    $expect($missing['parent_name'], '', 'Missing parent retains empty compatible label');
    echo "Identity group payload non-PG PASS ({$checks} checks)\n";
}
