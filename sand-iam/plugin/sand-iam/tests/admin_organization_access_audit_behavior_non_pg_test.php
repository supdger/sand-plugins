<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace support {
    final class Request
    {
        /** @var array<string,mixed> */
        private array $attributes = [];

        /** @param array<string,mixed> $headers */
        public function __construct(private readonly array $headers = []) {}

        public function header(string $key, mixed $default = null): mixed { return $this->headers[$key] ?? $default; }
        public function setAttribute(string $key, mixed $value): void { $this->attributes[$key] = $value; }
        public function getAttribute(string $key): mixed { return $this->attributes[$key] ?? null; }
    }
}

namespace plugin\SandIam\app\service {
    final class AuditWriter
    {
        /** @var list<array{action:string,outcome:string,request_id:string}> */
        public static array $writes = [];

        public function write(string $actorType, string $actorRef, ?int $organizationId, ?int $applicationId, string $action, string $resourceType, ?int $resourceId, string $outcome, string $requestId): void
        {
            self::$writes[] = compact('action', 'outcome', 'requestId') + ['request_id' => $requestId];
        }
    }
}

namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    final class Record
    {
        /** @param array<string,mixed> $values */
        public function __construct(array $values) { foreach ($values as $key => $value) $this->{$key} = $value; }
    }

    final class Query
    {
        /** @var list<array{field:string,value:mixed,in:bool}> */
        private array $conditions = [];

        /** @param array<int,Record> $rows */
        public function __construct(private readonly array $rows, ?string $field = null, mixed $value = null)
        {
            if ($field !== null) $this->conditions[] = ['field' => $field, 'value' => $value, 'in' => false];
        }

        public function where(string $field, mixed $value): self { $this->conditions[] = ['field' => $field, 'value' => $value, 'in' => false]; return $this; }
        /** @param list<mixed> $values */
        public function whereIn(string $field, array $values): self { $this->conditions[] = ['field' => $field, 'value' => $values, 'in' => true]; return $this; }
        public function find(): ?Record { return $this->matches()[0] ?? null; }
        /** @return list<mixed> */
        public function column(string $field): array { return array_map(static fn (Record $row): mixed => $row->{$field}, $this->matches()); }
        /** @return list<Record> */
        private function matches(): array
        {
            return array_values(array_filter($this->rows, function (Record $row): bool {
                foreach ($this->conditions as $condition) {
                    $actual = $row->{$condition['field']} ?? null;
                    if ($condition['in'] ? !in_array($actual, $condition['value'], true) : $actual !== $condition['value']) return false;
                }
                return true;
            }));
        }
    }

    final class Application
    {
        /** @var array<int,Record> */
        public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
        /** @param list<mixed> $values */
        public static function whereIn(string $field, array $values): Query { return (new Query(self::$rows))->whereIn($field, $values); }
    }

    final class Organization
    {
        /** @var array<int,Record> */
        public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
        /** @param list<mixed> $values */
        public static function whereIn(string $field, array $values): Query { return (new Query(self::$rows))->whereIn($field, $values); }
    }

    final class AdminOrganizationGrant
    {
        /** @var array<int,Record> */
        public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }

    final class AdminApplicationGrant
    {
        /** @var array<int,Record> */
        public static array $rows = [];
        public static function where(string $field, mixed $value): Query { return new Query(self::$rows, $field, $value); }
    }
}

namespace {
    use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Organization;
    use plugin\SandIam\app\model\Record;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\sandadmin\exception\ApiException;
    use support\Request;

    function request(): ?Request { global $adminOrganizationAccessRequest; return $adminOrganizationAccessRequest; }
    function accessAuditAssert(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    function accessAuditDenied(callable $operation, string $message): void
    {
        try { $operation(); } catch (ApiException $exception) { accessAuditAssert($exception->getCode() === 403, $message . ' must remain a 403'); return; }
        throw new \RuntimeException($message . ' must reject');
    }
    function accessAuditRequest(string $requestId): void { global $adminOrganizationAccessRequest; $adminOrganizationAccessRequest = new Request(['X-Request-Id' => $requestId]); }
    function accessAuditLastRequestId(): string { return AuditWriter::$writes[array_key_last(AuditWriter::$writes)]['request_id']; }

    require dirname(__DIR__) . '/app/service/RequestId.php';
    require dirname(__DIR__) . '/app/admin/support/AdminOrganizationAccess.php';

    Organization::$rows = [10 => new Record(['id' => 10, 'status' => 1])];
    Application::$rows = [
        101 => new Record(['id' => 101, 'organization_id' => 10, 'status' => 1]),
        102 => new Record(['id' => 102, 'organization_id' => 10, 'status' => 2]),
    ];
    $access = new AdminOrganizationAccess(7, ['id' => 7]);

    foreach ([
        'access-application-0001' => static fn () => $access->assertApplication(101),
        'access-recovery-0002' => static fn () => $access->assertApplicationRecovery(102),
        'access-organization-0003' => static fn () => $access->assertOrganization(10),
        'access-super-admin-0004' => static fn () => $access->assertSuperAdmin(),
    ] as $requestId => $operation) {
        accessAuditRequest($requestId);
        accessAuditDenied($operation, $requestId);
        accessAuditAssert(accessAuditLastRequestId() === $requestId, $requestId . ' denial audit must retain the current HTTP request id');
    }

    $adminOrganizationAccessRequest = null;
    accessAuditDenied(static fn () => $access->assertApplication(101), 'non-HTTP application denial');
    $fallback = accessAuditLastRequestId();
    accessAuditAssert(preg_match('/^req_[a-f0-9]{32}$/', $fallback) === 1, 'non-HTTP denial must use a valid generated fallback request id');
    accessAuditAssert(!in_array($fallback, ['access-application-0001', 'access-recovery-0002', 'access-organization-0003', 'access-super-admin-0004'], true), 'non-HTTP fallback must not leak an HTTP request id');

    echo "admin organization access audit behavior non-PG test passed\n";
    $organizationGrant = new Record(['admin_user_id' => 7, 'organization_id' => 10, 'status' => 1]);
    $applicationGrant = new Record(['admin_user_id' => 7, 'application_id' => 101, 'status' => 1]);
    \plugin\SandIam\app\model\AdminOrganizationGrant::$rows = [$organizationGrant];
    \plugin\SandIam\app\model\AdminApplicationGrant::$rows = [$applicationGrant];
    accessAuditAssert($access->organizationIds() === [10] && $access->applicationIds() === [101], 'Active grants must resolve and deduplicate scopes');
    $access->assertOrganization(10);
    $access->assertApplication(101);
    $organizationGrant->status = 2;
    accessAuditAssert($access->organizationIds() === [] && $access->applicationIds() === [101], 'Organization revocation must preserve independent application grant');
    accessAuditDenied(static fn () => $access->assertOrganization(10), 'revoked organization grant');
    $access->assertApplication(101);
    $applicationGrant->status = 2;
    accessAuditAssert($access->applicationIds() === [], 'Revoked application grant cached');
    accessAuditDenied(static fn () => $access->assertApplication(101), 'all sources revoked');
    $organizationGrant->status = 1;
    $access->assertApplication(101);
    accessAuditAssert($access->applicationIds() === [101], 'Restored organization delegation must apply without new resolver');
    Organization::$rows[10]->status = 2;
    accessAuditAssert($access->organizationIds() === [] && $access->applicationIds() === [], 'Disabled organization scope leaked');
    accessAuditDenied(static fn () => $access->assertApplication(101), 'disabled organization');
    Organization::$rows[10]->status = 1;
    Application::$rows[101]->status = 2;
    accessAuditAssert($access->applicationIds() === [], 'Disabled application scope leaked');
    accessAuditDenied(static fn () => $access->assertApplication(101), 'disabled application');
    Application::$rows[101]->status = 1;
    $access->assertApplication(101);
    echo "delegation live scope revocation and restoration behavior PASS (same resolver, non-PG)\n";
}
