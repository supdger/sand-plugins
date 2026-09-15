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
    final class Request
    {
        /** @param array<string, mixed> $post @param array<string, mixed> $input @param array<string, mixed> $headers */
        public function __construct(private array $post = [], private array $input = [], private array $headers = []) {}
        /** @return array<string, mixed> */ public function post(): array { return $this->post; }
        public function input(string $key, mixed $default = null): mixed { return $this->input[$key] ?? $this->post[$key] ?? $default; }
        public function header(string $key, mixed $default = null): mixed { return $this->headers[$key] ?? $default; }
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
        /** @var list<array{actor_ref:string,organization_id:?int,application_id:?int,action:string,resource_type:string,resource_id:?int,outcome:string,request_id:string}> */
        public static array $writes = [];
        public static bool $fail = false;

        public function write(string $actorType, string $actorRef, ?int $organizationId, ?int $applicationId, string $action, string $resourceType, ?int $resourceId, string $outcome, string $requestId): void
        {
            self::$writes[] = [
                'actor_ref' => $actorRef,
                'organization_id' => $organizationId,
                'application_id' => $applicationId,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'outcome' => $outcome,
                'request_id' => $requestId,
            ];
            if (self::$fail) throw new \RuntimeException('audit failure');
        }
    }
}

namespace think\facade {
    final class Db
    {
        private static array $snapshots = [];
        public static function startTrans(): void { self::$snapshots[] = serialize([\plugin\SandIam\app\model\AdminApplicationGrant::$rows, \plugin\SandIam\app\model\SecurityOperation::$rows, \plugin\SandIam\app\service\AuditWriter::$writes, \plugin\SandIam\app\model\AdminOrganizationGrant::$rows]); }
        public static function commit(): void { array_pop(self::$snapshots); }
        public static function rollback(): void
        {
            [$grants, $operations, $audits, $organizationGrants] = unserialize(array_pop(self::$snapshots), ['allowed_classes' => true]);
            \plugin\SandIam\app\model\AdminOrganizationGrant::$rows = $organizationGrants;
            \plugin\SandIam\app\model\AdminApplicationGrant::$rows = $grants;
            \plugin\SandIam\app\model\SecurityOperation::$rows = $operations;
            \plugin\SandIam\app\service\AuditWriter::$writes = $audits;
        }
    }
}

namespace plugin\SandIam\app\admin\support {
    use plugin\sandadmin\exception\ApiException;

    final class AdminOrganizationAccess
    {
        public static bool $superAdmin = false;
        public function __construct(int $adminId, ?array $adminInfo) {}
        public function isSuperAdmin(): bool { return self::$superAdmin; }
        /** @return list<int> */ public function organizationIds(): array { return [700]; }
        /** @return list<int> */ public function applicationIds(): array { return [21, 22]; }
        public function assertApplication(int $applicationId): void
        {
            if (in_array($applicationId, $this->applicationIds(), true)) return;
            throw new ApiException('SAND_IAM_APPLICATION_ACCESS_DENIED', 403);
        }
        public function assertOrganization(int $organizationId): void
        {
            if ($organizationId === 700) return;
            throw new ApiException('SAND_IAM_ORGANIZATION_ACCESS_DENIED', 403);
        }
        public function assertSuperAdmin(): void { if (!self::$superAdmin) throw new ApiException('SAND_IAM_ORGANIZATION_ACCESS_DENIED', 403); }
    }
}

namespace plugin\SandIam\app\model {
    final class FakeQuery
    {
        /** @var list<array{0:string,1:mixed}> */ private array $conditions = [];
        /** @param array<int, object> $rows */
        public function __construct(private array $rows, string $field, mixed $value) { $this->conditions[] = [$field, $value]; }
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
    final class GrantRecord
    {
        /** @param array<string, mixed> $values */
        public function __construct(private array $values = [], private bool $empty = false) { foreach ($values as $key => $value) $this->{$key} = $value; }
        public function isEmpty(): bool { return $this->empty; }
        /** @param array<string, mixed> $payload */
        public function save(array $payload): void { foreach ($payload as $key => $value) { $this->{$key} = $value; $this->values[$key] = $value; } }
        /** @return array<string, mixed> */
        public function toArray(): array { return $this->values; }
    }

    final class AdminApplicationGrant
    {
        /** @var array<int, GrantRecord> */ public static array $rows = [];
        public static function create(array $payload): GrantRecord
        {
            $id = count(self::$rows) + 1;
            $row = new GrantRecord(['id' => $id, ...$payload]);
            self::$rows[$id] = $row;
            return $row;
        }
        public static function find(int $id): ?GrantRecord { return self::$rows[$id] ?? null; }
        public static function findOrEmpty(int $id): GrantRecord { return self::find($id) ?? new GrantRecord([], true); }
    }

    final class SecurityOperation
    {
        /** @var array<int, GrantRecord> */ public static array $rows = [];
        public static function where(string $field, mixed $value): FakeQuery { return new FakeQuery(self::$rows, $field, $value); }
        public static function create(array $payload): GrantRecord
        {
            $id = count(self::$rows) + 1;
            return self::$rows[$id] = new GrantRecord(['id' => $id, ...$payload]);
        }
    }
    final class Organization {
        public static function where(string $field, mixed $value): FakeQuery { return new FakeQuery([new GrantRecord(['id' => 700, 'status' => 1])], $field, $value); }
    }
    final class AdminOrganizationGrant {
        public static array $rows = [];
        public static function create(array $payload): GrantRecord { $id = count(self::$rows) + 1; return self::$rows[$id] = new GrantRecord(['id' => $id, ...$payload]); }
        public static function find(int $id): ?GrantRecord { return self::$rows[$id] ?? null; }
        public static function findOrEmpty(int $id): GrantRecord { return self::find($id) ?? new GrantRecord([], true); }
    }
}

namespace plugin\sandadmin\app\model\system {
    use plugin\SandIam\app\model\FakeQuery;

    final class SystemUser
    {
        /** @var array<int, object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): FakeQuery { return new FakeQuery(self::$rows, $field, $value); }
    }
}

namespace {
    use plugin\SandIam\app\admin\controller\AdminApplicationGrantController;
    use plugin\SandIam\app\model\AdminApplicationGrant;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\sandadmin\app\model\system\SystemUser;
    use support\Request;

    function request(): object
    {
        return new class {
            /** @return array{id:int} */
            public function header(string $key, mixed $default = null): mixed { return $key === 'check_admin' ? ['id' => 9] : $default; }
        };
    }

    function auditBehaviorAssert(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }

    require dirname(__DIR__) . '/app/service/RequestId.php';
    require dirname(__DIR__) . '/app/service/IdempotencyService.php';
    require dirname(__DIR__) . '/app/admin/support/AdminResourceController.php';
    require dirname(__DIR__) . '/app/admin/support/ApplicationResourceController.php';
    require dirname(__DIR__) . '/app/admin/controller/AdminApplicationGrantController.php';

    Application::$rows = [
        21 => (object) ['id' => 21, 'organization_id' => 700, 'status' => 1],
        22 => (object) ['id' => 22, 'organization_id' => 700, 'status' => 1],
    ];
    SystemUser::$rows = [
        101 => (object) ['id' => 101, 'status' => 1],
        102 => (object) ['id' => 102, 'status' => 1],
    ];

    $controller = new AdminApplicationGrantController();
    $requestIds = [
        'create-one' => 'grant-create-one-0001',
        'create-two' => 'grant-create-two-0002',
        'update-one' => 'grant-update-one-0003',
        'disable-two' => 'grant-disable-two-004',
        'disable-one' => 'grant-disable-one-005',
    ];
    $headers = static fn (string $requestId): array => ['X-Request-Id' => $requestId, 'check_admin' => ['id' => 9]];

    $first = $controller->save(new Request(['admin_user_id' => 101, 'application_id' => 21, 'status' => 2], [], $headers($requestIds['create-one'])));
    $second = $controller->save(new Request(['admin_user_id' => 102, 'application_id' => 22, 'status' => 1], [], $headers($requestIds['create-two'])));
    auditBehaviorAssert($first->data === ['id' => 1] && $second->data === ['id' => 2], 'real saves must return their created grants');

    $controller->update(new Request(['id' => 1, 'status' => 1], [], $headers($requestIds['update-one'])));
    auditBehaviorAssert(AdminApplicationGrant::find(1)?->status === 1, 'real update must persist its changed grant status before later operations');
    $controller->disable(new Request(['id' => 2], [], $headers($requestIds['disable-two'])));
    $controller->disable(new Request(['id' => 1], [], $headers($requestIds['disable-one'])));

    auditBehaviorAssert(AdminApplicationGrant::find(1)?->status === 2 && AdminApplicationGrant::find(2)?->status === 2, 'real disable operations must persist disabled status');
    $firstRead = $controller->read(new Request([], ['id' => 1], $headers('grant-read-one-00006')));
    $secondRead = $controller->read(new Request([], ['id' => 2], $headers('grant-read-two-00007')));
    auditBehaviorAssert($firstRead->data['status'] === 2 && $secondRead->data['status'] === 2, 'public reads must expose the disabled state of each grant');

    $expected = [
        ['action' => 'admin_application_grant.create', 'resource_id' => 1, 'application_id' => 21, 'request_id' => $requestIds['create-one']],
        ['action' => 'admin_application_grant.create', 'resource_id' => 2, 'application_id' => 22, 'request_id' => $requestIds['create-two']],
        ['action' => 'admin_application_grant.update', 'resource_id' => 1, 'application_id' => 21, 'request_id' => $requestIds['update-one']],
        ['action' => 'admin_application_grant.disable', 'resource_id' => 2, 'application_id' => 22, 'request_id' => $requestIds['disable-two']],
        ['action' => 'admin_application_grant.disable', 'resource_id' => 1, 'application_id' => 21, 'request_id' => $requestIds['disable-one']],
    ];
    $observed = array_map(static fn (array $write): array => [
        'action' => $write['action'],
        'resource_id' => $write['resource_id'],
        'application_id' => $write['application_id'],
        'request_id' => $write['request_id'],
    ], AuditWriter::$writes);
    auditBehaviorAssert($observed === $expected, 'each real create, update, and disable must emit its own matching audit request id without cross-request leakage');
    auditBehaviorAssert(count(array_unique(array_column($observed, 'request_id'))) === 5, 'interleaved grant operations must not reuse a different request audit id');
    foreach (AuditWriter::$writes as $write) {
        auditBehaviorAssert($write['actor_ref'] === '9' && $write['organization_id'] === 700 && $write['outcome'] === 'succeeded', 'audit sink must retain the actor and application ownership for every operation');
    }

    echo "admin application grant audit behavior non-pg test passed\n";
    foreach (['update', 'disable'] as $method) {
        AdminApplicationGrant::find(1)->save(['status' => $method === 'update' ? 2 : 1]);
        $before = serialize([AdminApplicationGrant::$rows, AuditWriter::$writes]);
        $request = new Request(['id' => 1, 'status' => 1], [], $headers('grant-atomic-' . $method));
        AuditWriter::$fail = true;
        try { $controller->$method($request); throw new \RuntimeException('Audit failure ignored'); }
        catch (\RuntimeException $error) { auditBehaviorAssert($error->getMessage() === 'audit failure', $error->getMessage()); }
        finally { AuditWriter::$fail = false; }
        auditBehaviorAssert(serialize([AdminApplicationGrant::$rows, AuditWriter::$writes]) === $before, 'Failed mutation retained delegation or audit');
        $controller->$method($request);
        auditBehaviorAssert(AdminApplicationGrant::find(1)->status === ($method === 'update' ? 1 : 2), 'Delegation recovery failed');
    }
    echo "admin application grant mutation audit rollback behavior PASS\n";
    require dirname(__DIR__) . '/app/admin/controller/AdminOrganizationGrantController.php';
    $organizationController = new \plugin\SandIam\app\admin\controller\AdminOrganizationGrantController();
    $snapshot = static fn (): string => serialize([\plugin\SandIam\app\model\AdminOrganizationGrant::$rows, \plugin\SandIam\app\model\SecurityOperation::$rows, AuditWriter::$writes]);
    foreach (['save', 'update', 'disable'] as $method) {
        \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$superAdmin = false;
        $request = new Request(['id' => 1, 'admin_user_id' => 101, 'organization_id' => 700, 'status' => 1], [], $headers('organization-grant-' . $method));
        $before = $snapshot();
        try { $organizationController->$method($request); throw new \RuntimeException('Non-super administrator accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { auditBehaviorAssert($error->getCode() === 403, 'Wrong super-admin error'); }
        auditBehaviorAssert($snapshot() === $before, 'Rejected organization grant changed state');
        \plugin\SandIam\app\admin\support\AdminOrganizationAccess::$superAdmin = true;
        if ($method === 'update') \plugin\SandIam\app\model\AdminOrganizationGrant::find(1)->save(['status' => 2]);
        $before = $snapshot();
        AuditWriter::$fail = true;
        try { $organizationController->$method($request); throw new \RuntimeException('Organization audit failure ignored'); }
        catch (\RuntimeException $error) { auditBehaviorAssert($error->getMessage() === 'audit failure', $error->getMessage()); }
        finally { AuditWriter::$fail = false; }
        auditBehaviorAssert($snapshot() === $before, 'Failed organization grant retained state or audit');
        $organizationController->$method($request);
        auditBehaviorAssert(\plugin\SandIam\app\model\AdminOrganizationGrant::find(1)->status === ($method === 'disable' ? 2 : 1), 'Organization grant recovery failed');
        $last = AuditWriter::$writes[count(AuditWriter::$writes) - 1];
        auditBehaviorAssert($last['organization_id'] === 700 && $last['application_id'] === null, 'Organization audit scope incorrect');
    }
    echo "organization delegation permission and audit atomicity behavior PASS\n";
}
