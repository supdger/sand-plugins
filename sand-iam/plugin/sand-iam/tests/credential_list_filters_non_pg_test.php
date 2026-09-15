<?php
declare(strict_types=1);

namespace support {
    final class Request {
        public function __construct(private array $inputs = []) {}
        public function input(string $key, mixed $default = null): mixed { return $this->inputs[$key] ?? $default; }
        public function header(string $key, mixed $default = null): mixed { return ['id' => 1]; }
    }
    final class Response { public function __construct(public array $data) {} }
}
namespace plugin\sandadmin\basic {
    class BaseController {
        public function success(array $data): \support\Response { return new \support\Response($data); }
    }
}
namespace plugin\SandIam\app\admin\support {
    final class AdminOrganizationAccess {
        public static bool $super = true;
        public static array $organizations = [1];
        public function __construct(int $id, mixed $unused) {}
        public function isSuperAdmin(): bool { return self::$super; }
        public function organizationIds(): array { return self::$organizations; }
        public function applicationIds(): array {
            return \plugin\SandIam\app\model\Application::whereIn('organization_id', self::$organizations)->column('id');
        }
    }
}
namespace CredentialListTest {
    final class Query {
        public function __construct(private array $rows) {}
        public function where(string $key, mixed $value): self {
            $this->rows = array_values(array_filter($this->rows, fn ($row) => $row[$key] == $value));
            return $this;
        }
        public function whereIn(string $key, array $values): self {
            $this->rows = array_values(array_filter($this->rows, fn ($row) => in_array($row[$key], $values, true)));
            return $this;
        }
        public function whereRaw(string $expression): self {
            if ($expression !== '1 = 0') throw new \RuntimeException('Unexpected raw condition');
            $this->rows = [];
            return $this;
        }
        public function column(string $key): array { return array_column($this->rows, $key); }
        public function paginate(array $options): object {
            $total = count($this->rows);
            $rows = array_slice($this->rows, ($options['page'] - 1) * $options['list_rows'], $options['list_rows']);
            return new class($rows, $total, $options) {
                public function __construct(private array $rows, private int $total, private array $options) {}
                public function toArray(): array { return ['data' => $this->rows, 'total' => $this->total, ...$this->options]; }
            };
        }
    }
    class Model {
        public static array $records = [];
        public static function where(string $key, mixed $value): Query { return (new Query(static::$records))->where($key, $value); }
        public static function whereIn(string $key, array $values): Query { return (new Query(static::$records))->whereIn($key, $values); }
        public static function order(string $key, string $direction): Query {
            $rows = static::$records;
            usort($rows, fn ($a, $b) => $b[$key] <=> $a[$key]);
            return new Query($rows);
        }
    }
}
namespace plugin\SandIam\app\model {
    class Application extends \CredentialListTest\Model { public static array $records = [
        ['id' => 11, 'organization_id' => 1], ['id' => 12, 'organization_id' => 1], ['id' => 21, 'organization_id' => 2]
    ]; }
    class Environment extends \CredentialListTest\Model { public static array $records = [
        ['id' => 111, 'application_id' => 11], ['id' => 121, 'application_id' => 12], ['id' => 211, 'application_id' => 21]
    ]; }
    class WorkloadClient extends \CredentialListTest\Model { public static array $records = [
        ['id' => 1111, 'environment_id' => 111], ['id' => 1211, 'environment_id' => 121], ['id' => 2111, 'environment_id' => 211]
    ]; }
    class Credential extends \CredentialListTest\Model { public static array $records = [
        ['id' => 1, 'workload_client_id' => 1111], ['id' => 2, 'workload_client_id' => 1211], ['id' => 3, 'workload_client_id' => 2111]
    ]; }
}
namespace {
    function request(): \support\Request { return new \support\Request(); }
    require dirname(__DIR__) . '/app/admin/controller/CredentialController.php';
    function check(array $params, array $expected): void {
        $response = (new \plugin\SandIam\app\admin\controller\CredentialController())->index(new \support\Request($params));
        $actual = array_column($response->data['data'], 'id');
        if ($actual !== $expected) throw new \RuntimeException(json_encode([$params, 'expected' => $expected, 'actual' => $actual]));
    }
    check(['organization_id' => 1], [2, 1]);
    check(['application_id' => 11], [1]);
    check(['environment_id' => 121], [2]);
    check(['workload_client_id' => 2111], [3]);
    check([], [3, 2, 1]);
    check(['organization_id' => 1, 'application_id' => 11, 'environment_id' => 111, 'workload_client_id' => 1111], [1]);
    check(['organization_id' => 1, 'application_id' => 21], []);
    check(['application_id' => 11, 'environment_id' => 211], []);
    check(['environment_id' => 111, 'workload_client_id' => 2111], []);
    foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $key) {
        check([$key => 99999], []);
    }
    \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$super = false;
    check([], [2, 1]);
    check(['organization_id' => 2], []);
    check(['application_id' => 21], []);
    check(['environment_id' => 211], []);
    check(['workload_client_id' => 2111], []);
    check(['application_id' => 11], [1]);
    \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$organizations = [];
    check([], []);
    check(['organization_id' => 1], []);
    \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$super = true;
    check(['page' => 2, 'limit' => 1], [2]);
    $page = (new \plugin\SandIam\app\admin\controller\CredentialController())->index(
        new \support\Request(['page' => 0, 'limit' => 200])
    )->data;
    if ($page['page'] !== 1 || $page['list_rows'] !== 100 || $page['total'] !== 3) {
        throw new \RuntimeException('Pagination bounds changed');
    }
    echo "credential list filters non-PG behavior PASS\n";
}
