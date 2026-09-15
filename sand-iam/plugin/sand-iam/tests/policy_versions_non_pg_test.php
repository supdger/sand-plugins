<?php
declare(strict_types=1);
namespace {
    require __DIR__ . '/machine_application_access_non_pg_test.php';
}
namespace PolicyVersionsTest {
    class Request extends \support\Request {
        public function post(string $key, mixed $default = null): mixed { return $default; }
    }
    class Query {
        private array $fields = [];
        public function __construct(private array $rows) {}
        public function field(string $fields): self { $this->fields = explode(',', $fields); return $this; }
        public function order(string $key, string $direction): self {
            if ($key !== 'version_no' || $direction !== 'desc') throw new \RuntimeException('Version ordering changed');
            usort($this->rows, fn ($a, $b) => $b[$key] <=> $a[$key]); return $this;
        }
        public function paginate(array $options): object {
            $rows = array_slice($this->rows, ($options['page'] - 1) * $options['list_rows'], $options['list_rows']);
            $rows = array_map(fn ($row) => array_intersect_key($row, array_flip($this->fields)), $rows);
            return new class(['data' => $rows, 'total' => count($this->rows), 'current_page' => $options['page'], 'per_page' => $options['list_rows']]) {
                public function __construct(private array $data) {}
                public function toArray(): array { return $this->data; }
            };
        }
    }
}
namespace plugin\SandIam\app\model {
    class Policy {
        public function __construct(public int $id, public int $application_id, public ?int $published_version_id) {}
        public static function findOrEmpty(int $id): self {
            return new self($id, match ($id) { 1, 4 => 11, 2 => 12, 3 => 21, default => 0 }, $id === 4 ? null : 102);
        }
        public function isEmpty(): bool { return $this->application_id === 0; }
    }
    class PolicyVersion {
        public static int $queries = 0;
        public static function where(string $key, mixed $value): \PolicyVersionsTest\Query {
            self::$queries++;
            if ($key !== 'policy_id') throw new \RuntimeException('Policy scope missing');
            $rows = [
                ['id' => 101, 'policy_id' => 1, 'version_no' => 1, 'operation' => 'publish', 'rollback_of_version_id' => null, 'create_time' => '2026-09-01', 'snapshot' => ['private' => true]],
                ['id' => 102, 'policy_id' => 1, 'version_no' => 2, 'operation' => 'rollback', 'rollback_of_version_id' => 101, 'create_time' => '2026-09-02', 'request_fingerprint' => 'private'],
                ['id' => 201, 'policy_id' => 2, 'version_no' => 1, 'operation' => 'publish'],
            ];
            return new \PolicyVersionsTest\Query(array_values(array_filter($rows, fn ($row) => $row[$key] === $value)));
        }
    }
}
namespace {
    require dirname(__DIR__) . '/app/admin/support/ApplicationResourceController.php';
    require dirname(__DIR__) . '/app/admin/controller/PolicyController.php';
    \support\Request::$admin = 2;
    \plugin\SandIam\app\model\AdminOrganizationGrant::$rows = [];
    \plugin\SandIam\app\model\AdminApplicationGrant::$rows = [['admin_user_id' => 2, 'application_id' => 11, 'status' => 1]];
    $controller = new \plugin\SandIam\app\admin\controller\PolicyController();
    $page = $controller->versions(new \PolicyVersionsTest\Request(['id' => 1, 'page' => 2, 'limit' => 1]))->data;
    if (array_column($page['data'], 'id') !== [101] || $page['total'] !== 2 || $page['current_page'] !== 2 || $page['per_page'] !== 1 || $page['published_version_id'] !== 102) throw new \RuntimeException('Pagination or published pointer mismatch');
    if (array_keys($page['data'][0]) !== ['id', 'version_no', 'operation', 'rollback_of_version_id', 'create_time']) throw new \RuntimeException('Projection exposes unrequested fields');
    $first = $controller->versions(new \PolicyVersionsTest\Request(['id' => 1, 'limit' => 999]))->data;
    if (array_column($first['data'], 'id') !== [102, 101] || $first['per_page'] !== 100) throw new \RuntimeException('Ordering or limit mismatch');
    $empty = $controller->versions(new \PolicyVersionsTest\Request(['id' => 4]))->data;
    if ($empty['data'] !== [] || $empty['published_version_id'] !== null) throw new \RuntimeException('Draft without versions mismatch');
    foreach ([0, 2, 3, 999] as $id) {
        $before = \plugin\SandIam\app\model\PolicyVersion::$queries;
        try {
            $controller->versions(new \PolicyVersionsTest\Request(['id' => $id, 'application_id' => 11]));
            throw new \RuntimeException('Unauthorized policy accepted');
        } catch (\plugin\sandadmin\exception\ApiException $error) {}
        if (\plugin\SandIam\app\model\PolicyVersion::$queries !== $before) throw new \RuntimeException('Versions queried before access check');
    }
    \plugin\SandIam\app\model\AdminApplicationGrant::$rows = [];
    try {
        $controller->versions(new \PolicyVersionsTest\Request(['id' => 1]));
        throw new \RuntimeException('Revoked delegation accepted');
    } catch (\plugin\sandadmin\exception\ApiException $error) {}
    echo "policy versions non-PG behavior PASS\n";
}
