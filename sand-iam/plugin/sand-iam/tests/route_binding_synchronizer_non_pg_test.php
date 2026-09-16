<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace think\facade {
    final class Db
    {
        public static array $snapshots = [];
        public static function startTrans(): void
        {
            self::$snapshots[] = serialize([\plugin\SandIam\app\model\ApiRouteBinding::$rows, \plugin\SandIam\app\service\AuditWriter::$records]);
        }
        public static function commit(): void { array_pop(self::$snapshots); }
        public static function rollback(): void
        {
            if (self::$snapshots === []) throw new \RuntimeException('Rollback without transaction');
            [\plugin\SandIam\app\model\ApiRouteBinding::$rows, \plugin\SandIam\app\service\AuditWriter::$records] = unserialize(array_pop(self::$snapshots), ['allowed_classes' => true]);
        }
    }
}

namespace plugin\SandIam\app\service {
    final class RequestId
    {
        public static function normalize(string $value): string { return $value !== '' ? $value : 'req_generated_00000000000000000000000000000000'; }
    }
}

namespace plugin\SandIam\app\model {
    final class MemoryQuery
    {
        /** @var array<string,mixed> */ private array $where = [];
        /** @param class-string $model */ public function __construct(private string $model) {}
        public function where(string $field, mixed $value): self { $this->where[$field] = $value; return $this; }
        public function lock(bool $lock): self { return $this; }
        public function find(): ?object { foreach ($this->all() as $row) return $row; return null; }
        public function select(): self { return $this; }
        /** @return list<object> */ public function all(): array
        {
            return array_values(array_filter($this->model::$rows, function (object $row): bool {
                foreach ($this->where as $field => $value) if (($row->{$field} ?? null) !== $value) return false;
                return true;
            }));
        }
    }

    trait MemoryModel
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): MemoryQuery { return (new MemoryQuery(self::class))->where($field, $value); }
    }
    #[\AllowDynamicProperties] final class Organization { use MemoryModel; }
    #[\AllowDynamicProperties] final class Application { use MemoryModel; }
    #[\AllowDynamicProperties] final class Environment { use MemoryModel; }
    #[\AllowDynamicProperties] final class ApiResource { use MemoryModel; }
    #[\AllowDynamicProperties] final class ApplicationBusinessAction { use MemoryModel; }
    #[\AllowDynamicProperties] final class ApiRouteBinding
    {
        use MemoryModel;
        /** @param array<string,mixed> $payload */
        public static function create(array $payload): self
        {
            $binding = new self();
            $binding->id = max(array_map(static fn (object $row): int => (int) $row->id, self::$rows) ?: [0]) + 1;
            foreach ($payload as $field => $value) $binding->{$field} = $value;
            self::$rows[] = $binding;
            return $binding;
        }
        /** @param array<string,mixed> $payload */
        public function save(array $payload): void { foreach ($payload as $field => $value) $this->{$field} = $value; }
    }
}

namespace plugin\SandIam\app\service {
    final class AuditWriter
    {
        /** @var list<array<int,mixed>> */ public static array $records = [];
        public static ?int $failAt = null;
        public function write(mixed ...$arguments): void
        {
            self::$records[] = $arguments;
            if (self::$failAt === count(self::$records)) throw new \RuntimeException('injected route audit failure');
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/developer/RouteSyncManifest.php';
    require_once dirname(__DIR__) . '/app/developer/ApplicationBusinessActionCatalog.php';
    require_once dirname(__DIR__) . '/app/runtime/ApiGovernanceService.php';
    require_once dirname(__DIR__) . '/app/runtime/RouteBindingSynchronizer.php';

    use plugin\SandIam\app\developer\RouteSyncManifest;
    use plugin\SandIam\app\model\ApiResource;
    use plugin\SandIam\app\model\ApplicationBusinessAction;
    use plugin\SandIam\app\model\ApiRouteBinding;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Environment;
    use plugin\SandIam\app\model\Organization;
    use plugin\SandIam\app\runtime\RouteBindingSynchronizer;
    use plugin\sandadmin\exception\ApiException;

    function routeBindingSyncAssert(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    }
    /** @param class-string $class */
    function routeBindingSyncRow(string $class, array $values): object
    {
        $row = new $class();
        foreach ($values as $field => $value) $row->{$field} = $value;
        return $row;
    }

    Organization::$rows = [routeBindingSyncRow(Organization::class, ['id' => 1, 'code' => 'sand', 'status' => 1])];
    Application::$rows = [routeBindingSyncRow(Application::class, ['id' => 2, 'organization_id' => 1, 'code' => 'lawyer', 'status' => 1])];
    Environment::$rows = [routeBindingSyncRow(Environment::class, ['id' => 3, 'application_id' => 2, 'code' => 'production', 'status' => 1])];
    ApiResource::$rows = [
        routeBindingSyncRow(ApiResource::class, ['id' => 4, 'application_id' => 2, 'code' => 'legal.case.read', 'action' => 'legal.case.read', 'api_version' => 'v1', 'status' => 1]),
        routeBindingSyncRow(ApiResource::class, ['id' => 5, 'application_id' => 2, 'code' => 'legal.case.create', 'action' => 'legal.case.create', 'api_version' => 'v1', 'status' => 1]),
        routeBindingSyncRow(ApiResource::class, ['id' => 6, 'application_id' => 2, 'code' => 'legal.case.update', 'action' => 'legal.case.update', 'api_version' => 'v1', 'status' => 1]),
    ];
    ApplicationBusinessAction::$rows = [
        routeBindingSyncRow(ApplicationBusinessAction::class, ['id' => 21, 'application_id' => 2, 'code' => 'legal.case.read', 'status' => 1]),
        routeBindingSyncRow(ApplicationBusinessAction::class, ['id' => 22, 'application_id' => 2, 'code' => 'legal.case.create', 'status' => 1]),
        routeBindingSyncRow(ApplicationBusinessAction::class, ['id' => 23, 'application_id' => 2, 'code' => 'legal.case.update', 'status' => 1]),
    ];
    ApiRouteBinding::$rows = [
        routeBindingSyncRow(ApiRouteBinding::class, ['id' => 7, 'application_id' => 2, 'api_resource_id' => 4, 'http_method' => 'GET', 'route_template' => '/api/law/v1/scanned-cases', 'source' => 'route_scan', 'status' => 1]),
        routeBindingSyncRow(ApiRouteBinding::class, ['id' => 8, 'application_id' => 2, 'api_resource_id' => 4, 'http_method' => 'GET', 'route_template' => '/api/law/v1/old-cases', 'source' => 'route_scan', 'status' => 1]),
        routeBindingSyncRow(ApiRouteBinding::class, ['id' => 9, 'application_id' => 2, 'api_resource_id' => 4, 'http_method' => 'GET', 'route_template' => '/api/law/v1/manual', 'source' => 'manual', 'status' => 1]),
        routeBindingSyncRow(ApiRouteBinding::class, ['id' => 10, 'application_id' => 2, 'api_resource_id' => 4, 'http_method' => 'GET', 'route_template' => '/api/law/v1/cases/{id}', 'source' => 'openapi', 'status' => 1]),
        routeBindingSyncRow(ApiRouteBinding::class, ['id' => 11, 'application_id' => 2, 'api_resource_id' => 5, 'http_method' => 'POST', 'route_template' => '/api/law/v1/cases', 'source' => 'manual', 'status' => 1]),
        routeBindingSyncRow(ApiRouteBinding::class, ['id' => 12, 'application_id' => 2, 'api_resource_id' => 4, 'http_method' => 'GET', 'route_template' => '/api/law/v1/openapi-only', 'source' => 'openapi', 'status' => 1]),
    ];
    $manifest = [
        'format' => RouteSyncManifest::FORMAT, 'organization_code' => 'sand', 'application_code' => 'lawyer', 'environment_code' => 'production',
        'routes' => [
            ['method' => 'GET', 'path' => '/healthz'],
            ['method' => 'GET', 'path' => '/api/law/v1/cases/{id}', 'sand_iam' => ['api_code' => 'legal.case.read']],
            ['method' => 'POST', 'path' => '/api/law/v1/cases', 'sand_iam' => ['api_code' => 'legal.case.create']],
            ['method' => 'GET', 'path' => '/api/law/v1/scanned-cases', 'sand_iam' => ['api_code' => 'legal.case.read']],
            ['method' => 'POST', 'path' => '/api/law/v1/cases/{id}/close', 'sand_iam' => ['api_code' => 'legal.case.update']],
        ],
    ];
    $synchronizer = new RouteBindingSynchronizer();
    $preview = $synchronizer->synchronize($manifest, disableMissing: true, operationId: 'acceptance-run-0001');
    routeBindingSyncAssert($preview['dry_run'] === true && $preview['valid'] === true, 'preview should be valid and dry-run by default');
    routeBindingSyncAssert(preg_match('/^[a-f0-9]{64}$/D', (string) ($preview['preview_hash'] ?? '')) === 1, 'preview hash is missing or malformed');
    $normalizedPreview = $synchronizer->synchronizeNormalized(RouteSyncManifest::normalize($manifest), disableMissing: true, operationId: 'acceptance-run-0001');
    routeBindingSyncAssert($normalizedPreview['dry_run'] === true && $normalizedPreview['valid'] === true && $normalizedPreview['changes'] === $preview['changes'] && $normalizedPreview['preview_hash'] === $preview['preview_hash'], 'normalized route manifest did not follow the same strict synchronization plan');
    routeBindingSyncAssert($preview['summary'] === ['新增' => 1, '更新' => 1, '停用' => 1, '保留外部绑定' => 2, '冲突' => 0, '未绑定' => 0, '忽略未标记路由' => 1], 'preview summary is incomplete or not human-readable');
    routeBindingSyncAssert($preview['operation_id'] === 'acceptance-run-0001', 'operation ID was not retained in the sync preview');
    routeBindingSyncAssert(in_array('SAND_IAM_ROUTE_SYNC_EXTERNAL_BINDING_UNCHANGED', array_column($preview['changes'], 'code'), true), 'manual or OpenAPI binding was not explicitly preserved');
    $beforeFailure = serialize([ApiRouteBinding::$rows, \plugin\SandIam\app\service\AuditWriter::$records]);
    foreach ([2, 3] as $failAt) {
        \plugin\SandIam\app\service\AuditWriter::$failAt = $failAt;
        try {
            $synchronizer->synchronize($manifest, apply: true, disableMissing: true, operationId: 'acceptance-run-0001');
            routeBindingSyncAssert(false, 'batch audit failure was ignored');
        } catch (\RuntimeException $exception) {
            routeBindingSyncAssert($exception->getMessage() === 'injected route audit failure', 'batch failed for an unexpected reason: ' . $exception->getMessage());
        }
        routeBindingSyncAssert(serialize([ApiRouteBinding::$rows, \plugin\SandIam\app\service\AuditWriter::$records]) === $beforeFailure, 'failed batch left route or audit changes');
        routeBindingSyncAssert(\think\facade\Db::$snapshots === [], 'failed batch leaked its transaction');
    }
    \plugin\SandIam\app\service\AuditWriter::$failAt = null;
    try {
        $synchronizer->synchronize($manifest, apply: true, disableMissing: true, operationId: 'acceptance-run-0001', expectedPreviewHash: str_repeat('0', 64));
        routeBindingSyncAssert(false, 'apply accepted a stale preview hash');
    } catch (ApiException $exception) {
        routeBindingSyncAssert(str_starts_with($exception->getMessage(), 'SAND_IAM_ROUTE_SYNC_PREVIEW_STALE'), 'stale preview hash returned the wrong error');
    }
    routeBindingSyncAssert(serialize([ApiRouteBinding::$rows, \plugin\SandIam\app\service\AuditWriter::$records]) === $beforeFailure, 'stale preview rejection changed route or audit state');
    $applied = $synchronizer->synchronize($manifest, apply: true, disableMissing: true, operationId: 'acceptance-run-0001', expectedPreviewHash: $preview['preview_hash']);
    routeBindingSyncAssert($applied['dry_run'] === false && (int) ApiRouteBinding::$rows[1]->status === 2, 'apply did not actually disable the missing route_scan binding');
    routeBindingSyncAssert((string) ApiRouteBinding::$rows[3]->source === 'openapi' && (string) ApiRouteBinding::$rows[4]->source === 'manual' && (int) ApiRouteBinding::$rows[5]->status === 1, 'apply took ownership of manual or OpenAPI bindings');
    routeBindingSyncAssert(count(ApiRouteBinding::$rows) === 7 && (string) ApiRouteBinding::$rows[6]->source === 'route_scan', 'apply did not create a new route_scan binding');
    $createdChange = array_values(array_filter($applied['changes'], static fn (array $change): bool => $change['operation'] === 'create'))[0] ?? null;
    routeBindingSyncAssert(
        is_array($createdChange)
            && (int) ($createdChange['binding_id'] ?? 0) === (int) ApiRouteBinding::$rows[6]->id
            && str_starts_with((string) ($createdChange['audit_request_id'] ?? ''), 'acceptance-run-0001.'),
        'apply result did not return the created binding ID and traceable audit request ID',
    );
    $auditRequestIds = array_map(static fn (array $record): string => (string) $record[8], \plugin\SandIam\app\service\AuditWriter::$records);
    routeBindingSyncAssert(count($auditRequestIds) === count(array_unique($auditRequestIds)) && count($auditRequestIds) === 3, 'route sync audit child request IDs are not unique per binding write');

    $wrongEnvironment = $manifest;
    $wrongEnvironment['environment_code'] = 'staging';
    try {
        (new RouteBindingSynchronizer())->synchronize($wrongEnvironment);
        routeBindingSyncAssert(false, 'cross-application or missing environment was accepted');
    } catch (ApiException $exception) {
        routeBindingSyncAssert(str_starts_with($exception->getMessage(), 'SAND_IAM_ROUTE_SYNC_ENVIRONMENT_MISMATCH'), 'environment mismatch did not fail closed');
    }

    $missing = $manifest;
    $missing['routes'][1]['sand_iam']['api_code'] = 'legal.case.missing';
    $blocked = (new RouteBindingSynchronizer())->synchronize($missing);
    routeBindingSyncAssert($blocked['valid'] === false && $blocked['counts']['unbound'] === 1 && $blocked['problems'][0]['code'] === 'SAND_IAM_ROUTE_SYNC_API_UNDECLARED', 'undeclared API action was not reported as unbound');
    try {
        (new RouteBindingSynchronizer())->synchronize($missing, apply: true);
        routeBindingSyncAssert(false, 'apply accepted an undeclared API action');
    } catch (ApiException $exception) {
        routeBindingSyncAssert(str_starts_with($exception->getMessage(), 'SAND_IAM_ROUTE_SYNC_APPLY_BLOCKED'), 'invalid apply did not fail closed');
    }

    $invalidNormalized = RouteSyncManifest::normalize($manifest);
    unset($invalidNormalized['routes'][0]['api_code']);
    try {
        (new RouteBindingSynchronizer())->synchronizeNormalized($invalidNormalized);
        routeBindingSyncAssert(false, 'normalized synchronization accepted an incomplete route');
    } catch (ApiException $exception) {
        routeBindingSyncAssert(str_starts_with($exception->getMessage(), 'SAND_IAM_ROUTE_SYNC_MANIFEST_INVALID'), 'invalid normalized route did not fail closed');
    }

    $conflictingManifest = $manifest;
    $conflictingManifest['routes'][2]['sand_iam']['api_code'] = 'legal.case.update';
    $conflict = (new RouteBindingSynchronizer())->synchronize($conflictingManifest);
    routeBindingSyncAssert($conflict['valid'] === false && $conflict['counts']['conflict'] === 1 && $conflict['problems'][0]['code'] === 'SAND_IAM_ROUTE_BINDING_CONFLICT', 'existing different API binding did not fail closed');

    ApplicationBusinessAction::$rows[0]->status = 2;
    $disabled = (new RouteBindingSynchronizer())->synchronize($manifest);
    routeBindingSyncAssert($disabled['valid'] === false && $disabled['problems'][0]['code'] === 'SAND_IAM_ROUTE_SYNC_ACTION_DISABLED', 'disabled application business action did not block route synchronization');

    echo "route binding synchronizer non-PG checks passed\n";
}
