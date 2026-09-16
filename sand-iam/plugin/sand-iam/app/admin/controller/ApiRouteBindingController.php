<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\ApiResource;
use plugin\SandIam\app\model\ApiRouteBinding;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ApiRouteBindingController extends ApplicationResourceController
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const SOURCES = ['manual', 'openapi', 'route_scan'];

    protected string $modelClass = ApiRouteBinding::class;
    protected array $writeFields = [
        'application_id',
        'api_resource_id',
        'http_method',
        'route_template',
        'source',
        'status',
    ];
    protected array $requiredFields = ['application_id', 'api_resource_id', 'http_method', 'route_template'];
    protected string $resourceType = 'api_route_binding';

    #[Permission('SandIAM 接口路由列表', 'sand_iam:api_route_binding:index')]
    public function index(Request $request): Response { return parent::index($request); }

    #[Permission('SandIAM 接口路由读取', 'sand_iam:api_route_binding:read')]
    public function read(Request $request): Response { return parent::read($request); }

    #[Permission('SandIAM 接口路由保存', 'sand_iam:api_route_binding:save')]
    public function save(Request $request): Response
    {
        try {
            return parent::save($request);
        } catch (\Throwable $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new ApiException('SAND_IAM_ROUTE_BINDING_CONFLICT: 当前请求方法和路由模板已经绑定', 409);
            }
            throw $exception;
        }
    }

    #[Permission('SandIAM 接口路由更新', 'sand_iam:api_route_binding:update')]
    public function update(Request $request): Response { return parent::update($request); }

    #[Permission('SandIAM 接口路由停用', 'sand_iam:api_route_binding:disable')]
    public function disable(Request $request): Response { return parent::disable($request); }

    protected function payload(Request $request, bool $updating): array
    {
        $payload = parent::payload($request, $updating);
        if ($updating) {
            foreach (['application_id', 'api_resource_id', 'http_method', 'route_template', 'source'] as $immutable) {
                unset($payload[$immutable]);
            }
            return $payload;
        }
        if (isset($payload['http_method'])) {
            $payload['http_method'] = strtoupper(trim((string) $payload['http_method']));
        }
        if (isset($payload['route_template'])) {
            $payload['route_template'] = $this->normalizeRoute((string) $payload['route_template']);
        }
        $method = (string) ($payload['http_method'] ?? '');
        $route = (string) ($payload['route_template'] ?? '');
        if ($method !== '' && $route !== '') {
            $payload['route_fingerprint'] = hash('sha256', $method . "\0" . $route);
        }
        $payload['source'] ??= 'manual';
        return $payload;
    }

    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        $apiResourceId = (int) ($payload['api_resource_id'] ?? $existing?->api_resource_id ?? 0);
        if (!ApiResource::where('id', $apiResourceId)->where('application_id', $applicationId)->where('status', 1)->find()) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 接口目录记录不存在、已停用或不属于所选应用', 400);
        }
        $method = (string) ($payload['http_method'] ?? $existing?->http_method ?? '');
        if (!in_array($method, self::METHODS, true)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 请求方法只能选择 GET、POST、PUT、PATCH 或 DELETE', 400);
        }
        $this->normalizeRoute((string) ($payload['route_template'] ?? $existing?->route_template ?? ''));
        $source = (string) ($payload['source'] ?? $existing?->source ?? 'manual');
        if (!in_array($source, self::SOURCES, true)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 路由来源只能是手工登记、OpenAPI 导入或路由扫描', 400);
        }
    }

    private function normalizeRoute(string $route): string
    {
        $route = trim($route);
        if (
            $route === ''
            || strlen($route) > 255
            || $route[0] !== '/'
            || str_contains($route, '?')
            || str_contains($route, '#')
            || str_contains($route, '//')
            || !preg_match('~^/[A-Za-z0-9._\x7E!$&\'()*+,;=:@%/{\}\[\]-]*$~', $route)
        ) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 路由模板须以 / 开头，不含域名、查询参数、片段或连续斜杠', 400);
        }
        return $route !== '/' ? rtrim($route, '/') : $route;
    }
}
