<?php
declare(strict_types=1);

namespace support {
    class Response { public function __construct(public mixed $data) {} }
    class Request {
        public function __construct(private array $values) {}
        public function post(string $key, mixed $default = null): mixed { return array_key_exists($key, $this->values) ? $this->values[$key] : $default; }
        public function input(string $key, mixed $default = null): mixed { return $this->post($key, $default); }
        public function header(string $key, mixed $default = null): mixed { return $key === 'check_admin' ? ['id' => 5] : $default; }
    }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\sandadmin\basic {
    class BaseController { protected function success(mixed $data): \support\Response { return new \support\Response($data); } }
}
namespace plugin\SandIam\app\admin\support {
    class AdminOrganizationAccess {
        public static bool $allowed = true;
        public function __construct(int $id, ?array $token) {}
        public function assertApplication(int $id): void {
            if (!self::$allowed || $id !== 20) {
                (new \plugin\SandIam\app\service\AuditWriter())->write('admin', '5', 10, $id, 'application.access', 'application', $id, 'denied', 'sync-settings-test');
                throw new \RuntimeException('forbidden');
            }
        }
    }
}
namespace plugin\SandIam\app\model {
    class SyncRun {
        public static bool $running = false;
        public static function where(string $key, mixed $value): RunQuery { return (new RunQuery())->where($key, $value); }
    }
    class RunQuery {
        private array $conditions = [];
        public function where(string $key, mixed $value): self { $this->conditions[$key] = $value; return $this; }
        public function find(): ?object {
            if (($this->conditions['state'] ?? '') !== 'running' || !isset($this->conditions['sync_connector_id'])) throw new \RuntimeException('unscoped running check');
            return SyncRun::$running ? (object) ['id' => 1] : null;
        }
    }
    class Application {
        public static function where(string $key, int $value): ApplicationQuery { return (new ApplicationQuery())->where($key, $value); }
    }
    class ApplicationQuery {
        private array $conditions = [];
        public function where(string $key, int $value): self { $this->conditions[$key] = $value; return $this; }
        public function find(): ?object { return ($this->conditions['id'] ?? 0) === 20 ? (object) ['id' => 20, 'organization_id' => 10, 'status' => 1] : null; }
    }
    #[\AllowDynamicProperties]
    class SyncConnector {
        public static array $rows = [];
        public static bool $locked = false;
        public static ?\Closure $beforeLock = null;
        public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; }
        public static function find(int $id): ?self { return self::$rows[$id] ?? null; }
        public static function create(array $values): self {
            foreach (self::$rows as $row) {
                if ($row->application_id === $values['application_id'] && $row->code === $values['code']) {
                    throw new \RuntimeException('unique connector code');
                }
            }
            $id = self::$rows === [] ? 1 : max(array_keys(self::$rows)) + 1;
            return self::$rows[$id] = new self(['id' => $id] + $values);
        }
        public static function where(string $key, int $id): Query {
            if ($key !== 'id') throw new \RuntimeException('imprecise connector lookup');
            return new Query($id);
        }
        public function save(array $values): void { foreach ($values as $key => $value) $this->$key = $value; }
    }
    class Query {
        public function __construct(private int $id) {}
        public function lock(bool $lock): self {
            if (!\think\facade\Db::$active || !$lock) throw new \RuntimeException('lock outside transaction');
            if (SyncConnector::$beforeLock !== null) {
                $callback = SyncConnector::$beforeLock;
                SyncConnector::$beforeLock = null;
                $callback();
            }
            SyncConnector::$locked = true;
            return $this;
        }
        public function find(): ?SyncConnector { return SyncConnector::find($this->id); }
    }
}
namespace plugin\SandIam\app\service {
    class RequestId { public static function fromRequestCached(\support\Request $request): string { return 'sync-settings-test'; } }
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
            self::$snapshot = serialize([\plugin\SandIam\app\model\SyncConnector::$rows, \plugin\SandIam\app\service\AuditWriter::$events]);
        }
        public static function commit(): void { self::$active = false; }
        public static function rollback(): void {
            [\plugin\SandIam\app\model\SyncConnector::$rows, \plugin\SandIam\app\service\AuditWriter::$events] = unserialize(self::$snapshot);
            self::$active = false;
        }
    }
}
namespace {
    require dirname(__DIR__) . '/app/admin/controller/SyncConnectorController.php';
    use plugin\SandIam\app\model\SyncConnector;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
    use plugin\SandIam\app\admin\controller\SyncConnectorController;
    function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    $original = ['id' => 7, 'organization_id' => 10, 'application_id' => 20, 'name' => '目录',
        'code' => 'directory', 'driver_code' => 'ldap', 'direction' => 'inbound',
        'missing_protection_hours' => 24, 'disable_threshold_percent' => 20, 'conflict_policy' => 'manual'];
    SyncConnector::$rows[7] = new SyncConnector($original);
    $controller = new SyncConnectorController();
    AuditWriter::$fail = true;
    try { $controller->update(new \support\Request(['id' => 7, 'name' => '修改'])); throw new \RuntimeException('audit failure accepted'); }
    catch (\RuntimeException $error) { check($error->getMessage() === 'audit unavailable', 'wrong audit error'); }
    check(get_object_vars(SyncConnector::$rows[7]) === $original, 'audit failure left new protection settings');
    AuditWriter::$fail = false;
    foreach (['manual', 'reject', 'source_wins', 'local_wins'] as $policy) {
        $controller->update(new \support\Request(['id' => 7, 'name' => '新目录', 'missing_protection_hours' => 720,
            'disable_threshold_percent' => 100, 'conflict_policy' => $policy, 'application_id' => 99, 'code' => 'forged']));
        check(SyncConnector::$rows[7]->conflict_policy === $policy && SyncConnector::$rows[7]->application_id === 20
            && SyncConnector::$rows[7]->code === 'directory', 'settings update changed identity or ignored policy');
    }
    check(SyncConnector::$locked && count(AuditWriter::$events) === 4 && AuditWriter::$events[0][8] === 'sync-settings-test', 'settings audit or lock missing');
    foreach ([['name' => ''], ['missing_protection_hours' => 0], ['missing_protection_hours' => 721],
        ['disable_threshold_percent' => 0], ['disable_threshold_percent' => 101], ['conflict_policy' => 'unknown']] as $invalid) {
        $before = get_object_vars(SyncConnector::$rows[7]);
        try { $controller->update(new \support\Request(['id' => 7] + $invalid)); throw new \RuntimeException('invalid settings accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 400, 'validation status changed'); }
        check(get_object_vars(SyncConnector::$rows[7]) === $before, 'invalid settings mutated connector');
    }
    foreach (['missing_protection_hours', 'disable_threshold_percent'] as $field) {
        foreach ([1.5, true, false, null, [], '12hours', '1.5', '1e1', ''] as $invalid) {
            $before = serialize([SyncConnector::$rows, AuditWriter::$events]);
            try { $controller->update(new \support\Request(['id' => 7, $field => $invalid])); throw new \RuntimeException('coerced protection value accepted'); }
            catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 400, 'invalid protection type status changed'); }
            check(serialize([SyncConnector::$rows, AuditWriter::$events]) === $before, 'invalid protection type changed connector or audit');
        }
    }
    AdminOrganizationAccess::$allowed = false;
    $beforeDenied = get_object_vars(SyncConnector::$rows[7]);
    try { $controller->update(new \support\Request(['id' => 7, 'name' => 'forbidden'])); throw new \RuntimeException('unauthorized update accepted'); }
    catch (\RuntimeException $error) { check($error->getMessage() === 'forbidden', 'wrong authorization error'); }
    try { $controller->update(new \support\Request(['id' => 999])); throw new \RuntimeException('missing connector accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 404, 'missing connector status changed'); }
    check(!\think\facade\Db::$active && count(AuditWriter::$events) === 5
        && AuditWriter::$events[4][4] === 'application.access' && AuditWriter::$events[4][7] === 'denied', 'denied settings update must retain denial audit');
    check(get_object_vars(SyncConnector::$rows[7]) === $beforeDenied, 'denied settings update changed connector');
    AdminOrganizationAccess::$allowed = true;
    foreach (['organization_id', 'application_id'] as $field) {
        $before = get_object_vars(SyncConnector::$rows[7]);
        SyncConnector::$beforeLock = static function () use ($field): void { SyncConnector::$rows[7]->$field = 99; };
        try { $controller->update(new \support\Request(['id' => 7, 'name' => 'wrong scope'])); throw new \RuntimeException('changed connector scope accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) {
            check($error->getCode() === 409 && str_contains($error->getMessage(), 'SCOPE_CHANGED'), 'scope conflict not rejected');
        }
        check(get_object_vars(SyncConnector::$rows[7]) === $before && count(AuditWriter::$events) === 5
            && !\think\facade\Db::$active, 'scope conflict changed settings or success audit');
    }
    $create = ['application_id' => 20, 'code' => 'new-directory', 'name' => '新连接', 'driver_code' => 'ldap'];
    $before = serialize([SyncConnector::$rows, AuditWriter::$events]);
    AuditWriter::$fail = true;
    try { $controller->save(new \support\Request($create)); throw new \RuntimeException('create audit failure accepted'); }
    catch (\RuntimeException $error) { check($error->getMessage() === 'audit unavailable', 'wrong create audit error'); }
    finally { AuditWriter::$fail = false; }
    check(serialize([SyncConnector::$rows, AuditWriter::$events]) === $before, 'failed create left a connector or audit');
    $created = $controller->save(new \support\Request($create));
    $createdId = $created->data['id'];
    check(SyncConnector::$rows[$createdId]->code === 'new-directory' && end(AuditWriter::$events)[4] === 'sync_connector.create', 'create retry failed');
    $before = serialize([SyncConnector::$rows, AuditWriter::$events]);
    try { $controller->save(new \support\Request($create)); throw new \RuntimeException('duplicate create accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 409, 'duplicate create status changed'); }
    check(serialize([SyncConnector::$rows, AuditWriter::$events]) === $before && !\think\facade\Db::$active, 'duplicate create left mutation or transaction');
    $controller->update(new \support\Request(['id' => 7, 'missing_protection_hours' => '720', 'disable_threshold_percent' => '100']));
    check(SyncConnector::$rows[7]->missing_protection_hours === 720 && SyncConnector::$rows[7]->disable_threshold_percent === 100, 'form integer strings must remain supported');
    $controller->update(new \support\Request(['id' => 7, 'missing_protection_hours' => 1, 'disable_threshold_percent' => 1]));
    check(SyncConnector::$rows[7]->missing_protection_hours === 1 && SyncConnector::$rows[7]->disable_threshold_percent === 1, 'minimum protection values rejected');
    $before = serialize([SyncConnector::$rows, AuditWriter::$events]);
    \plugin\SandIam\app\model\SyncRun::$running = true;
    try { $controller->disable(new \support\Request(['id' => $createdId])); throw new \RuntimeException('running connector disabled'); }
    catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 409, 'running conflict changed'); }
    check(serialize([SyncConnector::$rows, AuditWriter::$events]) === $before, 'running rejection changed connector or audit');
    \plugin\SandIam\app\model\SyncRun::$running = false;
    AuditWriter::$fail = true;
    try { $controller->disable(new \support\Request(['id' => $createdId])); throw new \RuntimeException('disable audit failure accepted'); }
    catch (\RuntimeException $error) { check($error->getMessage() === 'audit unavailable', 'wrong disable audit error'); }
    finally { AuditWriter::$fail = false; }
    check(serialize([SyncConnector::$rows, AuditWriter::$events]) === $before && !\think\facade\Db::$active, 'disable failure left connector disabled');
    $controller->disable(new \support\Request(['id' => $createdId]));
    check(SyncConnector::$rows[$createdId]->status === 2 && end(AuditWriter::$events)[4] === 'sync_connector.disable', 'disable retry failed');
    echo "Sync connector protection update PASS (offline controller)\n";
}
