<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace think\facade {
    final class Db
    {
        /** @var list<string> */
        private static array $snapshots = [];

        public static function startTrans(): void
        {
            self::$snapshots[] = serialize([
                \plugin\SandIam\app\model\ApiResource::$rows,
                \plugin\SandIam\app\model\ApiRouteBinding::$rows,
                \plugin\SandIam\app\service\AuditWriter::$records,
            ]);
        }

        public static function commit(): void { array_pop(self::$snapshots); }

        public static function rollback(): void
        {
            $snapshot = array_pop(self::$snapshots);
            if (!is_string($snapshot)) return;
            [
                \plugin\SandIam\app\model\ApiResource::$rows,
                \plugin\SandIam\app\model\ApiRouteBinding::$rows,
                \plugin\SandIam\app\service\AuditWriter::$records,
            ] = unserialize($snapshot, ['allowed_classes' => true]);
        }
    }
}

namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    class ImportRow
    {
        /** @param array<string,mixed> $values */
        public function __construct(array $values)
        {
            foreach ($values as $key => $value) $this->{$key} = $value;
        }

        /** @return array<string,mixed> */
        public function toArray(): array { return get_object_vars($this); }

        /** @param array<string,mixed> $values */
        public function save(array $values): bool
        {
            foreach ($values as $key => $value) $this->{$key} = $value;
            return true;
        }
    }

    final class ImportQuery
    {
        /** @var list<array{0:string,1:mixed}> */
        private array $filters = [];

        /** @param class-string $model */
        public function __construct(private string $model) {}

        public function where(string $field, mixed $value): self
        {
            $this->filters[] = [$field, $value];
            return $this;
        }

        public function lock(bool $enabled): self { return $this; }
        public function order(string $field): self { return $this; }

        public function find(): ?ImportRow
        {
            foreach ($this->rows() as $row) {
                if ($this->matches($row)) return $row;
            }
            return null;
        }

        /** @return list<ImportRow> */
        public function select(): array
        {
            return array_values(array_filter($this->rows(), fn (ImportRow $row): bool => $this->matches($row)));
        }

        /** @return list<ImportRow> */
        private function rows(): array
        {
            return $this->model::$rows;
        }

        private function matches(ImportRow $row): bool
        {
            foreach ($this->filters as [$field, $value]) {
                if ((string) ($row->{$field} ?? null) !== (string) $value) return false;
            }
            return true;
        }
    }

    trait ImportModel
    {
        /** @var list<ImportRow> */
        public static array $rows = [];
        public static function where(string $field, mixed $value): ImportQuery
        {
            return (new ImportQuery(static::class))->where($field, $value);
        }

        /** @param array<string,mixed> $values */
        public static function create(array $values): static
        {
            $values['id'] ??= count(static::$rows) + 100;
            $row = new static($values);
            static::$rows[] = $row;
            return $row;
        }
    }

    final class Organization extends ImportRow { use ImportModel; }
    final class Application extends ImportRow { use ImportModel; }
    final class Environment extends ImportRow { use ImportModel; }
    final class Resource extends ImportRow { use ImportModel; }
    final class ApplicationBusinessAction extends ImportRow { use ImportModel; }
    final class ApiResource extends ImportRow { use ImportModel; }
    final class ApiRouteBinding extends ImportRow { use ImportModel; }
    final class Policy extends ImportRow { use ImportModel; }
}

namespace plugin\SandIam\app\service {
    final class RequestId
    {
        public static function normalize(string $value): string
        {
            if (strlen($value) < 8) throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_REQUEST_ID_REQUIRED', 400);
            return $value;
        }
    }

    final class AuditWriter
    {
        /** @var list<array<int,mixed>> */
        public static array $records = [];
        public static bool $fail = false;

        public function write(mixed ...$values): void
        {
            if (self::$fail) throw new \RuntimeException('injected audit failure');
            self::$records[] = $values;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/developer/OpenApiImportDocument.php';
    require_once dirname(__DIR__) . '/app/developer/ApplicationBusinessActionCatalog.php';
    require_once dirname(__DIR__) . '/app/service/OpenApiImportService.php';

    use plugin\SandIam\app\model\ApiResource;
    use plugin\SandIam\app\model\ApiRouteBinding;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\ApplicationBusinessAction;
    use plugin\SandIam\app\model\Environment;
    use plugin\SandIam\app\model\ImportRow;
    use plugin\SandIam\app\model\Organization;
    use plugin\SandIam\app\model\Resource;
    use plugin\SandIam\app\service\OpenApiImportService;
    use plugin\SandIam\app\service\AuditWriter;

    function openApiImportServiceAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
    }

    Organization::$rows = [new Organization(['id' => 1, 'code' => 'sand', 'status' => 1])];
    Application::$rows = [new Application(['id' => 2, 'organization_id' => 1, 'code' => 'work', 'status' => 1])];
    Environment::$rows = [new Environment(['id' => 3, 'application_id' => 2, 'code' => 'production', 'status' => 1])];
    Resource::$rows = [new Resource(['id' => 4, 'application_id' => 2, 'code' => 'work_item', 'status' => 1])];
    ApplicationBusinessAction::$rows = [new ApplicationBusinessAction([
        'id' => 5,
        'application_id' => 2,
        'code' => 'work_item.read',
        'state' => 'published',
        'status' => 1,
    ])];
    ApiResource::$rows = [];
    ApiRouteBinding::$rows = [];

    $input = [
        'organization_code' => 'sand',
        'application_code' => 'work',
        'environment_code' => 'production',
        'document' => [
            'openapi' => '3.1.0',
            'paths' => [
                '/work-items/{id}' => [
                    'get' => ['summary' => '查看工作项', 'x-sand-iam' => ['riskLevel' => 'low']],
                ],
            ],
        ],
        'mappings' => [[
            'operation_key' => 'GET /work-items/{id}',
            'api_code' => 'work-item.read',
            'api_version' => 'v1',
            'resource_code' => 'work_item',
            'action' => 'work_item.read',
            'audience' => 'work-api',
            'required_scope' => 'work.read',
        ]],
        'disable_missing' => false,
    ];

    $service = new OpenApiImportService();
    $first = $service->preview($input);
    openApiImportServiceAssert($first['can_apply'] === true && count($first['changes']) === 2, 'valid OpenAPI import preview was not applicable');

    Resource::$rows = [new Resource(['id' => 44, 'application_id' => 2, 'code' => 'work_item', 'status' => 1])];
    $recreatedResource = $service->preview($input);
    openApiImportServiceAssert($recreatedResource['preview_hash'] !== $first['preview_hash'], 'resource delete/recreate did not invalidate preview hash');

    ApplicationBusinessAction::$rows[0]->state = 'draft';
    $draftAction = $service->preview($input);
    openApiImportServiceAssert($draftAction['can_apply'] === false, 'draft business action was accepted for OpenAPI import');
    openApiImportServiceAssert(
        ($draftAction['conflicts'][0]['code'] ?? null) === 'SAND_IAM_OPENAPI_IMPORT_ACTION_NOT_PUBLISHED',
        'draft business action did not return the stable import conflict',
    );

    $duplicateTarget = $input;
    $duplicateTarget['document']['paths']['/work-items'] = [
        'get' => ['summary' => '工作项列表', 'x-sand-iam' => ['riskLevel' => 'medium']],
    ];
    $duplicateTarget['mappings'][] = array_replace(
        $duplicateTarget['mappings'][0],
        ['operation_key' => 'GET /work-items'],
    );
    try {
        $service->preview($duplicateTarget);
        openApiImportServiceAssert(false, 'two operations mapped to the same API target');
    } catch (\plugin\sandadmin\exception\ApiException $exception) {
        openApiImportServiceAssert(
            str_starts_with($exception->getMessage(), 'SAND_IAM_OPENAPI_IMPORT_INVALID'),
            'duplicate API target did not fail with the stable input error',
        );
    }

    ApplicationBusinessAction::$rows[0]->state = 'published';
    Resource::$rows = [new Resource(['id' => 4, 'application_id' => 2, 'code' => 'work_item', 'status' => 1])];
    $applicable = $service->preview($input);
    $applied = $service->apply($input, $applicable['preview_hash'], 9, 'openapi-apply-1');
    openApiImportServiceAssert($applied['dry_run'] === false, 'OpenAPI import apply did not report a write');
    openApiImportServiceAssert(count(ApiResource::$rows) === 1 && count(ApiRouteBinding::$rows) === 1, 'OpenAPI import did not create API and route rows');
    openApiImportServiceAssert(
        ApiRouteBinding::$rows[0]->route_fingerprint === hash('sha256', "GET\0/work-items/{id}")
        && ApiRouteBinding::$rows[0]->source === 'openapi',
        'OpenAPI import route ownership or fingerprint drifted',
    );
    openApiImportServiceAssert(count(AuditWriter::$records) === 1, 'OpenAPI import apply did not write one batch audit');

    $beforeFailure = serialize([ApiResource::$rows, ApiRouteBinding::$rows, AuditWriter::$records]);
    $failureInput = $input;
    $failureInput['document']['paths']['/work-items/{id}']['get']['summary'] = '查看单个工作项';
    $failureInput['document']['paths']['/work-items/{id}']['get']['x-sand-iam']['riskLevel'] = 'medium';
    AuditWriter::$fail = true;
    try {
        $service->apply($failureInput, $service->preview($failureInput)['preview_hash'], 9, 'openapi-apply-2');
        openApiImportServiceAssert(false, 'audit failure did not fail the OpenAPI import');
    } catch (\RuntimeException $exception) {
        openApiImportServiceAssert($exception->getMessage() === 'injected audit failure', 'unexpected apply failure');
    } finally {
        AuditWriter::$fail = false;
    }
    openApiImportServiceAssert(
        serialize([ApiResource::$rows, ApiRouteBinding::$rows, AuditWriter::$records]) === $beforeFailure,
        'OpenAPI import audit failure left partial state',
    );

    echo "OpenAPI import service non-PG behavior passed\n";
}
