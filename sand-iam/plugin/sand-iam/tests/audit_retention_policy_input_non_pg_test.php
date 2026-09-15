<?php
declare(strict_types=1);

namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace support {
    class Response { public function __construct(public mixed $data) {} }
    class Request {
        public function __construct(private array $body) {}
        public function post(?string $key = null, mixed $default = null): mixed { return $key === null ? $this->body : ($this->body[$key] ?? $default); }
        public function input(string $key, mixed $default = null): mixed { return $this->post($key, $default); }
        public function header(string $key, mixed $default = null): mixed { return $key === 'check_admin' ? ['id' => 1] : $default; }
    }
}
namespace plugin\sandadmin\basic {
    class BaseController { protected function success(mixed $data, string $message = ''): \support\Response { return new \support\Response($data); } }
}
namespace plugin\SandIam\app\admin\support {
    class AdminOrganizationAccess {
        public function __construct(int $id, ?array $token) {}
        public function assertOrganization(int $id): void { if ($id !== 10) throw new \RuntimeException('foreign organization'); }
        public function assertSuperAdmin(): void {}
    }
}
namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    class Record {
        public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; }
        public function isEmpty(): bool { return false; }
        public function save(array $values): void { foreach ($values as $key => $value) $this->$key = $value; }
    }
    class Organization {
        public static function where(string $key, mixed $value): OrganizationQuery { return (new OrganizationQuery())->where($key, $value); }
    }
    class OrganizationQuery {
        private array $conditions = [];
        public function where(string $key, mixed $value): self { $this->conditions[$key] = $value; return $this; }
        public function find(): ?Record { return ($this->conditions['id'] ?? 0) === 10 ? new Record(['id' => 10, 'status' => 1]) : null; }
    }
    class AuditRetentionPolicy {
        public static array $rows = [];
        public static function where(string $key, mixed $value): PolicyQuery { return new PolicyQuery($key, $value); }
        public static function create(array $values): Record {
            $id = count(self::$rows) + 20;
            return self::$rows[$id] = new Record(['id' => $id] + $values);
        }
        public static function find(int $id): ?Record { return self::$rows[$id] ?? null; }
        public static function findOrEmpty(int $id): Record { return self::$rows[$id]; }
    }
    class PolicyQuery {
        public function __construct(private string $key, private mixed $value) {}
        public function find(): ?Record {
            foreach (AuditRetentionPolicy::$rows as $row) if ($row->{$this->key} === $this->value) return $row;
            return null;
        }
    }
    class SecurityOperation {
        public static array $rows = [];
        public static function where(string $key, mixed $value): OperationQuery { return (new OperationQuery())->where($key, $value); }
        public static function create(array $values): Record { return self::$rows[] = new Record($values); }
    }
    class OperationQuery {
        private array $conditions = [];
        public function where(string $key, mixed $value): self { $this->conditions[$key] = $value; return $this; }
        public function lock(bool $lock): self { return $this; }
        public function find(): ?Record {
            foreach (SecurityOperation::$rows as $row) {
                foreach ($this->conditions as $key => $value) if ($row->$key !== $value) continue 2;
                return $row;
            }
            return null;
        }
    }
}
namespace plugin\SandIam\app\service {
    class RequestId {
        public static function fromRequestCached(\support\Request $request): string { return 'retention-input-test'; }
        public static function normalize(string $value): string { return $value; }
    }
    class AuditWriter {
        public static array $events = [];
        public static bool $fail = false;
        public function write(mixed ...$args): void {
            if (self::$fail) throw new \RuntimeException('audit unavailable');
            self::$events[] = $args;
        }
    }
}
namespace think\facade {
    class Db {
        public static bool $active = false;
        private static string $snapshot;
        public static function startTrans(): void {
            self::$snapshot = serialize([\plugin\SandIam\app\model\AuditRetentionPolicy::$rows, \plugin\SandIam\app\service\AuditWriter::$events, \plugin\SandIam\app\model\SecurityOperation::$rows]);
            self::$active = true;
        }
        public static function commit(): void { self::$active = false; }
        public static function rollback(): void {
            [\plugin\SandIam\app\model\AuditRetentionPolicy::$rows, \plugin\SandIam\app\service\AuditWriter::$events, \plugin\SandIam\app\model\SecurityOperation::$rows] = unserialize(self::$snapshot);
            self::$active = false;
        }
    }
}
namespace {
    use plugin\SandIam\app\model\AuditRetentionPolicy;
    use plugin\SandIam\app\model\Record;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\admin\controller\AuditRetentionPolicyController;
    use plugin\sandadmin\exception\ApiException;
    function request(): \support\Request { return new \support\Request([]); }
    require dirname(__DIR__) . '/app/admin/support/AdminResourceController.php';
    require dirname(__DIR__) . '/app/admin/controller/AuditRetentionPolicyController.php';
    require dirname(__DIR__) . '/app/service/IdempotencyService.php';
    AuditRetentionPolicy::$rows[7] = new Record(['id' => 7, 'organization_id' => 10, 'purge_enabled' => 0,
        'archive_after_days' => 90, 'retention_days' => 365, 'alert_window_seconds' => 300, 'alert_failure_threshold' => 5]);
    $controller = new AuditRetentionPolicyController();
    foreach ([2, -1, 1.5, 'yes', 'false', '1abc', [], null] as $invalid) {
        $before = serialize([AuditRetentionPolicy::$rows, AuditWriter::$events]);
        try {
            $controller->update(new \support\Request(['id' => 7, 'purge_enabled' => $invalid]));
            throw new \RuntimeException('invalid purge switch accepted');
        } catch (ApiException $error) { if ($error->getCode() !== 400) throw $error; }
        if (serialize([AuditRetentionPolicy::$rows, AuditWriter::$events]) !== $before) throw new \RuntimeException('invalid switch changed policy or audit');
    }
    foreach ([0, 1, '0', '1', false, true] as $valid) {
        $controller->update(new \support\Request(['id' => 7, 'purge_enabled' => $valid]));
        if (AuditRetentionPolicy::$rows[7]->purge_enabled !== (int) $valid) throw new \RuntimeException('switch normalization failed');
    }
    $controller->update(new \support\Request(['id' => 7, 'retention_days' => 400]));
    if (AuditRetentionPolicy::$rows[7]->purge_enabled !== 1) throw new \RuntimeException('omitted switch changed existing setting');
    foreach (['archive_after_days', 'retention_days', 'alert_window_seconds', 'alert_failure_threshold'] as $field) {
        foreach ([100.5, true, false, null, [], '100days', '100.5', '1e2'] as $invalid) {
            $before = serialize([AuditRetentionPolicy::$rows, AuditWriter::$events]);
            try {
                $controller->update(new \support\Request(['id' => 7, $field => $invalid]));
                throw new \RuntimeException('non-integer policy input accepted');
            } catch (ApiException $error) { if ($error->getCode() !== 400) throw $error; }
            if (serialize([AuditRetentionPolicy::$rows, AuditWriter::$events]) !== $before) throw new \RuntimeException('invalid integer changed policy or audit');
        }
    }
    $controller->update(new \support\Request(['id' => 7, 'archive_after_days' => '100', 'retention_days' => '400',
        'alert_window_seconds' => '60', 'alert_failure_threshold' => '2']));
    foreach (['archive_after_days' => 100, 'retention_days' => 400, 'alert_window_seconds' => 60, 'alert_failure_threshold' => 2] as $field => $expected) {
        if (AuditRetentionPolicy::$rows[7]->$field !== $expected) throw new \RuntimeException('form policy integer not normalized');
    }
    $before = serialize([AuditRetentionPolicy::$rows, AuditWriter::$events]);
    try {
        $controller->update(new \support\Request(['id' => 7, 'retention_days' => 99]));
        throw new \RuntimeException('retention shorter than archive accepted');
    } catch (ApiException $error) { if ($error->getCode() !== 400) throw $error; }
    if (serialize([AuditRetentionPolicy::$rows, AuditWriter::$events]) !== $before) throw new \RuntimeException('invalid retention relation changed policy');
    foreach (['update', 'disable'] as $operation) {
        $before = serialize([AuditRetentionPolicy::$rows, AuditWriter::$events]);
        AuditWriter::$fail = true;
        try {
            $controller->$operation(new \support\Request(['id' => 7, 'purge_enabled' => 0, 'retention_days' => 500]));
            throw new \RuntimeException('policy audit failure hidden');
        } catch (\RuntimeException $error) {
            if ($error->getMessage() !== 'audit unavailable') throw $error;
        } finally { AuditWriter::$fail = false; }
        if (serialize([AuditRetentionPolicy::$rows, AuditWriter::$events]) !== $before || \think\facade\Db::$active) {
            throw new \RuntimeException('policy audit failure left mutation');
        }
    }
    $controller->disable(new \support\Request(['id' => 7]));
    if (AuditRetentionPolicy::$rows[7]->status !== 2 || end(AuditWriter::$events)[4] !== 'audit_retention_policy.disable') {
        throw new \RuntimeException('policy disable retry failed');
    }
    AuditRetentionPolicy::$rows = [];
    AuditWriter::$events = [];
    AuditWriter::$fail = true;
    try {
        $controller->save(new \support\Request(['organization_id' => 10]));
        throw new \RuntimeException('create audit failure hidden');
    } catch (\RuntimeException $error) {
        if ($error->getMessage() !== 'audit unavailable') throw $error;
    } finally { AuditWriter::$fail = false; }
    if (AuditRetentionPolicy::$rows !== [] || AuditWriter::$events !== [] || \plugin\SandIam\app\model\SecurityOperation::$rows !== [] || \think\facade\Db::$active) {
        throw new \RuntimeException('create audit failure left policy or operation');
    }
    $created = $controller->save(new \support\Request(['organization_id' => 10]));
    if (!isset(AuditRetentionPolicy::$rows[$created->data['id']]) || count(AuditWriter::$events) !== 1) throw new \RuntimeException('create retry failed');
    echo "Audit retention policy input PASS (offline controller)\n";
}
