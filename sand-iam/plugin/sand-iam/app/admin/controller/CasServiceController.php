<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\CasService;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class CasServiceController extends ApplicationResourceController
{
    protected string $modelClass = CasService::class;
    protected array $writeFields = ['application_id', 'name', 'service_url', 'released_attributes', 'status'];
    protected array $requiredFields = ['application_id', 'name', 'service_url'];
    protected string $resourceType = 'cas_service';

    #[Permission('SandIAM CAS 接入服务列表', 'sand_iam:cas_service:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM CAS 接入服务读取', 'sand_iam:cas_service:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM CAS 接入服务保存', 'sand_iam:cas_service:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM CAS 接入服务更新', 'sand_iam:cas_service:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM CAS 接入服务停用', 'sand_iam:cas_service:disable')] public function disable(Request $request): Response { return parent::disable($request); }

    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        if (!Application::where('id', $applicationId)->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 400);

        $url = trim((string) ($payload['service_url'] ?? $existing?->service_url ?? ''));
        if (!$this->validServiceUrl($url)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: CAS 服务地址必须是无通配符、无账号信息和无片段的精确 HTTPS 地址', 400);
        if ($existing !== null && array_key_exists('service_url', $payload) && !hash_equals((string) $existing->service_url, $url)) {
            throw new ApiException('SAND_IAM_CAS_SERVICE_URL_IMMUTABLE: CAS 服务地址启用后不可修改，请新增接入服务并停用旧记录', 409);
        }
        $attributes = $payload['released_attributes'] ?? $existing?->released_attributes ?? [];
        if (is_string($attributes)) $attributes = json_decode($attributes, true);
        if (!is_array($attributes) || count($attributes) > 2 || array_filter($attributes, static fn (mixed $value): bool => !is_string($value) || !in_array($value, ['display_name', 'email'], true))) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: CAS 可返回属性只能选择显示名称和邮箱', 400);
        }
        $duplicate = CasService::where('service_url', $url);
        if ($existing !== null) $duplicate->where('id', '<>', (int) $existing->id);
        if ($duplicate->find()) throw new ApiException('SAND_IAM_CAS_SERVICE_URL_EXISTS: 该 CAS 服务地址已登记到其他接入服务', 409);
    }

    /** @return array<string,mixed> */
    protected function payload(Request $request, bool $updating): array
    {
        $payload = parent::payload($request, $updating);
        if (array_key_exists('service_url', $payload)) $payload['service_url'] = trim((string) $payload['service_url']);
        if (array_key_exists('name', $payload)) $payload['name'] = trim((string) $payload['name']);
        if (array_key_exists('released_attributes', $payload)) {
            $attributes = $payload['released_attributes'];
            if (is_string($attributes)) $attributes = json_decode($attributes, true);
            if (is_array($attributes)) $payload['released_attributes'] = array_values(array_unique($attributes));
        }
        return $payload;
    }

    private function validServiceUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 512 || !filter_var($url, FILTER_VALIDATE_URL) || str_contains($url, '*')) return false;
        $parts = parse_url($url);
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }
}
