<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\IdentityProvider;
use plugin\SandIam\app\model\IdentityProviderApplication;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\service\AuditWriter;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;
use think\facade\Db;

/** Generic provider management. Provider ownership is organization-scoped. */
final class IdentityProviderController extends AdminResourceController
{
    protected string $modelClass = IdentityProvider::class;
    protected array $writeFields = ['organization_id', 'application_id', 'scope_type', 'code', 'name', 'status'];
    protected array $requiredFields = ['code', 'name'];
    protected string $resourceType = 'identity_provider';

    #[Permission('SandIAM 身份源列表', 'sand_iam:identity_provider:index')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->input('page', 1));
        $limit = min(100, max(1, (int) $request->input('limit', 20)));
        $query = IdentityProvider::order('id', 'desc');
        $this->scopeIndexToOrganizations($query);
        foreach (['organization_id', 'status'] as $field) {
            $value = $request->input($field, '');
            if ($value !== '') $query->where($field, (int) $value);
        }
        $applicationId = (int) $request->input('application_id', 0);
        if ($applicationId > 0) $query->whereIn('id', IdentityProviderApplication::where('application_id', $applicationId)->column('identity_provider_id'));
        $scope = (string) $request->input('scope_type', '');
        if ($scope !== '') $query->where('scope_type', $scope);
        $keyword = trim((string) $request->input('keywords', ''));
        if ($keyword !== '') $query->whereLike('name', '%' . $keyword . '%');
        $data = $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
        $data['data'] = array_map(fn (array $provider): array => $this->safeProvider($provider), $data['data'] ?? []);
        return $this->success($data);
    }

    #[Permission('SandIAM 身份源读取', 'sand_iam:identity_provider:read')]
    public function read(Request $request): Response
    {
        $provider = IdentityProvider::find((int) $request->input('id', 0));
        if ($provider === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 身份源不存在或当前账号无权访问', 400);
        $this->assertProviderVisible($provider);
        return $this->success($this->safeProvider($provider->toArray()));
    }

    #[Permission('SandIAM 身份源保存', 'sand_iam:identity_provider:save')]
    public function save(Request $request): Response
    {
        $payload = $this->payload($request, false);
        [$organization, $application, $scope] = $this->creationScope($payload);
        if ($scope === 'application') {
            $this->access()->assertApplication((int) $application->id);
        } else {
            $this->access()->assertOrganization((int) $organization->id);
        }
        try {
            Db::startTrans();
            $provider = IdentityProvider::create([
                'organization_id' => (int) $organization->id,
                'application_id' => $scope === 'application' ? (int) $application->id : null,
                'scope_type' => $scope,
                'provider_type' => 'local',
                'code' => (string) $payload['code'],
                'name' => mb_substr(trim((string) $payload['name']), 0, 128),
                'status' => (int) ($payload['status'] ?? 1),
            ]);
            $provider = IdentityProvider::where('id', (int) $provider->id)->lock(true)->find();
            if ($provider === null || !preg_match('/^[A-Za-z0-9_-]{20,128}$/', (string) $provider->public_code)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 500);
            if ($application !== null) IdentityProviderApplication::create(['identity_provider_id' => (int) $provider->id, 'application_id' => (int) $application->id, 'organization_id' => (int) $organization->id, 'provider_scope_application_key' => $scope === 'application' ? (int) $application->id : 0, 'status' => 1]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFLICT', 409);
            throw $exception;
        }
        $this->audit('create', (int) $provider->id, $request);
        return $this->success(['id' => (int) $provider->id, 'public_code' => (string) $provider->public_code], '保存成功');
    }

    #[Permission('SandIAM 身份源更新', 'sand_iam:identity_provider:update')]
    public function update(Request $request): Response
    {
        $provider = $this->find($request);
        $payload = $this->payload($request, true);
        unset($payload['organization_id'], $payload['application_id'], $payload['scope_type'], $payload['code']);
        if ($payload !== []) $provider->save($payload);
        $this->audit('update', (int) $provider->id, $request);
        return $this->success('更新成功');
    }

    #[Permission('SandIAM 身份源停用', 'sand_iam:identity_provider:disable')]
    public function disable(Request $request): Response
    {
        $provider = $this->find($request);
        $provider->save(['status' => 2]);
        $this->audit('disable', (int) $provider->id, $request);
        return $this->success('已停用');
    }

    protected function payload(Request $request, bool $updating): array
    {
        $payload = parent::payload($request, $updating);
        if (!$updating) {
            if (!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', (string) $payload['code'])) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 身份源标识须以小写字母开头，只能包含小写字母、数字、点、下划线、冒号或短横线', 400);
            if (trim((string) $payload['name']) === '') throw new ApiException('SAND_IAM_VALIDATION_ERROR: 名称不能为空', 400);
        }
        return $payload;
    }

    protected function scopeIndexToOrganizations(object $query): void
    {
        if ($this->access()->isSuperAdmin()) return;
        $providerIds = [];
        $organizationIds = $this->access()->organizationIds();
        if ($organizationIds !== []) {
            $providerIds = IdentityProvider::whereIn('organization_id', $organizationIds)->column('id');
        }
        $applicationIds = $this->access()->applicationIds();
        if ($applicationIds !== []) {
            $providerIds = array_merge($providerIds, IdentityProviderApplication::whereIn('application_id', $applicationIds)->where('status', 1)->column('identity_provider_id'));
        }
        $providerIds = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $providerIds)));
        if ($providerIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn('id', $providerIds);
    }

    protected function organizationIdForModel(object $model): ?int { return isset($model->organization_id) ? (int) $model->organization_id : null; }

    protected function assertModelAccess(object $model): void
    {
        if ((string) ($model->scope_type ?? '') === 'application' && (int) ($model->application_id ?? 0) > 0) {
            $this->access()->assertApplication((int) $model->application_id);
            return;
        }
        $this->access()->assertOrganization((int) ($model->organization_id ?? 0));
    }

    private function assertProviderVisible(IdentityProvider $provider): void
    {
        if ($this->access()->isSuperAdmin()) return;
        if (in_array((int) $provider->organization_id, $this->access()->organizationIds(), true)) return;
        $mountedApplications = IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)
            ->where('status', 1)
            ->column('application_id');
        if (array_intersect($this->access()->applicationIds(), array_map('intval', $mountedApplications)) !== []) return;
        $this->access()->assertOrganization((int) $provider->organization_id);
    }

    /** @return array{0:Organization,1:?Application,2:string} */
    private function creationScope(array $payload): array
    {
        $scope = (string) ($payload['scope_type'] ?? 'application');
        $applicationId = (int) ($payload['application_id'] ?? 0);
        $organizationId = (int) ($payload['organization_id'] ?? 0);
        if (!in_array($scope, ['application', 'organization'], true)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        $application = $applicationId > 0 ? Application::where('id', $applicationId)->where('status', 1)->find() : null;
        if ($scope === 'application') {
            if ($application === null || ($organizationId > 0 && (int) $application->organization_id !== $organizationId)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
            $organization = Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
            if ($organization === null) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
            return [$organization, $application, $scope];
        }
        if ($applicationId !== 0 || $organizationId <= 0) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        $organization = Organization::where('id', $organizationId)->where('status', 1)->find();
        if ($organization === null) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        return [$organization, null, $scope];
    }

    /** @param array<string,mixed> $provider @return array<string,mixed> */
    private function safeProvider(array $provider): array
    {
        return ['id' => (int) ($provider['id'] ?? 0), 'organization_id' => (int) ($provider['organization_id'] ?? 0), 'application_id' => ($provider['application_id'] ?? null) === null ? null : (int) $provider['application_id'], 'scope_type' => (string) ($provider['scope_type'] ?? ''), 'public_code' => (string) ($provider['public_code'] ?? ''), 'provider_type' => (string) ($provider['provider_type'] ?? 'local'), 'code' => (string) ($provider['code'] ?? ''), 'name' => (string) ($provider['name'] ?? ''), 'status' => (int) ($provider['status'] ?? 0), 'config_version' => (int) ($provider['config_version'] ?? 0), 'secret_configured' => !empty($provider['encrypted_config']), 'create_time' => $provider['create_time'] ?? null, 'update_time' => $provider['update_time'] ?? null];
    }

    protected function audit(string $verb, int $id, Request $request): void
    {
        $provider = IdentityProvider::find($id);
        $token = $request->header('check_admin', []);
        (new AuditWriter())->write('admin', (string) (is_array($token) ? ($token['id'] ?? 0) : 0), $provider ? (int) $provider->organization_id : null, $provider?->application_id === null ? null : (int) $provider->application_id, $this->resourceType . '.' . $verb, $this->resourceType, $id, 'succeeded', (string) $request->header('X-Request-Id', bin2hex(random_bytes(12))));
    }
}
