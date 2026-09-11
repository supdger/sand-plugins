<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\model\ApiResource;
use plugin\SandIam\app\model\ApiRouteBinding;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\developer\ApplicationBusinessActionCatalog;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class ApiGovernanceService
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly AuditWriter $auditWriter = new AuditWriter()) {}

    public function apiByCode(int $applicationId, string $code, string $version = 'v1'): ApiResource
    {
        $api = ApiResource::where('application_id', $applicationId)
            ->where('code', $this->apiCode($code))
            ->where('api_version', $this->version($version))
            ->where('status', 1)
            ->find();
        if ($api === null) {
            throw new ApiException('SAND_IAM_API_NOT_REGISTERED: 接口未登记、已停用或版本不匹配', 403);
        }
        return $api;
    }

    public function applicationByCode(string $organizationCode, string $applicationCode): Application
    {
        $organizationCode = trim($organizationCode);
        $applicationCode = trim($applicationCode);
        if (
            !preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $organizationCode)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $applicationCode)
        ) {
            throw new ApiException('SAND_IAM_APPLICATION_REQUIRED: 必须同时明确客户主体和接入应用', 400);
        }
        $organization = Organization::where('code', $organizationCode)->where('status', 1)->find();
        $application = $organization === null ? null : Application::where('organization_id', (int) $organization->id)
            ->where('code', $applicationCode)
            ->where('status', 1)
            ->find();
        if ($application === null) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 接入应用不存在或已停用', 404);
        }
        return $application;
    }

    public function resolveRoute(int $applicationId, string $method, string $routeTemplate): ApiResource
    {
        $method = strtoupper(trim($method));
        $routeTemplate = $this->routeTemplate($routeTemplate);
        if (!in_array($method, self::METHODS, true)) {
            throw new ApiException('SAND_IAM_ROUTE_NOT_REGISTERED: 请求方法不受支持', 403);
        }
        $bindings = ApiRouteBinding::where('application_id', $applicationId)
            ->where('http_method', $method)
            ->where('route_template', $routeTemplate)
            ->where('status', 1)
            ->select()
            ->all();
        if (count($bindings) !== 1) {
            $code = $bindings === [] ? 'SAND_IAM_ROUTE_NOT_REGISTERED' : 'SAND_IAM_ROUTE_BINDING_CONFLICT';
            throw new ApiException($code . ': 当前路由没有唯一且启用的接口绑定', 403);
        }
        return $this->apiById($applicationId, (int) $bindings[0]->api_resource_id);
    }

    /**
     * Route scanners may only observe a binding for an existing semantic API.
     * They never create resources, actions, policies or grants.
     */
    public function observeRoute(
        int $applicationId,
        string $apiCode,
        string $apiVersion,
        string $method,
        string $routeTemplate,
        string $source = 'route_scan',
        string $requestId = '',
        bool $requireRouteScanOwnership = false,
    ): int {
        $api = $this->apiByCode($applicationId, $apiCode, $apiVersion);
        $method = strtoupper(trim($method));
        $routeTemplate = $this->routeTemplate($routeTemplate);
        if (!in_array($method, self::METHODS, true) || !in_array($source, ['route_scan', 'openapi'], true)) {
            throw new ApiException('SAND_IAM_ROUTE_DECLARATION_INVALID', 400);
        }
        $fingerprint = hash('sha256', $method . "\0" . $routeTemplate);
        $requestId = RequestId::normalize($requestId);
        Db::startTrans();
        try {
            $application = Application::where('id', $applicationId)->where('status', 1)->lock(true)->find();
            $api = ApiResource::where('id', (int) $api->id)->where('application_id', $applicationId)->where('status', 1)->lock(true)->find();
            if ($application === null || $api === null) {
                throw new ApiException('SAND_IAM_API_NOT_REGISTERED', 403);
            }
            $binding = ApiRouteBinding::where('application_id', $applicationId)
                ->where('http_method', $method)
                ->where('route_template', $routeTemplate)
                ->lock(true)
                ->find();
            if ($binding !== null && (int) $binding->api_resource_id !== (int) $api->id) {
                throw new ApiException('SAND_IAM_ROUTE_BINDING_CONFLICT: 路由已绑定到其他语义接口', 409);
            }
            if ($binding !== null && $requireRouteScanOwnership && (string) $binding->source !== 'route_scan') {
                throw new ApiException('SAND_IAM_ROUTE_BINDING_OWNERSHIP_CONFLICT: 路由绑定由其他来源维护，扫描器不得接管', 409);
            }
            if ($binding === null) {
                $binding = ApiRouteBinding::create([
                    'application_id' => $applicationId,
                    'api_resource_id' => (int) $api->id,
                    'http_method' => $method,
                    'route_template' => $routeTemplate,
                    'route_fingerprint' => $fingerprint,
                    'source' => $source,
                    'last_seen_time' => date('Y-m-d H:i:s'),
                    'status' => 1,
                ]);
            } else {
                $binding->save([
                    'route_fingerprint' => $fingerprint,
                    'source' => $source,
                    'last_seen_time' => date('Y-m-d H:i:s'),
                    'status' => 1,
                ]);
            }
            $this->auditWriter->write(
                'system',
                'api_route_catalog',
                (int) $application->organization_id,
                $applicationId,
                'api_route.observe',
                'api_route_binding',
                (int) $binding->id,
                'succeeded',
                $requestId,
                ['api_resource_id' => (int) $api->id, 'source' => $source],
            );
            Db::commit();
            return (int) $binding->id;
        } catch (\Throwable $exception) {
            Db::rollback();
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new ApiException('SAND_IAM_ROUTE_BINDING_CONFLICT: 路由被并发绑定，请刷新后重试', 409);
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function openApiOperation(ApiResource $api): array
    {
        (new ApplicationBusinessActionCatalog())->assertEnabled(
            (int) $api->application_id,
            (string) $api->action,
            true,
        );
        return [
            'operationId' => (string) $api->code,
            'summary' => (string) $api->name,
            'description' => (string) ($api->description ?? ''),
            'x-sand-iam' => [
                'resourceId' => (int) $api->resource_id,
                'action' => (string) $api->action,
                'operation' => (string) $api->operation,
                'apiVersion' => (string) $api->api_version,
                'audience' => (string) $api->audience,
                'requiredScope' => (string) ($api->required_scope ?? ''),
                'riskLevel' => (string) $api->risk_level,
            ],
        ];
    }

    private function apiById(int $applicationId, int $id): ApiResource
    {
        $api = ApiResource::where('id', $id)->where('application_id', $applicationId)->where('status', 1)->find();
        if ($api === null) {
            throw new ApiException('SAND_IAM_API_NOT_REGISTERED', 403);
        }
        return $api;
    }

    private function apiCode(string $code): string
    {
        $code = trim($code);
        if (!preg_match('/^[a-z][a-z0-9_.:-]{1,95}$/', $code)) {
            throw new ApiException('SAND_IAM_API_CODE_INVALID', 400);
        }
        return $code;
    }

    private function version(string $version): string
    {
        $version = trim($version);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $version)) {
            throw new ApiException('SAND_IAM_API_VERSION_INVALID', 400);
        }
        return $version;
    }

    private function routeTemplate(string $route): string
    {
        $route = trim($route);
        if (
            $route === ''
            || strlen($route) > 255
            || $route[0] !== '/'
            || str_contains($route, '?')
            || str_contains($route, '#')
            || str_contains($route, '//')
        ) {
            throw new ApiException('SAND_IAM_ROUTE_DECLARATION_INVALID', 400);
        }
        return $route !== '/' ? rtrim($route, '/') : $route;
    }
}
