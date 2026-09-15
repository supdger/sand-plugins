<?php
declare(strict_types=1);
namespace support {
    class Request {
        public function __construct(private array $inputs = []) {}
        public function input(string $key, mixed $default = null): mixed { return $this->inputs[$key] ?? $default; }
        public function header(string $key, mixed $default = null): mixed { return ['id' => 2]; }
    }
    class Response {
        public array $headers = [];
        public function __construct(public mixed $data) {}
        public function withHeader(string $key, string $value): self { $this->headers[$key] = $value; return $this; }
    }
}
namespace plugin\sandadmin\basic {
    class BaseController { public function success(array $data): \support\Response { return new \support\Response($data); } }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\admin\support {
    class AdminOrganizationAccess {
        public static array $organizations = [];
        public static array $applications = [11];
        public function __construct(int $id, mixed $info) {}
        public function isSuperAdmin(): bool { return false; }
        public function organizationIds(): array { return self::$organizations; }
        public function applicationIds(): array { return self::$applications; }
        public function assertOrganization(int $id): void {
            if (!in_array($id, self::$organizations, true)) throw new \plugin\sandadmin\exception\ApiException('organization denied', 403);
        }
        public function assertApplication(int $id): void {
            if (!in_array($id, self::$applications, true)) throw new \plugin\sandadmin\exception\ApiException('application denied', 403);
        }
    }
}
namespace AuditApplicationTest {
    class Query {
        private array $conditions = [];
        private int $maximum = PHP_INT_MAX;
        public function __construct(private array $rows = []) {}
        public function matches(array $row): bool {
            $result = true;
            foreach ($this->conditions as [$or, $predicate]) $result = $or ? ($result || $predicate($row)) : ($result && $predicate($row));
            return $result;
        }
        public function where(mixed $field, mixed $operator = null, mixed $value = null): self {
            if ($field instanceof \Closure) {
                $group = new self(); $field($group);
                $this->conditions[] = [false, fn ($row) => $group->matches($row)];
            } else {
                $this->conditions[] = [false, fn ($row) => match ($operator) {
                    '>=' => $row[$field] >= $value, '<=' => $row[$field] <= $value,
                    default => $row[$field] == $operator
                }];
            }
            return $this;
        }
        public function whereIn(string $field, array $values): self {
            $this->conditions[] = [false, fn ($row) => in_array($row[$field], $values, true)]; return $this;
        }
        public function whereOr(string $field, string $operator, array $values): self {
            if ($operator !== 'in') throw new \RuntimeException('unexpected OR');
            $this->conditions[] = [true, fn ($row) => in_array($row[$field], $values, true)]; return $this;
        }
        public function whereRaw(string $condition): self {
            if ($condition !== '1 = 0') throw new \RuntimeException('unexpected raw');
            $this->conditions[] = [false, fn ($row) => false]; return $this;
        }
        private function rows(): array { return array_values(array_filter($this->rows, fn ($row) => $this->matches($row))); }
        public function paginate(array $options): object {
            $rows = $this->rows();
            return new class(['data' => array_slice($rows, ($options['page'] - 1) * $options['list_rows'], $options['list_rows']), 'total' => count($rows)]) {
                public function __construct(private array $data) {}
                public function toArray(): array { return $this->data; }
            };
        }
        public function limit(int $count): self { $this->maximum = $count; return $this; }
        public function select(): object {
            return new class(array_map(fn ($row) => (object) $row, array_slice($this->rows(), 0, $this->maximum))) {
                public function __construct(private array $rows) {}
                public function all(): array { return $this->rows; }
            };
        }
    }
}
namespace plugin\SandIam\app\model {
    class AuditLog {
        public static array $rows = [];
        public static function order(string $key, string $direction): \AuditApplicationTest\Query { return new \AuditApplicationTest\Query(self::$rows); }
    }
    class AuditArchive extends AuditLog {}
}
namespace {
    function response(string $body): \support\Response { return new \support\Response($body); }
    require dirname(__DIR__) . '/app/admin/controller/AuditLogController.php';
    foreach ([[1, 1, 11], [2, 1, 12], [3, 2, 21], [4, 3, 31]] as [$id, $org, $app]) {
        \plugin\SandIam\app\model\AuditLog::$rows[] = [
            'id' => $id, 'original_audit_id' => $id, 'organization_id' => $org, 'application_id' => $app,
            'create_time' => '2026-09-01 12:00:00', 'original_create_time' => '2026-09-01 12:00:00',
            'actor_type' => 'admin', 'actor_ref' => '2', 'action' => 'test', 'resource_type' => 'test',
            'resource_id' => $id, 'outcome' => 'succeeded', 'request_id' => "r{$id}", 'context' => []
        ];
    }
    function checkAudit(string $method, array $filter, array $expected): void {
        $controller = new \plugin\SandIam\app\admin\controller\AuditLogController();
        $result = $controller->$method(new \support\Request([...$filter, 'from' => '2026-09-01', 'to' => '2026-09-02']));
        if ($method === 'export') {
            $lines = explode("\n", trim($result->data));
            array_shift($lines);
            $ids = array_map(fn ($line) => (int) str_getcsv($line)[0], $lines);
            if ($result->headers['Cache-Control'] !== 'no-store') throw new \RuntimeException('Export cache protection changed');
        } else $ids = array_column($result->data['data'], 'id');
        if ($ids !== $expected) throw new \RuntimeException("{$method}: " . json_encode([$filter, $ids, $expected]));
    }
    foreach (['index', 'archiveIndex', 'export'] as $method) {
        checkAudit($method, ['organization_id' => 1], [1]);
        checkAudit($method, ['organization_id' => 2], []);
        checkAudit($method, [], [1]);
        try {
            checkAudit($method, ['application_id' => 12], []);
            throw new \RuntimeException('Application assertion removed');
        } catch (\plugin\sandadmin\exception\ApiException $error) {
            if ($error->getCode() !== 403) throw $error;
        }
        \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$applications = [];
        checkAudit($method, ['organization_id' => 1], []);
        \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$organizations = [2];
        \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$applications = [11];
        checkAudit($method, [], [1, 3]);
        checkAudit($method, ['organization_id' => 1], [1]);
        checkAudit($method, ['organization_id' => 2], [3]);
        checkAudit($method, ['organization_id' => 3], []);
        checkAudit($method, ['resource_id' => 3], [3]);
        \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$organizations = [];
    }
    try {
        (new \plugin\SandIam\app\admin\controller\AuditLogController())->export(new \support\Request());
        throw new \RuntimeException('Export range requirement removed');
    } catch (\plugin\sandadmin\exception\ApiException $error) {
        if ($error->getCode() !== 400) throw $error;
    }
    echo "audit application filter non-PG behavior PASS\n";
}
