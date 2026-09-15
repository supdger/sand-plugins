<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace plugin\sandadmin\service {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class Permission { public function __construct(public string $name, public string $code) {} }
}

namespace support {
    final class Request
    {
        /** @var array<string,mixed> */ private array $attributes = [];
        /** @param array<string,mixed> $post @param array<string,mixed> $headers */
        public function __construct(private array $post, private array $headers) {}
        public function post(?string $key = null, mixed $default = null): mixed { return $key === null ? $this->post : ($this->post[$key] ?? $default); }
        public function input(string $key, mixed $default = null): mixed { return $this->post[$key] ?? $default; }
        public function header(string $key, mixed $default = null): mixed { return $this->headers[$key] ?? $default; }
        public function setAttribute(string $key, mixed $value): void { $this->attributes[$key] = $value; }
        public function getAttribute(string $key): mixed { return $this->attributes[$key] ?? null; }
    }

    final class Response { public function __construct(public mixed $data = null, public string $message = '') {} }
}

namespace plugin\sandadmin\basic {
    use support\Response;
    class BaseController { protected function success(mixed $data = null, string $message = ''): Response { return new Response($data, $message); } }
}

namespace plugin\SandIam\app\service {
    final class AuditWriter
    {
        /** @var list<array{action:string,outcome:string,resource_id:?int}> */ public static array $writes = [];
        public static bool $fail = false;
        public function write(string $actorType, string $actorRef, ?int $organizationId, ?int $applicationId, string $action, string $resourceType, ?int $resourceId, string $outcome, string $requestId): void
        {
            self::$writes[] = compact('action', 'outcome', 'resourceId') + ['resource_id' => $resourceId];
            if (self::$fail) throw new \RuntimeException('audit unavailable');
        }
    }
}

namespace think\facade {
    final class Db
    {
        private static array $snapshots = [];
        public static function startTrans(): void
        {
            self::$snapshots[] = serialize([\plugin\SandIam\app\model\Application::$rows, \plugin\SandIam\app\model\Organization::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]);
        }
        public static function commit(): void { array_pop(self::$snapshots); }
        public static function rollback(): void
        {
            [\plugin\SandIam\app\model\Application::$rows, \plugin\SandIam\app\model\Organization::$rows, \plugin\SandIam\app\service\AuditWriter::$writes] = unserialize(array_pop(self::$snapshots), ['allowed_classes' => true]);
        }
    }
}

namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    final class Record
    {
        /** @param array<string,mixed> $values */
        public function __construct(array $values = [], private bool $empty = false) { foreach ($values as $key => $value) $this->{$key} = $value; }
        public function isEmpty(): bool { return $this->empty; }
        /** @param array<string,mixed> $payload */
        public function save(array $payload): void { foreach ($payload as $key => $value) $this->{$key} = $value; }
        /** @return array<string,mixed> */ public function toArray(): array { return get_object_vars($this); }
    }

    final class Query
    {
        /** @var list<array{0:string,1:mixed,2:string}> */ private array $conditions = [];
        /** @param array<int,Record> $rows */ public function __construct(private array $rows, ?string $field = null, mixed $value = null) { if ($field !== null) $this->conditions[] = [$field, $value, 'equals']; }
        public function where(string $field, mixed $value): self { $this->conditions[] = [$field, $value, 'equals']; return $this; }
        /** @param list<mixed> $values */ public function whereIn(string $field, array $values): self { $this->conditions[] = [$field, $values, 'in']; return $this; }
        /** @return list<Record> */ private function matches(): array { return array_values(array_filter($this->rows, function (Record $row): bool { foreach ($this->conditions as [$field, $value, $mode]) { if ($mode === 'equals' && ($row->{$field} ?? null) !== $value) return false; if ($mode === 'in' && !in_array($row->{$field} ?? null, $value, true)) return false; } return true; })); }
        public function find(): ?Record { return $this->matches()[0] ?? null; }
        /** @return list<mixed> */ public function column(string $field): array { return array_values(array_map(static fn (Record $row): mixed => $row->{$field}, $this->matches())); }
    }

    final class Application
    {
        /** @var array<int,Record> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
        /** @param list<mixed> $values */ public static function whereIn(string $field, array $values): Query { return (new Query(self::$rows))->whereIn($field, $values); }
        public static function find(int $id): ?Record { return self::$rows[$id] ?? null; }
        public static function findOrEmpty(int $id): Record { return self::find($id) ?? new Record([], true); }
    }
    final class Organization
    {
        /** @var array<int,Record> */ public static array $rows = [];
        public static function find(int $id): ?Record { return self::$rows[$id] ?? null; }
        public static function findOrEmpty(int $id): Record { return self::find($id) ?? new Record([], true); }
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
        /** @param list<mixed> $values */ public static function whereIn(string $field, array $values): Query { return (new Query(self::$rows))->whereIn($field, $values); }
    }
    final class AdminOrganizationGrant
    {
        /** @var array<int,Record> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
    final class AdminApplicationGrant
    {
        /** @var array<int,Record> */ public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
}

namespace {
    use plugin\SandIam\app\admin\controller\ApplicationController;
    use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
    use plugin\SandIam\app\model\AdminApplicationGrant;
    use plugin\SandIam\app\model\AdminOrganizationGrant;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Organization;
    use plugin\SandIam\app\model\Record;
    use plugin\sandadmin\exception\ApiException;
    use plugin\sandadmin\service\Permission;
    use support\Request;

    function request(): object { global $applicationRecoveryRequest; return $applicationRecoveryRequest; }
    function applicationRecoveryAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    function applicationRecoveryExpect(callable $operation, int $code, string $message): void { try { $operation(); } catch (ApiException $exception) { applicationRecoveryAssert($exception->getCode() === $code, $message . ' (unexpected status)'); return; } throw new RuntimeException($message . ' (not rejected)'); }
    function applicationRecoveryRequest(int $adminId, array $post): Request { global $applicationRecoveryRequest; $applicationRecoveryRequest = new Request($post, ['check_admin' => ['id' => $adminId], 'X-Request-Id' => 'application-recovery-test-' . bin2hex(random_bytes(4))]); return $applicationRecoveryRequest; }

    require dirname(__DIR__) . '/app/service/RequestId.php';
    require dirname(__DIR__) . '/app/admin/support/AdminOrganizationAccess.php';
    require dirname(__DIR__) . '/app/admin/support/AdminResourceController.php';
    require dirname(__DIR__) . '/app/admin/controller/ApplicationController.php';
    require dirname(__DIR__) . '/app/admin/controller/OrganizationController.php';

    Organization::$rows = [
        10 => new Record(['id' => 10, 'status' => 1]),
        20 => new Record(['id' => 20, 'status' => 1]),
        30 => new Record(['id' => 30, 'status' => 2]),
    ];
    Application::$rows = [
        101 => new Record(['id' => 101, 'organization_id' => 10, 'status' => 2, 'code' => 'recoverable', 'name' => 'Recoverable']),
        201 => new Record(['id' => 201, 'organization_id' => 20, 'status' => 2, 'code' => 'cross-org', 'name' => 'Cross organization']),
        301 => new Record(['id' => 301, 'organization_id' => 30, 'status' => 2, 'code' => 'inactive-parent', 'name' => 'Inactive parent']),
    ];
    AdminOrganizationGrant::$rows = [
        1 => new Record(['admin_user_id' => 2, 'organization_id' => 10, 'status' => 1]),
        2 => new Record(['admin_user_id' => 3, 'organization_id' => 20, 'status' => 1]),
        3 => new Record(['admin_user_id' => 4, 'organization_id' => 10, 'status' => 2]),
    ];
    AdminApplicationGrant::$rows = [1 => new Record(['admin_user_id' => 5, 'application_id' => 101, 'status' => 1])];

    $super = new AdminOrganizationAccess(1, ['id' => 1]);
    $organizationAdmin = new AdminOrganizationAccess(2, ['id' => 2]);
    $applicationAdmin = new AdminOrganizationAccess(5, ['id' => 5]);
    applicationRecoveryExpect(static fn () => $super->assertApplication(101), 403, 'ordinary active-app access must still reject the disabled target before recovery');
    $super->assertApplicationRecovery(101);
    $organizationAdmin->assertApplicationRecovery(101);
    applicationRecoveryExpect(static fn () => $applicationAdmin->assertApplicationRecovery(101), 403, 'application delegate must not recover its disabled application');
    applicationRecoveryExpect(static fn () => $organizationAdmin->assertApplicationRecovery(201), 403, 'cross-organization administrator must not recover another organization application');
    applicationRecoveryExpect(static fn () => (new AdminOrganizationAccess(4, ['id' => 4]))->assertApplicationRecovery(101), 403, 'inactive organization grant must not recover an application');
    applicationRecoveryExpect(static fn () => $super->assertApplicationRecovery(301), 403, 'inactive parent organization must block recovery even for super administrator');
    applicationRecoveryAssert($applicationAdmin->applicationIds() === [], 'default applicationIds must keep disabled application grants out of ordinary scope');

    $permission = (new ReflectionMethod(ApplicationController::class, 'update'))->getAttributes(Permission::class)[0]->newInstance();
    applicationRecoveryAssert($permission->code === 'sand_iam:application:update', 'recovery must retain the application update permission gate');
    $source = file_get_contents(dirname(__DIR__) . '/app/admin/controller/ApplicationController.php');
    applicationRecoveryAssert(is_string($source) && substr_count($source, 'assertUpdatePayloadAccess($payload, $model, $recovering);') === 2, 'post-normalization payload validation must remain on the controlled recovery path');

    $controller = new ApplicationController();
    applicationRecoveryExpect(static fn () => $controller->update(applicationRecoveryRequest(5, ['id' => 101, 'status' => 1])), 403, 'application delegate controller recovery must be denied');
    applicationRecoveryExpect(static fn () => $controller->update(applicationRecoveryRequest(2, ['id' => 201, 'status' => 1])), 403, 'cross-organization controller recovery must be denied');
    applicationRecoveryExpect(static fn () => $controller->update(applicationRecoveryRequest(1, ['id' => 301, 'status' => 1])), 403, 'controller recovery must reject inactive parent organization');
    applicationRecoveryExpect(static fn () => $controller->update(applicationRecoveryRequest(1, ['id' => 101, 'status' => 1, 'name' => 'Mutated while recovering'])), 400, 'recovery must reject name mutation payloads');
    applicationRecoveryAssert((int) Application::$rows[101]->status === 2 && Application::$rows[101]->name === 'Recoverable' && (int) Application::$rows[101]->organization_id === 10, 'rejected recovery name mutation must leave application unchanged');
    applicationRecoveryExpect(static fn () => $controller->update(applicationRecoveryRequest(1, ['id' => 101, 'status' => 1, 'organization_id' => 20])), 400, 'recovery must reject ownership migration payloads');
    applicationRecoveryAssert((int) Application::$rows[101]->status === 2 && Application::$rows[101]->name === 'Recoverable' && (int) Application::$rows[101]->organization_id === 10, 'rejected recovery ownership mutation must leave application unchanged');
    applicationRecoveryExpect(static fn () => $controller->update(applicationRecoveryRequest(1, ['id' => 101, 'status' => 1, 'code' => 'mutated-code'])), 400, 'recovery must reject every other writable field');
    applicationRecoveryAssert((int) Application::$rows[101]->status === 2 && Application::$rows[101]->name === 'Recoverable' && (int) Application::$rows[101]->organization_id === 10, 'rejected recovery code mutation must leave application unchanged');
    applicationRecoveryExpect(static fn () => $controller->update(applicationRecoveryRequest(1, ['id' => 101, 'status' => 0])), 400, 'recovery must reject illegal target status');

    $before = serialize([Application::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]);
    \plugin\SandIam\app\service\AuditWriter::$fail = true;
    try {
        $controller->update(applicationRecoveryRequest(1, ['id' => 101, 'status' => 1]));
        throw new RuntimeException('recovery hid audit failure');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'audit unavailable') throw $exception;
    } finally {
        \plugin\SandIam\app\service\AuditWriter::$fail = false;
    }
    applicationRecoveryAssert(serialize([Application::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]) === $before, 'failed audit must not restore the application or retain a success audit');
    applicationRecoveryExpect(static fn () => $super->assertApplication(101), 403, 'failed recovery must keep ordinary access denied');

    $result = $controller->update(applicationRecoveryRequest(1, ['id' => 101, 'status' => 1]));
    applicationRecoveryAssert($result->data === '更新成功' && (int) Application::$rows[101]->status === 1, 'super administrator recovery must enable the application');
    $super->assertApplication(101);

    Application::$rows[101]->status = 2;
    $result = $controller->update(applicationRecoveryRequest(2, ['id' => 101, 'status' => 1]));
    applicationRecoveryAssert($result->data === '更新成功' && (int) Application::$rows[101]->status === 1, 'effective organization administrator recovery must enable the application');
    $organizationAdmin->assertApplication(101);

    $before = serialize([Application::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]);
    \plugin\SandIam\app\service\AuditWriter::$fail = true;
    try {
        $controller->update(applicationRecoveryRequest(2, ['id' => 101, 'name' => 'Uncommitted rename']));
        throw new RuntimeException('ordinary update hid audit failure');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'audit unavailable') throw $exception;
    } finally {
        \plugin\SandIam\app\service\AuditWriter::$fail = false;
    }
    applicationRecoveryAssert(serialize([Application::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]) === $before, 'failed ordinary update must preserve the application and audit');

    Organization::$rows[10]->name = '客户主体';
    foreach ([
        [$controller, Application::$rows[101], 101],
        [new \plugin\SandIam\app\admin\controller\OrganizationController(), Organization::$rows[10], 10],
    ] as [$nameController, $record, $id]) {
        foreach (['', '  ', "\t\r\n", null, 123, []] as $invalidName) {
            $before = serialize([$record, \plugin\SandIam\app\service\AuditWriter::$writes]);
            applicationRecoveryExpect(static fn () => $nameController->update(applicationRecoveryRequest(1, ['id' => $id, 'name' => $invalidName])), 400, 'invalid application or organization name must be rejected');
            applicationRecoveryAssert(serialize([$record, \plugin\SandIam\app\service\AuditWriter::$writes]) === $before, 'invalid name must preserve record and audit');
        }
        $nameController->update(applicationRecoveryRequest(1, ['id' => $id, 'name' => '  正式名称  ']));
        applicationRecoveryAssert($record->name === '正式名称', 'valid name must be trimmed');
    }

    $before = serialize([Application::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]);
    \plugin\SandIam\app\service\AuditWriter::$fail = true;
    try {
        $controller->disable(applicationRecoveryRequest(2, ['id' => 101]));
        throw new RuntimeException('disable hid audit failure');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'audit unavailable') throw $exception;
    } finally {
        \plugin\SandIam\app\service\AuditWriter::$fail = false;
    }
    applicationRecoveryAssert(serialize([Application::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]) === $before, 'failed disable must preserve application and audit');
    $organizationAdmin->assertApplication(101);
    $auditCount = count(\plugin\SandIam\app\service\AuditWriter::$writes);
    applicationRecoveryExpect(static fn () => $controller->disable(applicationRecoveryRequest(3, ['id' => 101])), 403, 'foreign organization must not disable application');
    $denial = end(\plugin\SandIam\app\service\AuditWriter::$writes);
    applicationRecoveryAssert((int) Application::$rows[101]->status === 1
        && count(\plugin\SandIam\app\service\AuditWriter::$writes) === $auditCount + 1
        && $denial['action'] === 'application.access' && $denial['outcome'] === 'denied', 'denied application disable must retain denial audit');
    $controller->disable(applicationRecoveryRequest(2, ['id' => 101]));
    applicationRecoveryAssert((int) Application::$rows[101]->status === 2, 'successful disable must change status');
    applicationRecoveryExpect(static fn () => $organizationAdmin->assertApplication(101), 403, 'successful disable must deny ordinary access');

    $organizationController = new \plugin\SandIam\app\admin\controller\OrganizationController();
    foreach ([['name' => '不应保存'], ['status' => 1]] as $payload) {
        Organization::$rows[10]->status = 2;
        $before = serialize([Organization::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]);
        \plugin\SandIam\app\service\AuditWriter::$fail = true;
        try {
            $organizationController->update(applicationRecoveryRequest(1, ['id' => 10] + $payload));
            throw new RuntimeException('organization update hid audit failure');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'audit unavailable') throw $exception;
        } finally {
            \plugin\SandIam\app\service\AuditWriter::$fail = false;
        }
        applicationRecoveryAssert(serialize([Organization::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]) === $before, 'failed organization update must preserve record and audit');
    }
    $organizationController->update(applicationRecoveryRequest(1, ['id' => 10, 'status' => 1]));
    applicationRecoveryAssert((int) Organization::$rows[10]->status === 1 && Organization::$rows[10]->name === '正式名称', 'organization recovery retry must succeed without a failed rename');

    echo "application recovery authorization non-PG behavior checks passed\n";
}
