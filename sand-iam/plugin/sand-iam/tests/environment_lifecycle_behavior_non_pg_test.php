<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace plugin\sandadmin\service {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class Permission { public function __construct(string $name, string $code) {} }
}

namespace support {
    class Request
    {
        /** @param array<string, mixed> $post */
        public function __construct(private array $post = [], private array $input = [], private string $requestId = 'environment-lifecycle-acceptance-001') {}
        /** @return array<string, mixed> */ public function post(): array { return $this->post; }
        public function input(string $key, mixed $default = null): mixed { return $this->input[$key] ?? $this->post[$key] ?? $default; }
        public function header(string $key, mixed $default = null): mixed { return $key === 'X-Request-Id' ? $this->requestId : $default; }
    }
    final class Response
    {
        public function __construct(public mixed $data = null, public string $message = '') {}
    }
}

namespace plugin\sandadmin\basic {
    use support\Response;

    class BaseController
    {
        protected function success(mixed $data = null, string $message = ''): Response { return new Response($data, $message); }
    }
}

namespace plugin\SandIam\app\service {
    final class AuditWriter
    {
        /** @var list<array{organization_id:?int,application_id:?int,action:string,outcome:string,request_id:string}> */
        public static array $writes = [];
        public function write(string $actorType, string $actorRef, ?int $organizationId, ?int $applicationId, string $action, string $resourceType, ?int $resourceId, string $outcome, string $requestId): void
        {
            self::$writes[] = ['organization_id' => $organizationId, 'application_id' => $applicationId, 'action' => $action, 'outcome' => $outcome, 'request_id' => $requestId];
        }
    }
}

namespace think\facade {
    final class Db
    {
        private static array $snapshots = [];
        public static function startTrans(): void { self::$snapshots[] = serialize([\plugin\SandIam\app\model\Environment::$rows, \plugin\SandIam\app\model\SecurityOperation::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]); }
        public static function commit(): void { array_pop(self::$snapshots); }
        public static function rollback(): void
        {
            [$environments, $operations, $audits] = unserialize(array_pop(self::$snapshots), ['allowed_classes' => true]);
            \plugin\SandIam\app\model\Environment::$rows = $environments;
            \plugin\SandIam\app\model\SecurityOperation::$rows = $operations;
            \plugin\SandIam\app\service\AuditWriter::$writes = $audits;
        }
    }
}

namespace plugin\SandIam\app\admin\support {
    use plugin\sandadmin\exception\ApiException;

    final class AdminOrganizationAccess
    {
        /** @var list<int> */ public static array $allowedApplicationIds = [10];
        public function __construct(int $adminId, ?array $adminInfo) {}
        public function isSuperAdmin(): bool { return false; }
        /** @return list<int> */ public function organizationIds(): array { return []; }
        /** @return list<int> */ public function applicationIds(): array { return self::$allowedApplicationIds; }
        public function assertApplication(int $applicationId): void
        {
            if (in_array($applicationId, self::$allowedApplicationIds, true)) return;
            throw new ApiException('SAND_IAM_APPLICATION_ACCESS_DENIED: 当前账号未获授该接入应用的管理范围', 403);
        }
        public function assertOrganization(int $organizationId): void { throw new ApiException('SAND_IAM_ORGANIZATION_ACCESS_DENIED', 403); }
        public function assertSuperAdmin(): void { throw new ApiException('SAND_IAM_ORGANIZATION_ACCESS_DENIED', 403); }
    }
}

namespace plugin\SandIam\app\model {
    final class FakeQuery
    {
        /** @var list<array{0:string,1:mixed}> */ private array $conditions = [];
        /** @param array<int, object> $rows */ public function __construct(private array $rows, string $field, mixed $value) { $this->conditions[] = [$field, $value]; }
        public function where(string $field, mixed $value): self { $this->conditions[] = [$field, $value]; return $this; }
        public function lock(bool $lock): self { return $this; }
        public function find(): ?object
        {
            foreach ($this->rows as $row) {
                foreach ($this->conditions as [$field, $value]) {
                    if (($row->{$field} ?? null) !== $value) continue 2;
                }
                return $row;
            }
            return null;
        }
    }

    final class Application
    {
        /** @var array<int, object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): FakeQuery { return new FakeQuery(self::$rows, $field, $value); }
        public static function find(int $id): ?object { return self::$rows[$id] ?? null; }
    }

    #[\AllowDynamicProperties]
    final class EnvironmentRecord
    {
        /** @param array<string, mixed> $values */ public function __construct(private array $values = [], private bool $empty = false) { foreach ($values as $key => $value) $this->{$key} = $value; }
        public function isEmpty(): bool { return $this->empty; }
        /** @param array<string, mixed> $payload */ public function save(array $payload): void { foreach ($payload as $key => $value) { $this->{$key} = $value; $this->values[$key] = $value; } }
    }

    final class Environment
    {
        /** @var array<int, EnvironmentRecord> */ public static array $rows = [];
        public static function create(array $payload): EnvironmentRecord
        {
            foreach (self::$rows as $row) {
                if ((int) $row->application_id === (int) $payload['application_id'] && (string) $row->code === (string) $payload['code']) {
                    throw new \RuntimeException('SQLSTATE[23505]: duplicate key value violates unique constraint "uk_sand_iam_environment_application_code"');
                }
            }
            $id = count(self::$rows) + 1;
            $row = new EnvironmentRecord(['id' => $id, ...$payload]);
            self::$rows[$id] = $row;
            return $row;
        }
        public static function find(int $id): ?EnvironmentRecord { return self::$rows[$id] ?? null; }
        public static function findOrEmpty(int $id): EnvironmentRecord { return self::find($id) ?? new EnvironmentRecord([], true); }
    }

    final class SecurityOperation
    {
        /** @var array<int, EnvironmentRecord> */ public static array $rows = [];
        public static function where(string $field, mixed $value): FakeQuery { return new FakeQuery(self::$rows, $field, $value); }
        public static function create(array $payload): EnvironmentRecord
        {
            $id = count(self::$rows) + 1;
            return self::$rows[$id] = new EnvironmentRecord(['id' => $id, ...$payload]);
        }
    }
}

namespace {
    use plugin\SandIam\app\admin\controller\EnvironmentController;
    use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Environment;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\sandadmin\exception\ApiException;
    use support\Request;

    function request(): object
    {
        return new class {
            public function header(string $key, mixed $default = null): mixed { return $key === 'check_admin' ? ['id' => 103] : $default; }
        };
    }

    require dirname(__DIR__) . '/app/service/RequestId.php';
    require dirname(__DIR__) . '/app/service/IdempotencyService.php';
    require dirname(__DIR__) . '/app/admin/support/AdminResourceController.php';
    require dirname(__DIR__) . '/app/admin/support/ApplicationResourceController.php';
    require dirname(__DIR__) . '/app/admin/controller/EnvironmentController.php';

    Application::$rows = [
        10 => (object) ['id' => 10, 'organization_id' => 7, 'status' => 1],
        11 => (object) ['id' => 11, 'organization_id' => 8, 'status' => 1],
    ];
    $controller = new EnvironmentController();
    $created = $controller->save(new Request(['application_id' => 10, 'code' => 'production', 'name' => '生产环境', 'status' => 1]));
    if (($created->data['id'] ?? 0) !== 1) throw new \RuntimeException('allowed application environment creation failed');
    $firstAudit = AuditWriter::$writes[0] ?? null;
    if ($firstAudit === null || $firstAudit['organization_id'] !== 7 || $firstAudit['application_id'] !== 10 || $firstAudit['action'] !== 'environment.create') {
        throw new \RuntimeException('environment create audit did not retain organization and application scope');
    }

    try {
        $controller->save(new Request(['application_id' => 10, 'code' => 'production', 'name' => '重复环境', 'status' => 1], [], 'environment-lifecycle-duplicate-002'));
        throw new \RuntimeException('duplicate environment code was accepted');
    } catch (ApiException $exception) {
        if ($exception->getCode() !== 409 || !str_contains($exception->getMessage(), 'SAND_IAM_ENVIRONMENT_CONFLICT') || !str_contains($exception->getMessage(), '已有环境')) {
            throw new \RuntimeException('duplicate environment code did not return the stable Chinese conflict');
        }
    }

    try {
        $controller->save(new Request(['application_id' => 11, 'code' => 'production', 'name' => '越权环境', 'status' => 1]));
        throw new \RuntimeException('foreign application environment creation was accepted');
    } catch (ApiException $exception) {
        if ($exception->getCode() !== 403 || !str_contains($exception->getMessage(), 'SAND_IAM_APPLICATION_ACCESS_DENIED')) {
            throw new \RuntimeException('foreign application rejection lost the stable scope error');
        }
    }

    $controller->disable(new Request(['id' => 1]));
    $controller->update(new Request(['id' => 1, 'status' => 1]));
    if ((int) (Environment::find(1)?->status ?? 0) !== 1) throw new \RuntimeException('environment restore via the frozen update API failed');
    foreach (['environment.disable', 'environment.update'] as $action) {
        $audit = array_values(array_filter(AuditWriter::$writes, static fn (array $write): bool => $write['action'] === $action))[0] ?? null;
        if ($audit === null || $audit['organization_id'] !== 7 || $audit['application_id'] !== 10) {
            throw new \RuntimeException("{$action} audit lost the application scope");
        }
    }

    foreach (AuditWriter::$writes as $audit) {
        if (($audit['request_id'] ?? null) !== 'environment-lifecycle-acceptance-001') {
            throw new \RuntimeException('lifecycle audit did not preserve the caller request ID');
        }
    }

    if (AdminOrganizationAccess::$allowedApplicationIds !== [10]) throw new \RuntimeException('test fixture scope changed unexpectedly');
    echo "environment lifecycle behavior non-PG test passed\n";
}
