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
        public static array $applications = [11];
        public function __construct(int $id, mixed $token) {}
        public function isSuperAdmin(): bool { return self::$super; }
        public function organizationIds(): array { return self::$organizations; }
        public function applicationIds(): array { return self::$applications; }
    }
}
namespace MachineFilterTest {
    final class Query {
        private ?array $fields = null;
        public static array $selectedIds = [];
        public function __construct(private array $rows) {}
        public function where(string $key, mixed $value): self {
            $this->rows = array_values(array_filter($this->rows, fn ($row) => $row[$key] == $value));
            return $this;
        }
        public function whereIn(string $key, array $values): self {
            $this->rows = array_values(array_filter($this->rows, fn ($row) => in_array($row[$key], $values, true)));
            return $this;
        }
        public function whereLike(string $key, string $value): self {
            $this->rows = array_values(array_filter($this->rows, fn ($row) => str_contains($row[$key], trim($value, '%'))));
            return $this;
        }
        public function whereRaw(string $expression): self {
            if ($expression !== '1 = 0') throw new \RuntimeException('Unexpected raw condition');
            $this->rows = [];
            return $this;
        }
        public function column(string $key): array { return array_column($this->rows, $key); }
        public function field(string $fields): self { $this->fields = explode(',', $fields); return $this; }
        public function select(): object {
            self::$selectedIds[] = array_column($this->rows, 'id');
            $rows = $this->fields === null ? $this->rows : array_map(fn ($row) => array_intersect_key($row, array_flip($this->fields)), $this->rows);
            return new class($rows) {
                public function __construct(private array $rows) {}
                public function toArray(): array { return $this->rows; }
            };
        }
        public function paginate(array $options): object {
            $data = ['data' => array_slice($this->rows, ($options['page'] - 1) * $options['list_rows'], $options['list_rows']), 'total' => count($this->rows), ...$options];
            return new class($data) {
                public function __construct(private array $data) {}
                public function toArray(): array { return $this->data; }
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
    class ServiceAction extends \MachineFilterTest\Model { public static array $records = []; }
    class Service extends \MachineFilterTest\Model { public static array $records = []; }
    class Application extends \MachineFilterTest\Model { public static array $records = [
        ['id' => 11, 'organization_id' => 1], ['id' => 12, 'organization_id' => 1], ['id' => 21, 'organization_id' => 2]
    ]; }
    class Environment extends \MachineFilterTest\Model { public static array $records = [
        ['id' => 111, 'application_id' => 11], ['id' => 121, 'application_id' => 12], ['id' => 211, 'application_id' => 21]
    ]; }
    class WorkloadClient extends \MachineFilterTest\Model { public static array $records = [
        ['id' => 1, 'environment_id' => 111, 'status' => 1, 'name' => 'alpha'],
        ['id' => 2, 'environment_id' => 121, 'status' => 2, 'name' => 'beta'],
        ['id' => 3, 'environment_id' => 211, 'status' => 1, 'name' => 'gamma']
    ]; }
    class ServiceGrant extends \MachineFilterTest\Model { public static array $records = [
        ['id' => 1, 'workload_client_id' => 1, 'status' => 1],
        ['id' => 2, 'workload_client_id' => 2, 'status' => 2],
        ['id' => 3, 'workload_client_id' => 3, 'status' => 1]
    ]; }
}
namespace {
    function request(): \support\Request { return new \support\Request(); }
    $root = dirname(__DIR__) . '/app/admin/';
    require $root . 'support/AdminResourceController.php';
    require $root . 'support/EnvironmentResourceController.php';
    require $root . 'controller/WorkloadClientController.php';
    require $root . 'controller/ServiceGrantController.php';
    function check(string $controller, array $params, array $expected): void {
        $response = (new $controller())->index(new \support\Request($params));
        $actual = array_column($response->data['data'], 'id');
        if ($actual !== $expected) throw new \RuntimeException(json_encode([$controller, $params, 'expected' => $expected, 'actual' => $actual]));
    }
    $controllers = [\plugin\SandIam\app\admin\controller\WorkloadClientController::class, \plugin\SandIam\app\admin\controller\ServiceGrantController::class];
    foreach ($controllers as $controller) {
        check($controller, ['organization_id' => 1], [2, 1]);
        check($controller, ['application_id' => 11], [1]);
        check($controller, ['environment_id' => 121], [2]);
        check($controller, [], [3, 2, 1]);
        check($controller, ['organization_id' => 1, 'application_id' => 11, 'environment_id' => 111], [1]);
        check($controller, ['organization_id' => 1, 'application_id' => 21], []);
        check($controller, ['application_id' => 11, 'environment_id' => 211], []);
        check($controller, ['organization_id' => 1, 'status' => 1], [1]);
        foreach (['organization_id', 'application_id', 'environment_id'] as $filter) {
            check($controller, [$filter => 9999], []);
        }
        check($controller, ['page' => 2, 'limit' => 1], [2]);
        $page = (new $controller())->index(new \support\Request(['page' => 0, 'limit' => 200]))->data;
        if ($page['page'] !== 1 || $page['list_rows'] !== 100 || $page['total'] !== 3) {
            throw new \RuntimeException('Pagination bounds changed');
        }
    }
    [$client, $grant] = $controllers;
    check($client, ['keywords' => 'alpha'], [1]);
    check($grant, ['workload_client_id' => 2], [2]);
    check($grant, ['application_id' => 11, 'workload_client_id' => 2], []);
    \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$super = false;
    check($client, [], [1]);
    check($client, ['organization_id' => 1], [1]);
    check($client, ['application_id' => 12], []);
    check($grant, [], [1]);
    check($grant, ['application_id' => 12], []);
    foreach ($controllers as $controller) {
        check($controller, ['organization_id' => 2], []);
        check($controller, ['environment_id' => 211], []);
    }
    \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$organizations = [];
    check($grant, ['application_id' => 11], [1]);
    check($client, ['application_id' => 11], [1],);
    \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$applications = [];
    check($grant, [], []);
    check($client, [], []);
    check($client, ['organization_id' => 1], []);
    \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$super = true;
    // A resource without an override keeps only its existing direct/keyword filters.
    class DefaultResource extends \plugin\SandIam\app\admin\support\AdminResourceController {
        protected string $modelClass = \plugin\SandIam\app\model\WorkloadClient::class;
        protected array $writeFields = ['environment_id', 'status'];
        protected string $resourceType = 'test';
    }
    check(DefaultResource::class, ['organization_id' => 9999], [3, 2, 1]);
    check(DefaultResource::class, ['status' => 2], [2]);
    check(DefaultResource::class, ['environment_id' => 111, 'keywords' => 'alpha'], [1]);
    echo "machine resource filters non-PG behavior PASS\n";
}
