<?php
declare(strict_types=1);

namespace support {
    class Request {
        public static int $admin = 2;
        public function __construct(private array $inputs = []) {}
        public function input(string $key, mixed $default = null): mixed { return $this->inputs[$key] ?? $default; }
        public function header(string $key, mixed $default = null): mixed { return ['id' => self::$admin]; }
    }
    class Response { public function __construct(public array $data) {} }
}
namespace plugin\sandadmin\basic {
    class BaseController { public function success(array $data): \support\Response { return new \support\Response($data); } }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\service {
    class AuditWriter {
        public static array $events = [];
        public function write(mixed ...$args): void { self::$events[] = $args; }
    }
    class RequestId { public static function fromRequestCached(mixed $request): string { return 'offline'; } }
}
namespace MachineAccessTest {
    class Query {
        private ?array $fields = null;
        public function __construct(private array $rows) {}
        public function where(string $key, mixed $value): self {
            $this->rows = array_values(array_filter($this->rows, fn ($row) => $row[$key] == $value));
            return $this;
        }
        public function whereIn(string $key, array $values): self {
            $this->rows = array_values(array_filter($this->rows, fn ($row) => in_array($row[$key], $values, true)));
            return $this;
        }
        public function whereRaw(string $condition): self {
            if ($condition !== '1 = 0') throw new \RuntimeException('unexpected condition');
            $this->rows = [];
            return $this;
        }
        public function column(string $key): array { return array_column($this->rows, $key); }
        public function find(): ?object { return $this->rows === [] ? null : (object) $this->rows[0]; }
        public function field(string $fields): self { $this->fields = explode(',', $fields); return $this; }
        public function order(string $key, string $direction): self {
            usort($this->rows, fn ($a, $b) => $a[$key] <=> $b[$key]); return $this;
        }
        public function whereLike(string $key, string $value): self {
            $this->rows = array_values(array_filter($this->rows, fn ($row) => str_contains($row[$key], trim($value, '%')))); return $this;
        }
        public function paginate(array $options): object {
            $rows = array_slice($this->rows, ($options['page'] - 1) * $options['list_rows'], $options['list_rows']);
            if ($this->fields !== null) $rows = array_map(fn ($row) => array_intersect_key($row, array_flip($this->fields)), $rows);
            return new class(['data' => $rows, 'total' => count($this->rows), ...$options]) {
                public function __construct(private array $data) {}
                public function toArray(): array { return $this->data; }
            };
        }
    }
    class Model {
        public static array $rows = [];
        public static function where(string $key, mixed $value): Query { return (new Query(static::$rows))->where($key, $value); }
        public static function whereIn(string $key, array $values): Query { return (new Query(static::$rows))->whereIn($key, $values); }
        public static function find(int $id): ?object { return static::where('id', $id)->find(); }
    }
}
namespace plugin\SandIam\app\model {
    class Application extends \MachineAccessTest\Model { public static array $rows = [
        ['id' => 11, 'organization_id' => 1, 'status' => 1],
        ['id' => 12, 'organization_id' => 1, 'status' => 1],
        ['id' => 21, 'organization_id' => 2, 'status' => 1]
    ]; }
    class Organization extends \MachineAccessTest\Model { public static array $rows = [['id' => 1, 'status' => 1], ['id' => 2, 'status' => 1]]; }
    class Environment extends \MachineAccessTest\Model { public static array $rows = [
        ['id' => 111, 'application_id' => 11], ['id' => 121, 'application_id' => 12], ['id' => 211, 'application_id' => 21],
        ['id' => 999, 'application_id' => 999]
    ]; }
    class WorkloadClient extends \MachineAccessTest\Model { public static array $rows = [
        ['id' => 1, 'environment_id' => 111], ['id' => 2, 'environment_id' => 121], ['id' => 3, 'environment_id' => 211],
        ['id' => 4, 'environment_id' => 999], ['id' => 5, 'environment_id' => 998]
    ]; }
    class AdminApplicationGrant extends \MachineAccessTest\Model { public static array $rows = [['admin_user_id' => 2, 'application_id' => 11, 'status' => 1]]; }
    class AdminOrganizationGrant extends \MachineAccessTest\Model { public static array $rows = []; }
}
namespace {
    function request(): \support\Request { return new \support\Request(); }
    $base = dirname(__DIR__) . '/app/';
    require $base . 'admin/support/AdminOrganizationAccess.php';
    require $base . 'admin/support/AdminResourceController.php';
    require $base . 'admin/controller/CredentialController.php';
    require $base . 'admin/controller/ServiceGrantController.php';
    function invoke(object $controller, string $method, mixed ...$args): mixed {
        return (new \ReflectionMethod($controller, $method))->invoke($controller, ...$args);
    }
    function assertAccess(object $controller, int $id, bool $allowed): void {
        $methods = $controller instanceof \plugin\SandIam\app\admin\controller\CredentialController
            ? ['assertClientAccess' => [$id]]
            : ['assertModelAccess' => [(object) ['workload_client_id' => $id]], 'assertPayloadAccess' => [['workload_client_id' => $id]]];
        foreach ($methods as $method => $arguments) {
            try { invoke($controller, $method, ...$arguments); $actual = true; }
            catch (\plugin\sandadmin\exception\ApiException $error) { $actual = false; }
            if ($actual !== $allowed) throw new \RuntimeException(get_class($controller) . " {$method} client {$id}: expected " . ($allowed ? 'allow' : 'deny'));
        }
    }
    $controllers = [new \plugin\SandIam\app\admin\controller\CredentialController(), new \plugin\SandIam\app\admin\controller\ServiceGrantController()];
    foreach ($controllers as $controller) {
        assertAccess($controller, 1, true);
        foreach ([2, 3, 4, 5, 999] as $id) assertAccess($controller, $id, false);
    }
    function checkScope(array $controllers, array $expected): void {
        foreach ($controllers as $controller) {
            $query = new \MachineAccessTest\Query([
                ['id' => 1, 'workload_client_id' => 1], ['id' => 2, 'workload_client_id' => 2], ['id' => 3, 'workload_client_id' => 3]
            ]);
            $method = $controller instanceof \plugin\SandIam\app\admin\controller\CredentialController ? 'scopeCredentials' : 'scopeIndexToOrganizations';
            invoke($controller, $method, $query);
            if ($query->column('id') !== $expected) throw new \RuntimeException('Scope differs from active application grants');
        }
    }
    checkScope($controllers, [1]);
    // Revoked application grants and inactive applications/organizations stay excluded.
    \plugin\SandIam\app\model\AdminApplicationGrant::$rows[0]['status'] = 2;
    checkScope($controllers, []);
    foreach ($controllers as $controller) assertAccess($controller, 1, false);
    \plugin\SandIam\app\model\AdminApplicationGrant::$rows[0]['status'] = 1;
    \plugin\SandIam\app\model\Application::$rows[0]['status'] = 2;
    checkScope($controllers, []);
    foreach ($controllers as $controller) assertAccess($controller, 1, false);
    \plugin\SandIam\app\model\Application::$rows[0]['status'] = 1;
    \plugin\SandIam\app\model\Organization::$rows[0]['status'] = 2;
    checkScope($controllers, []);
    foreach ($controllers as $controller) assertAccess($controller, 1, false);
    \plugin\SandIam\app\model\Organization::$rows[0]['status'] = 1;
    \plugin\SandIam\app\model\AdminApplicationGrant::$rows = [];
    checkScope($controllers, []);
    \plugin\SandIam\app\model\AdminOrganizationGrant::$rows = [['admin_user_id' => 2, 'organization_id' => 1, 'status' => 1]];
    checkScope($controllers, [1, 2]);
    foreach ($controllers as $controller) {
        assertAccess($controller, 1, true);
        assertAccess($controller, 2, true);
        assertAccess($controller, 3, false);
    }
    \support\Request::$admin = 1;
    checkScope($controllers, [1, 2, 3]);
    foreach ($controllers as $controller) {
        foreach ([1, 2, 3] as $id) assertAccess($controller, $id, true);
        foreach ([4, 5, 999] as $id) assertAccess($controller, $id, false);
    }
    echo "machine application access non-PG behavior PASS\n";
}
