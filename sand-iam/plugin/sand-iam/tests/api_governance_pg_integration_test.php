<?php

declare(strict_types=1);

use plugin\SandIam\app\model\ApiResource;
use plugin\SandIam\app\model\ApiRouteBinding;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\ApplicationBusinessAction;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\Resource;
use plugin\SandIam\app\runtime\ApiGovernanceService;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

function apiPgFail(string $message): never
{
    fwrite(STDERR, "IAM-T05 API governance PostgreSQL integration failed: {$message}\n");
    exit(1);
}

function apiPgAssert(bool $condition, string $message): void
{
    if (!$condition) {
        apiPgFail($message);
    }
}

function apiPgExpect(callable $callback, string $error, int $status): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if ($exception->getCode() === $status && str_contains($exception->getMessage(), $error)) {
            return;
        }
        apiPgFail("expected {$error}/{$status}, received {$exception->getMessage()}/{$exception->getCode()}");
    }
    apiPgFail("expected {$error}, but no exception was thrown");
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) {
    apiPgFail('SandAdmin dependencies are unavailable');
}

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';
Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$organization = Organization::create(['code' => 't05-pg-org', 'name' => 'T05 接口治理组织', 'status' => 1]);
$applicationA = Application::create(['organization_id' => (int) $organization->id, 'code' => 't05-pg-app-a', 'name' => '律序接口应用', 'status' => 1]);
$applicationB = Application::create(['organization_id' => (int) $organization->id, 'code' => 't05-pg-app-b', 'name' => '进销存接口应用', 'status' => 1]);
$resourceA = Resource::create(['application_id' => (int) $applicationA->id, 'code' => 'legal_case', 'name' => '案件', 'owner_field' => 'lawyer_id', 'organization_field' => 'law_firm_id', 'status' => 1]);
$resourceB = Resource::create(['application_id' => (int) $applicationB->id, 'code' => 'retail_order', 'name' => '零售订单', 'owner_field' => 'owner_id', 'organization_field' => 'store_id', 'status' => 1]);
ApplicationBusinessAction::create([
    'application_id' => (int) $applicationA->id,
    'code' => 'case.read',
    'name' => '读取案件',
    'description' => '读取案件详情的业务动作',
    'state' => 'published',
    'status' => 1,
]);
ApplicationBusinessAction::create([
    'application_id' => (int) $applicationA->id,
    'code' => 'case.update',
    'name' => '修改案件',
    'description' => '修改案件内容的业务动作',
    'state' => 'published',
    'status' => 1,
]);

$caseRead = ApiResource::create([
    'application_id' => (int) $applicationA->id,
    'resource_id' => (int) $resourceA->id,
    'code' => 'legal.case.read',
    'name' => '读取案件详情',
    'action' => 'case.read',
    'operation' => 'read',
    'api_version' => 'v1',
    'audience' => 'lawyer-api',
    'required_scope' => 'case:read',
    'risk_level' => 'high',
    'description' => '读取单个案件的脱敏详情',
    'status' => 1,
]);
$caseUpdate = ApiResource::create([
    'application_id' => (int) $applicationA->id,
    'resource_id' => (int) $resourceA->id,
    'code' => 'legal.case.update',
    'name' => '修改案件',
    'action' => 'case.update',
    'operation' => 'update',
    'api_version' => 'v1',
    'audience' => 'lawyer-api',
    'required_scope' => 'case:write',
    'risk_level' => 'critical',
    'status' => 1,
]);
ApiResource::create([
    'application_id' => (int) $applicationB->id,
    'resource_id' => (int) $resourceB->id,
    'code' => 'retail.order.read',
    'name' => '读取零售订单',
    'action' => 'order.read',
    'operation' => 'read',
    'api_version' => 'v1',
    'audience' => 'retail-api',
    'risk_level' => 'medium',
    'status' => 1,
]);

$governance = new ApiGovernanceService();
apiPgAssert((int) $governance->applicationByCode('t05-pg-org', 't05-pg-app-a')->id === (int) $applicationA->id, 'application lookup did not preserve organization scope');
apiPgExpect(static fn () => $governance->applicationByCode('t05-pg-org', 't05-pg-app-missing'), 'SAND_IAM_RESOURCE_NOT_FOUND', 404);
apiPgExpect(static fn () => $governance->apiByCode((int) $applicationA->id, 'retail.order.read'), 'SAND_IAM_API_NOT_REGISTERED', 403);

$bindingId = $governance->observeRoute((int) $applicationA->id, 'legal.case.read', 'v1', 'get', '/api/cases/{id}/', 'route_scan');
apiPgAssert($governance->observeRoute((int) $applicationA->id, 'legal.case.read', 'v1', 'GET', '/api/cases/{id}', 'openapi') === $bindingId, 're-observed route did not reuse its binding');
$binding = ApiRouteBinding::find($bindingId);
apiPgAssert($binding !== null && (string) $binding->source === 'openapi' && $binding->last_seen_time !== null, 'route observation did not refresh source and last-seen time');
$resolved = $governance->resolveRoute((int) $applicationA->id, 'GET', '/api/cases/{id}/');
apiPgAssert((int) $resolved->id === (int) $caseRead->id, 'registered route did not resolve to its semantic API');

apiPgExpect(
    static fn () => $governance->observeRoute((int) $applicationA->id, 'legal.case.update', 'v1', 'GET', '/api/cases/{id}', 'route_scan'),
    'SAND_IAM_ROUTE_BINDING_CONFLICT',
    409,
);
apiPgExpect(static fn () => $governance->resolveRoute((int) $applicationA->id, 'GET', '/api/unregistered'), 'SAND_IAM_ROUTE_NOT_REGISTERED', 403);
apiPgExpect(static fn () => $governance->resolveRoute((int) $applicationA->id, 'OPTIONS', '/api/cases/{id}'), 'SAND_IAM_ROUTE_NOT_REGISTERED', 403);
apiPgExpect(static fn () => $governance->observeRoute((int) $applicationA->id, 'legal.case.read', 'v1', 'GET', '//unsafe', 'route_scan'), 'SAND_IAM_ROUTE_DECLARATION_INVALID', 400);

$binding->save(['status' => 2]);
apiPgExpect(static fn () => $governance->resolveRoute((int) $applicationA->id, 'GET', '/api/cases/{id}'), 'SAND_IAM_ROUTE_NOT_REGISTERED', 403);
apiPgAssert($governance->observeRoute((int) $applicationA->id, 'legal.case.read', 'v1', 'GET', '/api/cases/{id}', 'route_scan') === $bindingId, 'route observation did not reactivate the same semantic binding');
$caseRead->save(['status' => 2]);
apiPgExpect(static fn () => $governance->resolveRoute((int) $applicationA->id, 'GET', '/api/cases/{id}'), 'SAND_IAM_API_NOT_REGISTERED', 403);
$caseRead->save(['status' => 1]);

$operation = $governance->openApiOperation($caseRead);
apiPgAssert(($operation['operationId'] ?? null) === 'legal.case.read', 'OpenAPI operationId is not the stable API code');
apiPgAssert(($operation['x-sand-iam']['action'] ?? null) === 'case.read', 'OpenAPI metadata lost the semantic action');
apiPgAssert(($operation['x-sand-iam']['operation'] ?? null) === 'read', 'OpenAPI metadata lost the data-scope operation');
apiPgAssert(($operation['x-sand-iam']['audience'] ?? null) === 'lawyer-api', 'OpenAPI metadata lost the audience');
apiPgAssert(($operation['x-sand-iam']['requiredScope'] ?? null) === 'case:read', 'OpenAPI metadata lost the required scope');
apiPgAssert((int) ($operation['x-sand-iam']['resourceId'] ?? 0) === (int) $resourceA->id, 'OpenAPI metadata lost the business resource');
apiPgAssert(AuditLog::where('application_id', (int) $applicationA->id)->where('action', 'api_route.observe')->count() === 3, 'route observations were not audited exactly once per successful call');

fwrite(STDOUT, "IAM-T05 API governance PostgreSQL integration passed\n");
