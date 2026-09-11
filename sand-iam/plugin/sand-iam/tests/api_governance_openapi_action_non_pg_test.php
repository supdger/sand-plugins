<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace plugin\SandIam\app\model {
    final class ApplicationBusinessActionQuery
    {
        /** @var array<string,mixed> */ private array $where = [];
        public function where(string $field, mixed $value): self { $this->where[$field] = $value; return $this; }
        public function find(): ?object
        {
            foreach (ApplicationBusinessAction::$rows as $row) {
                foreach ($this->where as $field => $value) if (($row->{$field} ?? null) !== $value) continue 2;
                return $row;
            }
            return null;
        }
    }
    final class ApplicationBusinessAction
    {
        /** @var list<object> */ public static array $rows = [];
        public static function where(string $field, mixed $value): ApplicationBusinessActionQuery { return (new ApplicationBusinessActionQuery())->where($field, $value); }
    }
    #[\AllowDynamicProperties] final class ApiResource {}
}

namespace plugin\SandIam\app\service {
    final class AuditWriter {}
    final class RequestId {}
}

namespace think\facade { final class Db {} }

namespace {
    require_once dirname(__DIR__) . '/app/developer/ApplicationBusinessActionCatalog.php';
    require_once dirname(__DIR__) . '/app/runtime/ApiGovernanceService.php';

    use plugin\SandIam\app\model\ApiResource;
    use plugin\SandIam\app\model\ApplicationBusinessAction;
    use plugin\SandIam\app\runtime\ApiGovernanceService;
    use plugin\sandadmin\exception\ApiException;

    function t28OpenApi(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    }
    function t28OpenApiResource(): ApiResource
    {
        $api = new ApiResource();
        $api->application_id = 11;
        $api->resource_id = 21;
        $api->code = 'matter.export.endpoint';
        $api->name = '导出案件';
        $api->description = '导出已授权案件';
        $api->action = 'matter.export';
        $api->operation = 'export';
        $api->api_version = 'v1';
        $api->audience = 'lawyer-api';
        $api->required_scope = 'matter.export';
        $api->risk_level = 'high';
        return $api;
    }

    $governance = new ApiGovernanceService();
    ApplicationBusinessAction::$rows = [(object) ['application_id' => 11, 'code' => 'matter.export', 'status' => 1]];
    $operation = $governance->openApiOperation(t28OpenApiResource());
    t28OpenApi(($operation['x-sand-iam']['action'] ?? null) === 'matter.export' && !array_key_exists('actionDeclarationState', $operation['x-sand-iam']), 'OpenAPI leaked compatibility action state');

    ApplicationBusinessAction::$rows = [];
    try {
        $governance->openApiOperation(t28OpenApiResource());
        t28OpenApi(false, 'undeclared action was exported through OpenAPI');
    } catch (ApiException $exception) {
        t28OpenApi(str_starts_with($exception->getMessage(), 'SAND_IAM_APPLICATION_ACTION_UNDECLARED'), 'undeclared OpenAPI action used an unstable code');
    }
    ApplicationBusinessAction::$rows = [(object) ['application_id' => 11, 'code' => 'matter.export', 'status' => 2]];
    try {
        $governance->openApiOperation(t28OpenApiResource());
        t28OpenApi(false, 'disabled action was exported through OpenAPI');
    } catch (ApiException $exception) {
        t28OpenApi(str_starts_with($exception->getMessage(), 'SAND_IAM_APPLICATION_ACTION_DISABLED'), 'disabled OpenAPI action used an unstable code');
    }

    echo "API governance OpenAPI action non-PG checks passed\n";
}
