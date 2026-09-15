<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace support {
    final class Request
    {
        public function __construct(private array $post, private array $headers) {}
        public function post(): array { return $this->post; }
        public function input(string $key, mixed $default = null): mixed { return $this->post[$key] ?? $default; }
        public function header(string $key, mixed $default = null): mixed { return $this->headers[$key] ?? $default; }
    }
    final class Response { public function __construct(public mixed $data, public string $message = '') {} }
}

namespace plugin\sandadmin\basic {
    class BaseController
    {
        protected function success(mixed $data = null, string $message = ''): \support\Response { return new \support\Response($data, $message); }
    }
}

namespace plugin\SandIam\app\service {
    final class AuditWriter
    {
        public static bool $fail = false;
        public static array $writes = [];
        public function write(string $actorType, string $actorRef, ?int $organizationId, ?int $applicationId, string $action, string $resourceType, ?int $resourceId, string $outcome, string $requestId): void
        {
            if (self::$fail) throw new \RuntimeException('injected audit failure');
            self::$writes[] = compact('actorType', 'actorRef', 'organizationId', 'applicationId', 'action', 'resourceType', 'resourceId', 'outcome', 'requestId');
        }
    }
}

namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    final class AtomicRecord
    {
        public function __construct(array $values) { foreach ($values as $key => $value) $this->{$key} = $value; }
        public function save(array $values): void { foreach ($values as $key => $value) $this->{$key} = $value; }
        public function toArray(): array { return get_object_vars($this); }
    }
    final class AtomicResource
    {
        public static array $rows = [];
        public static function create(array $values): AtomicRecord { $id = count(self::$rows) + 1; return self::$rows[$id] = new AtomicRecord(['id' => $id, ...$values]); }
        public static function find(int $id): ?AtomicRecord { return self::$rows[$id] ?? null; }
    }
    final class MemoryQuery
    {
        private array $where = [];
        public function __construct(private string $class, string $field, mixed $value) { $this->where[$field] = $value; }
        public function where(string $field, mixed $value): self { $this->where[$field] = $value; return $this; }
        public function lock(bool $lock): self { return $this; }
        public function find(): ?AtomicRecord
        {
            foreach (($this->class)::$rows as $row) {
                foreach ($this->where as $field => $value) if (($row->{$field} ?? null) !== $value) continue 2;
                return $row;
            }
            return null;
        }
    }
    final class SecurityOperation
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): MemoryQuery { return new MemoryQuery(self::class, $field, $value); }
        public static function create(array $values): AtomicRecord { $id = count(self::$rows) + 1; return self::$rows[$id] = new AtomicRecord(['id' => $id, ...$values]); }
    }
}

namespace think\facade {
    final class Db
    {
        private static array $snapshots = [];
        public static function startTrans(): void { self::$snapshots[] = serialize([\plugin\SandIam\app\model\AtomicResource::$rows, \plugin\SandIam\app\model\SecurityOperation::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]); }
        public static function commit(): void { array_pop(self::$snapshots); }
        public static function rollback(): void
        {
            [$resources, $operations, $audits] = unserialize(array_pop(self::$snapshots), ['allowed_classes' => true]);
            \plugin\SandIam\app\model\AtomicResource::$rows = $resources;
            \plugin\SandIam\app\model\SecurityOperation::$rows = $operations;
            \plugin\SandIam\app\service\AuditWriter::$writes = $audits;
        }
    }
}

namespace plugin\SandIam\app\admin\support {
    final class AdminOrganizationAccess
    {
        public function __construct(int $adminId, ?array $adminInfo) {}
        public function isSuperAdmin(): bool { return true; }
        public function organizationIds(): array { return []; }
        public function assertSuperAdmin(): void {}
        public function assertOrganization(int $organizationId): void {}
    }
}

namespace {
    use plugin\SandIam\app\admin\support\AdminResourceController;
    use plugin\SandIam\app\model\AtomicResource;
    use plugin\SandIam\app\model\SecurityOperation;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\sandadmin\exception\ApiException;
    use support\Request;

    function request(): object { return new class { public function header(string $key, mixed $default = null): mixed { return $key === 'check_admin' ? ['id' => 77] : $default; } }; }
    function atomicAssert(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }

    require dirname(__DIR__) . '/app/service/RequestId.php';
    require dirname(__DIR__) . '/app/service/IdempotencyService.php';
    require dirname(__DIR__) . '/app/admin/support/AdminResourceController.php';

    $controller = new class extends AdminResourceController {
        protected string $modelClass = AtomicResource::class;
        protected array $writeFields = ['code', 'name'];
        protected string $resourceType = 'atomic_resource';
        protected bool $atomicCreateAudit = true;
    };
    $headers = ['check_admin' => ['id' => 77], 'X-Request-Id' => 'atomic-create-request-001'];
    $request = new Request(['code' => 'atomic-resource', 'name' => 'Atomic resource'], $headers);

    // This is the former create-then-audit sequence: an audit exception leaves
    // the already-created object behind, which is the defect under regression.
    AuditWriter::$fail = true;
    try {
        $legacy = AtomicResource::create(['code' => 'legacy', 'name' => 'Legacy']);
        (new AuditWriter())->write('admin', '77', null, null, 'atomic_resource.create', 'atomic_resource', $legacy->id, 'succeeded', 'atomic-create-request-001');
    } catch (\RuntimeException $exception) {
        atomicAssert($exception->getMessage() === 'injected audit failure' && count(AtomicResource::$rows) === 1, 'pre-fix create then audit did not reproduce its residual object');
    }
    AtomicResource::$rows = [];
    AuditWriter::$fail = true;
    try {
        $controller->save($request);
        throw new \RuntimeException('atomic save unexpectedly succeeded when audit failed');
    } catch (\RuntimeException $exception) {
        atomicAssert($exception->getMessage() === 'injected audit failure', 'atomic save changed the audit failure');
    }
    atomicAssert(AtomicResource::$rows === [] && SecurityOperation::$rows === [] && AuditWriter::$writes === [], 'audit failure must roll back resource, idempotency record, and audit');

    AuditWriter::$fail = false;
    $first = $controller->save($request);
    $replayed = $controller->save(new Request(['code' => 'atomic-resource', 'name' => 'Atomic resource'], $headers));
    atomicAssert($first->data === ['id' => 1] && $replayed->data === ['id' => 1], 'same request replay must keep the established id-only response');
    atomicAssert(count(AtomicResource::$rows) === 1 && count(SecurityOperation::$rows) === 1 && count(AuditWriter::$writes) === 1, 'same request replay must not create or audit twice');
    try {
        $controller->save(new Request(['code' => 'changed-resource', 'name' => 'Changed'], $headers));
        throw new \RuntimeException('same request id accepted a changed create payload');
    } catch (ApiException $exception) {
        atomicAssert($exception->getCode() === 409, 'changed payload lost the existing idempotency conflict');
    }
    foreach (['OrganizationController.php', 'ApplicationController.php', 'EnvironmentController.php', 'AdminApplicationGrantController.php'] as $controllerFile) {
        atomicAssert(str_contains((string) file_get_contents(dirname(__DIR__) . '/app/admin/controller/' . $controllerFile), 'protected bool $atomicCreateAudit = true;'), "{$controllerFile} did not opt into atomic audited create");
    }
    echo "admin resource atomic create non-pg test passed\n";
}
