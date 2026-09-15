<?php
declare(strict_types=1);

// Real controller with in-memory persistence and injected audit failures.
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace support {
    class Response { public function __construct(public mixed $data) {} }
    class Request {
        public function __construct(private int $id) {}
        public function post(string $key, mixed $default = null): mixed { return $key === 'id' ? $this->id : $default; }
        public function header(string $key, mixed $default = null): mixed {
            return match ($key) { 'check_admin' => ['id' => 5], 'X-Request-Id' => 'alert-resolve-test', default => $default };
        }
    }
}
namespace plugin\sandadmin\basic {
    class BaseController { protected function success(mixed $data): \support\Response { return new \support\Response($data); } }
}
namespace plugin\SandIam\app\admin\support {
    class AdminOrganizationAccess {
        public static bool $allowed = true;
        public function __construct(int $id, ?array $token) {}
        public function assertOrganization(int $id): void {
            if (!self::$allowed || $id !== 10) {
                (new \plugin\SandIam\app\service\AuditWriter())->write('admin', '5', $id, null, 'organization.access', 'organization', $id, 'denied', 'alert-resolve-test');
                throw new \RuntimeException('forbidden');
            }
        }
        public function assertApplication(int $id): void {
            if (!self::$allowed || $id !== 20) {
                (new \plugin\SandIam\app\service\AuditWriter())->write('admin', '5', 10, $id, 'application.access', 'application', $id, 'denied', 'alert-resolve-test');
                throw new \RuntimeException('forbidden');
            }
        }
    }
}
namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    class SecurityAlert {
        public static array $rows = [];
        public static bool $locked = false;
        public static ?\Closure $beforeLock = null;
        public function __construct(array $data) { foreach ($data as $key => $value) $this->$key = $value; }
        public static function find(int $id): ?self { return self::$rows[$id] ?? null; }
        public static function where(string $key, int $id): AlertQuery {
            if ($key !== 'id') throw new \RuntimeException('expected precise alert id');
            return new AlertQuery($id);
        }
        public function save(array $data): void { foreach ($data as $key => $value) $this->$key = $value; }
    }
    class AlertQuery {
        public function __construct(private int $id) {}
        public function lock(bool $lock): self {
            if (!\think\facade\Db::$active || !$lock) throw new \RuntimeException('row lock outside transaction');
            if (SecurityAlert::$beforeLock !== null) {
                $callback = SecurityAlert::$beforeLock;
                SecurityAlert::$beforeLock = null;
                $callback();
            }
            SecurityAlert::$locked = true;
            return $this;
        }
        public function find(): ?SecurityAlert { return SecurityAlert::find($this->id); }
    }
}
namespace plugin\SandIam\app\service {
    class AuditWriter {
        public static bool $fail = false;
        public static array $events = [];
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
            self::$active = true;
            self::$snapshot = serialize([\plugin\SandIam\app\model\SecurityAlert::$rows, \plugin\SandIam\app\service\AuditWriter::$events]);
        }
        public static function commit(): void { self::$active = false; }
        public static function rollback(): void {
            [\plugin\SandIam\app\model\SecurityAlert::$rows, \plugin\SandIam\app\service\AuditWriter::$events] = unserialize(self::$snapshot);
            self::$active = false;
        }
    }
}
namespace {
    require dirname(__DIR__) . '/app/admin/controller/SecurityAlertController.php';
    use plugin\SandIam\app\model\SecurityAlert;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
    use plugin\SandIam\app\admin\controller\SecurityAlertController;
    function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    SecurityAlert::$rows[7] = new SecurityAlert(['id' => 7, 'organization_id' => 10, 'application_id' => 20, 'status' => 'open']);
    $controller = new SecurityAlertController();
    AuditWriter::$fail = true;
    try { $controller->resolve(new \support\Request(7)); throw new \RuntimeException('audit failure accepted'); }
    catch (\RuntimeException $error) { check($error->getMessage() === 'audit unavailable', 'wrong audit error'); }
    check(SecurityAlert::$rows[7]->status === 'open', 'audit failure left alert resolved');
    check(!isset(SecurityAlert::$rows[7]->resolved_by), 'audit failure retained actor');
    check(!\think\facade\Db::$active, 'transaction leaked after failure');
    AuditWriter::$fail = false;
    $controller->resolve(new \support\Request(7));
    check(SecurityAlert::$locked, 'resolve did not lock current row');
    check(SecurityAlert::$rows[7]->status === 'resolved' && SecurityAlert::$rows[7]->resolved_by === 5, 'resolve not persisted');
    check(count(AuditWriter::$events) === 1 && AuditWriter::$events[0][8] === 'alert-resolve-test', 'resolve audit missing');
    try { $controller->resolve(new \support\Request(7)); throw new \RuntimeException('duplicate resolve accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 409, 'duplicate status changed'); }
    check(count(AuditWriter::$events) === 1 && !\think\facade\Db::$active, 'duplicate audit or leaked transaction');
    SecurityAlert::$rows[8] = new SecurityAlert(['id' => 8, 'organization_id' => 10, 'application_id' => null, 'status' => 'open']);
    AdminOrganizationAccess::$allowed = false;
    try { $controller->resolve(new \support\Request(8)); throw new \RuntimeException('unauthorized resolve accepted'); }
    catch (\RuntimeException $error) { check($error->getMessage() === 'forbidden', 'wrong authorization error'); }
    check(SecurityAlert::$rows[8]->status === 'open' && count(AuditWriter::$events) === 2
        && AuditWriter::$events[1][4] === 'organization.access' && AuditWriter::$events[1][7] === 'denied', 'unauthorized resolution must retain denial audit');
    try { $controller->resolve(new \support\Request(999)); throw new \RuntimeException('missing alert accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 404, 'missing alert status changed'); }
    check(!\think\facade\Db::$active, 'transaction leaked');
    AdminOrganizationAccess::$allowed = true;
    SecurityAlert::$rows[9] = new SecurityAlert(['id' => 9, 'organization_id' => 10, 'application_id' => 21, 'status' => 'open']);
    try { $controller->resolve(new \support\Request(9)); throw new \RuntimeException('foreign application accepted'); }
    catch (\RuntimeException $error) { check($error->getMessage() === 'forbidden', 'wrong application denial'); }
    check(count(AuditWriter::$events) === 3 && AuditWriter::$events[2][4] === 'application.access'
        && AuditWriter::$events[2][7] === 'denied' && SecurityAlert::$rows[9]->status === 'open', 'application denial audit missing');
    SecurityAlert::$beforeLock = static function (): void { SecurityAlert::$rows[8]->application_id = 21; };
    try { $controller->resolve(new \support\Request(8)); throw new \RuntimeException('changed scope accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $error) {
        check($error->getCode() === 409 && str_contains($error->getMessage(), 'SCOPE_CHANGED'), 'changed scope not rejected');
    }
    check(SecurityAlert::$rows[8]->status === 'open' && count(AuditWriter::$events) === 3
        && !\think\facade\Db::$active, 'scope conflict changed alert or audit');
    echo "Security alert resolve behavior PASS (offline controller)\n";
}
