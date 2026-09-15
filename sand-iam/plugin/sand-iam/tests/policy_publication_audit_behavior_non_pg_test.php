<?php
declare(strict_types=1);

namespace PolicyPublicationTest {
    final class Store {
        public static array $policies = [];
        public static array $versions = [];
        public static array $audits = [];
        public static ?array $before = null;
    }
    final class Query {
        private array $filters = [];
        public function __construct(private string $kind) {}
        public function where(string $key, mixed $value): self { $this->filters[$key] = $value; return $this; }
        public function lock(bool $lock): self {
            if (!$lock || Store::$before === null) throw new \RuntimeException('Lock outside transaction');
            return $this;
        }
        private function rows(): array {
            $rows = $this->kind === 'policy' ? Store::$policies : Store::$versions;
            return array_filter($rows, function (array $row): bool {
                foreach ($this->filters as $key => $value) if (($row[$key] ?? null) !== $value) return false;
                return true;
            });
        }
        public function find(): ?object {
            $rows = $this->rows();
            if ($rows === []) return null;
            $class = $this->kind === 'policy' ? \plugin\SandIam\app\model\Policy::class : \plugin\SandIam\app\model\PolicyVersion::class;
            return new $class(reset($rows));
        }
        public function max(string $key): int { return (int) max([0, ...array_column($this->rows(), $key)]); }
    }
    function check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace support {
    class Request {
        public function __construct(private int $id = 1) {}
        public function post(?string $key = null, mixed $default = null): mixed { return $key === null ? ['id' => $this->id] : ($key === 'id' ? $this->id : $default); }
        public function input(string $key, mixed $default = null): mixed { return $this->post($key, $default); }
        public function header(string $key, mixed $default = null): mixed { return $key === 'X-Request-Id' ? 'policy-state-request' : $default; }
    }
    class Response { public function __construct(public mixed $data = null) {} }
}
namespace plugin\sandadmin\basic {
    class BaseController {
        protected function success(mixed $data = null, string $message = ''): \support\Response { return new \support\Response($data); }
    }
}
namespace plugin\SandIam\app\service {
    class AuditWriter {
        public static bool $fail = false;
        public function write(string $actor, string $ref, ?int $organization, ?int $application, string $action, string $resource, ?int $id, string $outcome, string $request): void {
            \PolicyPublicationTest\Store::$audits[] = [$action, $outcome];
            if (self::$fail) throw new \RuntimeException('audit unavailable');
        }
    }
}
namespace plugin\SandIam\app\admin\support {
    class AdminOrganizationAccess {
        public static bool $allowed = true;
        public function __construct(int $id, ?array $info) {}
        public function assertApplication(int $id): void {
            if (self::$allowed) return;
            (new \plugin\SandIam\app\service\AuditWriter())->write('admin', '2', 1, $id, 'application.access', 'application', $id, 'denied', 'policy-state-request');
            throw new \plugin\sandadmin\exception\ApiException('denied', 403);
        }
    }
}
namespace think\facade {
    use PolicyPublicationTest\Store;
    final class Db {
        public static function startTrans(): void {
            if (Store::$before !== null) throw new \RuntimeException('Unexpected nested transaction');
            Store::$before = [Store::$policies, Store::$versions, Store::$audits];
        }
        public static function commit(): void { Store::$before = null; }
        public static function rollback(): void {
            if (Store::$before !== null) [Store::$policies, Store::$versions, Store::$audits] = Store::$before;
            Store::$before = null;
        }
    }
}
namespace plugin\SandIam\app\model {
    use PolicyPublicationTest\{Query, Store};
    final class Policy {
        public function __construct(private array $data) {}
        public function __get(string $key): mixed { return $this->data[$key] ?? null; }
        public function __isset(string $key): bool { return isset($this->data[$key]); }
        public static function find(int $id): ?self { return isset(Store::$policies[$id]) ? new self(Store::$policies[$id]) : null; }
        public static function findOrEmpty(int $id): self { return self::find($id) ?? new self([]); }
        public function isEmpty(): bool { return $this->data === []; }
        public static function where(string $key, mixed $value): Query { return (new Query('policy'))->where($key, $value); }
        public function save(array $values): void { $this->data = array_replace($this->data, $values); Store::$policies[$this->id] = $this->data; }
    }
    class Application {
        public static function find(int $id): object { return (object) ['id' => $id, 'organization_id' => 1]; }
    }
    final class PolicyVersion {
        public function __construct(private array $data) {}
        public function __get(string $key): mixed { return $this->data[$key] ?? null; }
        public static function where(string $key, mixed $value): Query { return (new Query('version'))->where($key, $value); }
        public static function create(array $data): self {
            $data['id'] = max([0, ...array_keys(Store::$versions)]) + 1;
            Store::$versions[$data['id']] = $data;
            return new self($data);
        }
    }
}
namespace {
    function request(): \support\Request { return new \support\Request(); }
    use PolicyPublicationTest\Store;
    use function PolicyPublicationTest\check;
    use plugin\SandIam\app\service\PolicyVersionService;
    require dirname(__DIR__) . '/app/service/RequestId.php';
    require dirname(__DIR__) . '/app/service/PolicyVersionService.php';
    Store::$policies[1] = ['id' => 1, 'application_id' => 10, 'resource_id' => 20, 'role_id' => 30, 'identity_id' => 0, 'action' => 'read', 'effect' => 'allow', 'condition' => [], 'scope' => [], 'priority' => 10, 'state' => 'draft', 'status' => 1, 'published_version_id' => null];
    $service = new PolicyVersionService();
    $audit = static function (): void {
        check(Store::$before !== null, 'Audit ran after commit');
        $id = Store::$policies[1]['published_version_id'];
        check(isset(Store::$versions[$id]), 'Audit cannot see new version');
        Store::$audits[] = $id;
    };
    foreach (['publish', 'rollback'] as $operation) {
        $sourceId = $operation === 'rollback' ? 1 : null;
        $requestId = 'audit-' . $operation;
        $before = [Store::$policies, Store::$versions, Store::$audits];
        try {
            $service->publish(1, $requestId, $sourceId, static function () use ($audit): void {
                $audit();
                throw new \RuntimeException('audit unavailable');
            });
            throw new \RuntimeException('Failed audit was ignored');
        } catch (\RuntimeException $error) { check($error->getMessage() === 'audit unavailable', $error->getMessage()); }
        check([Store::$policies, Store::$versions, Store::$audits] === $before, 'Failed audit left published state');
        check(Store::$before === null, 'Transaction leaked');
        $result = $service->publish(1, $requestId, $sourceId, $audit);
        check(!$result['replayed'] && count(Store::$versions) === count($before[1]) + 1 && count(Store::$audits) === count($before[2]) + 1, 'Retry did not commit exactly once');
        $after = [Store::$policies, Store::$versions, Store::$audits];
        check($service->publish(1, $requestId, $sourceId, $audit)['replayed'], 'Successful retry was not replayed');
        check([Store::$policies, Store::$versions, Store::$audits] === $after, 'Replay repeated side effects');
    }
    $before = [Store::$policies, Store::$versions, Store::$audits];
    foreach ([['audit-publish', 1], ['foreign-version', 999]] as [$requestId, $sourceId]) {
        try { $service->publish(1, $requestId, $sourceId, $audit); throw new \RuntimeException('Invalid publication accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) {}
        check([Store::$policies, Store::$versions, Store::$audits] === $before, 'Rejected publication changed state');
    }
    check(Store::$versions[1]['snapshot'] === Store::$versions[2]['snapshot'] && Store::$versions[2]['rollback_of_version_id'] === 1, 'Rollback did not preserve source snapshot');
    echo "policy publication audit non-PG behavior PASS\n";

    require dirname(__DIR__) . '/app/admin/support/AdminResourceController.php';
    require dirname(__DIR__) . '/app/admin/support/ApplicationResourceController.php';
    require dirname(__DIR__) . '/app/admin/controller/PolicyController.php';
    $controller = new \plugin\SandIam\app\admin\controller\PolicyController();
    foreach (['revoke', 'disable'] as $operation) {
        Store::$policies[1]['state'] = 'published';
        Store::$policies[1]['status'] = 1;
        $before = [Store::$policies, Store::$versions, Store::$audits];
        \plugin\SandIam\app\service\AuditWriter::$fail = true;
        try { $controller->{$operation}(new \support\Request()); throw new \RuntimeException('State audit failure ignored'); }
        catch (\RuntimeException $error) { check($error->getMessage() === 'audit unavailable', $error->getMessage()); }
        check([Store::$policies, Store::$versions, Store::$audits] === $before, 'Failed state audit left mutation');
        \plugin\SandIam\app\service\AuditWriter::$fail = false;
        $controller->{$operation}(new \support\Request());
        check(Store::$policies[1]['status'] === 2 && Store::$policies[1]['state'] === ($operation === 'revoke' ? 'revoked' : 'published'), 'State retry failed');
        check(Store::$policies[1]['published_version_id'] === $before[0][1]['published_version_id'] && Store::$versions === $before[1], 'State mutation changed versions');
        check(count(Store::$audits) === count($before[2]) + 1 && end(Store::$audits) === ['policy.' . $operation, 'succeeded'], 'State audit missing or duplicated');
        \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$allowed = false;
        $policies = Store::$policies;
        $auditCount = count(Store::$audits);
        try { $controller->{$operation}(new \support\Request()); throw new \RuntimeException('Unauthorized mutation accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 403, 'Unexpected denial'); }
        check(Store::$policies === $policies && count(Store::$audits) === $auditCount + 1 && end(Store::$audits) === ['application.access', 'denied'], 'Denial audit lost or unauthorized state changed');
        check(Store::$before === null, 'State transaction leaked');
        \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$allowed = true;
    }
    echo "policy revoke/disable audit controller non-PG behavior PASS\n";
}
