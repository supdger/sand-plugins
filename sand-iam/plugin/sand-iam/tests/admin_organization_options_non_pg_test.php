<?php
declare(strict_types=1);

namespace plugin\sandadmin\app\model\system {
    final class SystemUser {
        public static array $rows = [];
        public function __construct(public int $id, public string $username, public string $realname) {}
        public static function where(string $key, mixed $value): \OrganizationOptionsTest\Query {
            return (new \OrganizationOptionsTest\Query(self::$rows))->where($key, $value);
        }
    }
}
namespace OrganizationOptionsTest {
    final class Query {
        private array $matches = [];
        private int $maximum = 20;
        public function __construct(private array $rows) {}
        public function where(string|\Closure $key, mixed $value = null): self {
            if ($key instanceof \Closure) {
                $scope = new self($this->rows);
                $key($scope);
                $this->rows = $scope->matches;
            } else {
                $this->rows = array_values(array_filter($this->rows, fn ($row) => $row[$key] === $value));
            }
            return $this;
        }
        public function whereLike(string $key, string $pattern): self {
            $this->matches = array_values(array_filter($this->rows, fn ($row) => str_contains($row[$key], trim($pattern, '%'))));
            return $this;
        }
        public function whereOr(string $key, string $operator, string $pattern): self {
            foreach ($this->rows as $row) {
                if (str_contains($row[$key], trim($pattern, '%')) && !in_array($row, $this->matches, true)) $this->matches[] = $row;
            }
            return $this;
        }
        public function field(array $fields): self {
            if ($fields !== ['id', 'username', 'realname']) throw new \RuntimeException('unexpected selected fields');
            return $this;
        }
        public function order(string $key): self { usort($this->rows, fn ($a, $b) => $a[$key] <=> $b[$key]); return $this; }
        public function limit(int $limit): self { $this->maximum = $limit; return $this; }
        public function select(): self { return $this; }
        public function all(): array {
            return array_map(fn ($row) => new \plugin\sandadmin\app\model\system\SystemUser($row['id'], $row['username'], $row['realname']), array_slice($this->rows, 0, $this->maximum));
        }
    }
}
namespace {
    require __DIR__ . '/machine_application_access_non_pg_test.php';
    require dirname(__DIR__) . '/app/admin/controller/AdminOrganizationGrantController.php';
    use plugin\sandadmin\app\model\system\SystemUser;
    use plugin\SandIam\app\admin\controller\AdminOrganizationGrantController;
    use support\Request;

    function optionsCheck(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException($message);
    }
    for ($id = 1; $id <= 25; $id++) SystemUser::$rows[] = [
        'id' => $id, 'username' => 'admin' . $id, 'realname' => '管理员' . $id, 'status' => 1, 'password' => 'not-returned'
    ];
    SystemUser::$rows[] = ['id' => 99, 'username' => 'disabled', 'realname' => '停用', 'status' => 0];
    Request::$admin = 1;
    $controller = new AdminOrganizationGrantController();
    optionsCheck($controller->adminOptions(new Request(['keywords' => 'a']))->data === [], 'short search must be empty');
    $rows = $controller->adminOptions(new Request(['keywords' => 'admin', 'limit' => 999]))->data;
    optionsCheck(count($rows) === 20, 'bounded results');
    optionsCheck($rows[0] === ['id' => 1, 'name' => '管理员1', 'username' => 'admin1'], 'minimum projection');
    optionsCheck(count($controller->adminOptions(new Request(['keywords' => '管理员']))->data) === 20, 'realname search');
    optionsCheck($controller->adminOptions(new Request(['id' => 25]))->data[0]['id'] === 25, 'exact id beyond first page');
    optionsCheck($controller->adminOptions(new Request(['id' => 99]))->data === [], 'disabled excluded');
    optionsCheck($controller->adminOptions(new Request(['id' => 999]))->data === [], 'missing excluded');
    foreach ([2, 3] as $admin) {
        Request::$admin = $admin;
        try {
            (new AdminOrganizationGrantController())->adminOptions(new Request(['id' => 1]));
            throw new RuntimeException('non-super allowed');
        } catch (\plugin\sandadmin\exception\ApiException $error) {
            optionsCheck($error->getCode() === 403, 'non-super must fail with 403');
        }
    }
    $permission = (new ReflectionMethod(AdminOrganizationGrantController::class, 'adminOptions'))->getAttributes()[0]->getArguments();
    optionsCheck($permission[1] === 'sand_iam:admin_organization_grant:save', 'exact save permission');
    echo "Organization admin options PASS (real controller/access, offline ORM)\n";
}
