<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\initialization\InitializationPackage;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\ApplicationBusinessAction;
use plugin\SandIam\app\model\IdentityProvider;
use plugin\SandIam\app\model\IdentityProviderApplication;
use plugin\SandIam\app\model\InitializationBinding;
use plugin\SandIam\app\model\InitializationDraft;
use plugin\SandIam\app\model\InitializationDraftRevision;
use plugin\SandIam\app\model\InitializationRun;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\Policy;
use plugin\SandIam\app\model\Resource;
use plugin\SandIam\app\model\Role;
use plugin\SandIam\app\model\UserType;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class InitializationService
{
    private const TABLES = [
        'application' => ['sand_iam_application', Application::class],
        'application_business_action' => ['sand_iam_application_business_action', ApplicationBusinessAction::class],
        'role' => ['sand_iam_role', Role::class],
        'user_type' => ['sand_iam_user_type', UserType::class],
        'resource' => ['sand_iam_resource', Resource::class],
        'identity_provider' => ['sand_iam_identity_provider', IdentityProvider::class],
        'policy' => ['sand_iam_policy', Policy::class],
    ];

    /** @return array<string,mixed> */
    public function export(int $applicationId, string $packageCode): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $packageCode)) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 初始化包代码格式不正确', 400);
        $application = Application::where('id', $applicationId)->find();
        $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
        if ($application === null || $organization === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 接入应用或客户主体不存在', 404);
        $roles = [];
        $roleCodes = [];
        foreach (Role::where('application_id', $applicationId)->order('code')->select() as $role) {
            $roleCodes[(int) $role->id] = (string) $role->code;
            $roles[] = ['code' => (string) $role->code, 'name' => (string) $role->name, 'status' => (int) $role->status];
        }
        $userTypes = [];
        foreach (UserType::where('application_id', $applicationId)->order('code')->select() as $type) $userTypes[] = ['code' => (string) $type->code, 'name' => (string) $type->name, 'status' => (int) $type->status];
        $resources = [];
        $resourceCodes = [];
        foreach (Resource::where('application_id', $applicationId)->order('code')->select() as $resource) {
            $resourceCodes[(int) $resource->id] = (string) $resource->code;
            $resources[] = ['code' => (string) $resource->code, 'name' => (string) $resource->name, 'owner_field' => (string) ($resource->owner_field ?? ''), 'organization_field' => (string) ($resource->organization_field ?? ''), 'status' => (int) $resource->status];
        }
        $businessActions = [];
        foreach (ApplicationBusinessAction::where('application_id', $applicationId)->order('code')->select() as $action) {
            $businessActions[] = [
                'code' => (string) $action->code,
                'name' => (string) $action->name,
                'description' => (string) ($action->description ?? ''),
                'state' => (string) ($action->state ?? 'draft'),
                'status' => (int) $action->status,
            ];
        }
        $providers = [];
        foreach (IdentityProvider::where('organization_id', (int) $organization->id)->where('application_id', $applicationId)->where('scope_type', 'application')->where('provider_type', 'local')->order('code')->select() as $provider) {
            $providers[] = ['code' => (string) $provider->code, 'name' => (string) $provider->name, 'provider_type' => 'local', 'status' => (int) $provider->status];
        }
        $policies = [];
        foreach (Policy::where('application_id', $applicationId)->whereNotNull('role_id')->whereNull('identity_id')->order('id')->select() as $policy) {
            $resourceCode = $resourceCodes[(int) $policy->resource_id] ?? null;
            $roleCode = $roleCodes[(int) $policy->role_id] ?? null;
            if ($resourceCode === null || $roleCode === null) continue;
            $condition = $policy->condition; if (is_string($condition)) $condition = json_decode($condition, true);
            $scope = $policy->scope; if (is_string($scope)) $scope = json_decode($scope, true);
            $policies[] = [
                'key' => 'policy-' . substr(hash('sha256', implode('|', [$resourceCode, $roleCode, (string) $policy->action, (string) $policy->effect, (int) $policy->priority, (int) $policy->id])), 0, 20),
                'resource_code' => $resourceCode, 'role_code' => $roleCode, 'action' => (string) $policy->action, 'effect' => (string) $policy->effect,
                'condition' => is_array($condition) ? $condition : [], 'scope' => is_array($scope) ? $scope : [], 'priority' => (int) $policy->priority,
                'state' => (string) $policy->state, 'status' => (int) $policy->status,
            ];
        }
        return InitializationPackage::normalize([
            'format' => 'sand-iam.initialization/v1', 'package_code' => $packageCode, 'organization_code' => (string) $organization->code,
            'application' => ['code' => (string) $application->code, 'name' => (string) $application->name, 'status' => (int) $application->status],
            'roles' => $roles, 'user_types' => $userTypes, 'resources' => $resources, 'business_actions' => $businessActions, 'identity_providers' => $providers, 'policies' => $policies,
        ]);
    }

    /** @param array<string,mixed> $input @return array{draft_id:int,revision:int,status:int,manifest_hash:string} */
    public function saveDraft(array $input, int $adminId, string $requestId): array
    {
        if ($adminId <= 0) throw new ApiException('SAND_IAM_ADMIN_REQUIRED', 401);
        $requestId = RequestId::normalize($requestId);
        $manifest = InitializationPackage::normalize($input);
        $preview = $this->preview($manifest);
        $fingerprint = IdempotencyService::fingerprint(['manifest_hash' => InitializationPackage::hash($manifest)]);
        $operations = new IdempotencyService();
        $replay = $operations->replayIfCompleted('admin', (string) $adminId, 'initialization_draft.save', $requestId, $fingerprint);
        if ($replay !== null) return $replay['result'];

        Db::startTrans();
        try {
            $result = $operations->execute('admin', (string) $adminId, 'initialization_draft.save', $requestId, $fingerprint, 'initialization_draft', function () use ($manifest, $preview, $adminId, $requestId): array {
                $organizationId = (int) $preview['organization_id'];
                $draft = InitializationDraft::where('organization_id', $organizationId)->where('package_code', $manifest['package_code'])->lock(true)->find();
                if ($draft !== null) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_CONFLICT: 当前客户主体已存在同名初始化草稿', 409);
                $hash = InitializationPackage::hash($manifest);
                $draft = InitializationDraft::create([
                    'organization_id' => $organizationId,
                    'application_id' => $preview['application_id'],
                    'package_code' => $manifest['package_code'],
                    'manifest' => $manifest,
                    'manifest_hash' => $hash,
                    'revision' => 1,
                    'status' => 1,
                    'created_by' => $adminId,
                    'updated_by' => $adminId,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
                $this->appendDraftRevision((int) $draft->id, 1, $manifest, $hash, 1, 'created', $adminId, $requestId);
                (new AuditWriter())->write('admin', (string) $adminId, $organizationId, $preview['application_id'], 'initialization_draft.create', 'initialization_draft', (int) $draft->id, 'succeeded', $requestId, ['package_code' => $manifest['package_code'], 'manifest_hash' => $hash]);
                $payload = ['draft_id' => (int) $draft->id, 'revision' => 1, 'status' => 1, 'manifest_hash' => $hash];
                return ['resource_id' => (int) $draft->id, 'result' => $payload];
            }, 0, false);
            Db::commit();
        } catch (ApiException $exception) {
            Db::rollback();
            if (str_starts_with($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_RETRY_REQUIRED')) {
                $replay = $operations->replayIfCompleted('admin', (string) $adminId, 'initialization_draft.save', $requestId, $fingerprint);
                if ($replay !== null) return $replay['result'];
                $conflict = InitializationDraft::where('organization_id', (int) $preview['organization_id'])
                    ->where('package_code', $manifest['package_code'])
                    ->find();
                if ($conflict !== null) {
                    throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_CONFLICT: 当前客户主体已存在同名初始化草稿', 409);
                }
            }
            throw $exception;
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        return $result['result'];
    }

    /** @param array<string,mixed> $input @return array{draft_id:int,revision:int,status:int,manifest_hash:string} */
    public function updateDraft(int $draftId, int $expectedRevision, array $input, int $adminId, string $requestId): array
    {
        if ($draftId <= 0 || $expectedRevision < 1) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_REVISION_INVALID', 400);
        if ($adminId <= 0) throw new ApiException('SAND_IAM_ADMIN_REQUIRED', 401);
        $requestId = RequestId::normalize($requestId);
        $manifest = InitializationPackage::normalize($input);
        $preview = $this->preview($manifest);
        $hash = InitializationPackage::hash($manifest);
        $fingerprint = IdempotencyService::fingerprint(['draft_id' => $draftId, 'expected_revision' => $expectedRevision, 'manifest_hash' => $hash]);
        return $this->changeDraft('update', $draftId, $expectedRevision, $manifest, $hash, $preview, $adminId, $requestId, $fingerprint);
    }

    /** @return array{draft_id:int,revision:int,status:int,manifest_hash:string} */
    public function disableDraft(int $draftId, int $expectedRevision, int $adminId, string $requestId): array
    {
        if ($draftId <= 0 || $expectedRevision < 1) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_REVISION_INVALID', 400);
        if ($adminId <= 0) throw new ApiException('SAND_IAM_ADMIN_REQUIRED', 401);
        $requestId = RequestId::normalize($requestId);
        $fingerprint = IdempotencyService::fingerprint(['draft_id' => $draftId, 'expected_revision' => $expectedRevision, 'status' => 2]);
        $operations = new IdempotencyService();
        $replay = $operations->replayIfCompleted('admin', (string) $adminId, 'initialization_draft.disable', $requestId, $fingerprint);
        if ($replay !== null) return $replay['result'];
        Db::startTrans();
        try {
            $result = $operations->execute('admin', (string) $adminId, 'initialization_draft.disable', $requestId, $fingerprint, 'initialization_draft', function () use ($draftId, $expectedRevision, $adminId, $requestId): array {
                $draft = InitializationDraft::where('id', $draftId)->lock(true)->find();
                if ($draft === null) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_NOT_FOUND', 404);
                if ((int) $draft->revision !== $expectedRevision) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_REVISION_STALE: 草稿已被其他管理员修改，请刷新后重试', 409);
                if ((int) $draft->status !== 1) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_ALREADY_DISABLED', 409);
                $revision = $expectedRevision + 1;
                $manifest = $this->draftManifest($draft);
                $draft->save(['status' => 2, 'revision' => $revision, 'updated_by' => $adminId, 'disabled_by' => $adminId, 'disabled_time' => date('Y-m-d H:i:s'), 'update_time' => date('Y-m-d H:i:s')]);
                $this->appendDraftRevision($draftId, $revision, $manifest, (string) $draft->manifest_hash, 2, 'disabled', $adminId, $requestId);
                (new AuditWriter())->write('admin', (string) $adminId, (int) $draft->organization_id, $draft->application_id === null ? null : (int) $draft->application_id, 'initialization_draft.disable', 'initialization_draft', $draftId, 'succeeded', $requestId, ['package_code' => (string) $draft->package_code, 'manifest_hash' => (string) $draft->manifest_hash]);
                $payload = ['draft_id' => $draftId, 'revision' => $revision, 'status' => 2, 'manifest_hash' => (string) $draft->manifest_hash];
                return ['resource_id' => $draftId, 'result' => $payload];
            }, 0, false);
            Db::commit();
        } catch (ApiException $exception) {
            Db::rollback();
            if (str_starts_with($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_RETRY_REQUIRED')) return $operations->replay('admin', (string) $adminId, 'initialization_draft.disable', $requestId, $fingerprint)['result'];
            throw $exception;
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        return $result['result'];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function preview(array $input): array
    {
        $manifest = InitializationPackage::normalize($input);
        $organization = Organization::where('code', $manifest['organization_code'])->where('status', 1)->find();
        if ($organization === null) throw new ApiException('SAND_IAM_INITIALIZATION_ORGANIZATION_NOT_FOUND: 初始化包只能应用到已存在且启用的客户主体', 404);
        $applicationSpec = $manifest['application'];
        $application = Application::where('organization_id', (int) $organization->id)->where('code', $applicationSpec['code'])->find();
        $changes = [];
        $changes[] = $this->change('application', $applicationSpec['code'], $application, $applicationSpec, ['name', 'status']);
        if ($application === null) {
            foreach (['roles' => 'role', 'user_types' => 'user_type', 'resources' => 'resource', 'business_actions' => 'application_business_action', 'identity_providers' => 'identity_provider', 'policies' => 'policy'] as $section => $type) {
                foreach ($manifest[$section] as $item) $changes[] = ['object_type' => $type, 'object_key' => (string) ($item['key'] ?? $item['code']), 'operation' => 'create', 'before' => null, 'after' => $item];
            }
        } else {
            $applicationId = (int) $application->id;
            foreach ($manifest['roles'] as $item) $changes[] = $this->change('role', $item['code'], Role::where('application_id', $applicationId)->where('code', $item['code'])->find(), $item, ['name', 'status']);
            foreach ($manifest['user_types'] as $item) $changes[] = $this->change('user_type', $item['code'], UserType::where('application_id', $applicationId)->where('code', $item['code'])->find(), $item, ['name', 'status']);
            foreach ($manifest['resources'] as $item) $changes[] = $this->change('resource', $item['code'], Resource::where('application_id', $applicationId)->where('code', $item['code'])->find(), $item, ['name', 'owner_field', 'organization_field', 'status']);
            foreach ($manifest['business_actions'] as $item) {
                $changes[] = $this->change('application_business_action', $item['code'], ApplicationBusinessAction::where('application_id', $applicationId)->where('code', $item['code'])->find(), $item, ['name', 'description', 'state', 'status']);
            }
            foreach ($manifest['identity_providers'] as $item) {
                $provider = IdentityProvider::where('organization_id', (int) $organization->id)->where('application_id', $applicationId)->where('scope_type', 'application')->where('code', $item['code'])->find();
                if ($provider === null && IdentityProvider::where('organization_id', (int) $organization->id)->where('code', $item['code'])->find() !== null) throw new ApiException('SAND_IAM_INITIALIZATION_PROVIDER_SCOPE_CONFLICT: 同一客户主体已有其他范围的同名身份源', 409);
                if ($provider !== null && IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('status', 1)->find() === null) throw new ApiException('SAND_IAM_INITIALIZATION_PROVIDER_MOUNT_MISSING: 现有应用身份源缺少挂载关系，请先修复数据', 409);
                $changes[] = $this->change('identity_provider', $item['code'], $provider, $item, ['name', 'provider_type', 'status']);
            }
            foreach ($manifest['policies'] as $item) {
                $resource = Resource::where('application_id', $applicationId)->where('code', $item['resource_code'])->find();
                $role = Role::where('application_id', $applicationId)->where('code', $item['role_code'])->find();
                $policy = ($resource === null || $role === null) ? null : $this->policy($applicationId, $manifest['package_code'], $item['key'], (int) $resource->id, (int) $role->id, $item);
                $changes[] = $this->policyChange($item['key'], $policy, $item);
            }
        }
        $counts = ['create' => 0, 'update' => 0, 'no_change' => 0];
        foreach ($changes as $change) $counts[$change['operation']]++;
        $packageHash = InitializationPackage::hash($manifest);
        return [
            'package_hash' => $packageHash,
            'preview_hash' => hash('sha256', $packageHash . "\0" . $this->canonicalJson($changes)),
            'organization_id' => (int) $organization->id,
            'application_id' => $application === null ? null : (int) $application->id,
            'changes' => $changes,
            'counts' => $counts,
            'warnings' => ['初始化包采用合并模式，不会停用或删除包中未列出的现有配置。', '外部身份源密钥、用户账号、凭证和业务数据不会进入初始化包。'],
            'manifest' => $manifest,
        ];
    }

    /** @param array<string,mixed> $input @return array{run_id:int,application_id:int,package_hash:string,applied:array<string,int>} */
    public function apply(array $input, string $expectedPreviewHash, int $adminId, string $requestId): array
    {
        if ($adminId <= 0) throw new ApiException('SAND_IAM_ADMIN_REQUIRED', 401);
        $requestId = RequestId::normalize($requestId);
        $manifest = InitializationPackage::normalize($input);
        $fingerprint = IdempotencyService::fingerprint(['manifest' => $manifest, 'preview_hash' => $expectedPreviewHash]);
        $operations = new IdempotencyService();
        $replay = $operations->replayIfCompleted('admin', (string) $adminId, 'initialization.apply', $requestId, $fingerprint);
        if ($replay !== null) return $replay['result'];

        $preview = $this->preview($manifest);
        if (!hash_equals((string) $preview['preview_hash'], $expectedPreviewHash)) throw new ApiException('SAND_IAM_INITIALIZATION_PREVIEW_STALE: 配置已变化，请重新预检并确认差异', 409);
        $manifest = $preview['manifest'];
        Db::startTrans();
        try {
            $result = $operations->execute(
                'admin',
                (string) $adminId,
                'initialization.apply',
                $requestId,
                $fingerprint,
                'initialization_run',
                function () use ($preview, $manifest, $expectedPreviewHash, $adminId, $requestId): array {
            $organization = Organization::where('id', (int) $preview['organization_id'])->where('status', 1)->lock(true)->find();
            if ($organization === null) throw new ApiException('SAND_IAM_INITIALIZATION_ORGANIZATION_NOT_FOUND', 404);
            $fresh = $this->preview($manifest);
            if (!hash_equals((string) $fresh['preview_hash'], $expectedPreviewHash)) throw new ApiException('SAND_IAM_INITIALIZATION_PREVIEW_STALE: 配置已变化，请重新预检并确认差异', 409);
            $recorded = [];
            $application = Application::where('organization_id', (int) $organization->id)->where('code', $manifest['application']['code'])->lock(true)->find();
            $application = $this->upsert($recorded, 'application', $manifest['application']['code'], $application, ['organization_id' => (int) $organization->id] + $manifest['application'], ['name', 'status']);
            $applicationId = (int) $application->id;
            $this->bind($applicationId, $manifest['package_code'], 'application', $manifest['application']['code'], (int) $application->id);

            foreach ($manifest['business_actions'] as $item) {
                $model = ApplicationBusinessAction::where('application_id', $applicationId)->where('code', $item['code'])->lock(true)->find();
                $model = $this->upsert($recorded, 'application_business_action', $item['code'], $model, ['application_id' => $applicationId] + $item, ['name', 'description', 'state', 'status']);
                $this->bind($applicationId, $manifest['package_code'], 'application_business_action', $item['code'], (int) $model->id);
            }

            $roles = [];
            foreach ($manifest['roles'] as $item) {
                $model = Role::where('application_id', $applicationId)->where('code', $item['code'])->lock(true)->find();
                $roles[$item['code']] = $this->upsert($recorded, 'role', $item['code'], $model, ['application_id' => $applicationId] + $item, ['name', 'status']);
                $this->bind($applicationId, $manifest['package_code'], 'role', $item['code'], (int) $roles[$item['code']]->id);
            }
            foreach ($manifest['user_types'] as $item) {
                $model = UserType::where('application_id', $applicationId)->where('code', $item['code'])->lock(true)->find();
                $model = $this->upsert($recorded, 'user_type', $item['code'], $model, ['application_id' => $applicationId] + $item, ['name', 'status']);
                $this->bind($applicationId, $manifest['package_code'], 'user_type', $item['code'], (int) $model->id);
            }
            $resources = [];
            foreach ($manifest['resources'] as $item) {
                $model = Resource::where('application_id', $applicationId)->where('code', $item['code'])->lock(true)->find();
                $resources[$item['code']] = $this->upsert($recorded, 'resource', $item['code'], $model, ['application_id' => $applicationId] + $item, ['name', 'owner_field', 'organization_field', 'status']);
                $this->bind($applicationId, $manifest['package_code'], 'resource', $item['code'], (int) $resources[$item['code']]->id);
            }
            foreach ($manifest['identity_providers'] as $item) {
                $model = IdentityProvider::where('organization_id', (int) $organization->id)->where('application_id', $applicationId)->where('scope_type', 'application')->where('code', $item['code'])->lock(true)->find();
                if ($model === null && IdentityProvider::where('organization_id', (int) $organization->id)->where('code', $item['code'])->lock(true)->find() !== null) throw new ApiException('SAND_IAM_INITIALIZATION_PROVIDER_SCOPE_CONFLICT: 同一客户主体已有其他范围的同名身份源', 409);
                $providerWasNew = $model === null;
                $payload = ['organization_id' => (int) $organization->id, 'application_id' => $applicationId, 'scope_type' => 'application'] + $item;
                $model = $this->upsert($recorded, 'identity_provider', $item['code'], $model, $payload, ['name', 'provider_type', 'status']);
                if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', (string) $model->public_code)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 身份源公开代码没有正确生成', 500);
                $mount = IdentityProviderApplication::where('identity_provider_id', (int) $model->id)->where('application_id', $applicationId)->lock(true)->find();
                if (!$providerWasNew && ($mount === null || (int) $mount->status !== 1)) throw new ApiException('SAND_IAM_INITIALIZATION_PROVIDER_MOUNT_MISSING: 现有应用身份源缺少启用挂载，请先修复数据', 409);
                if ($mount === null) {
                    IdentityProviderApplication::create(['identity_provider_id' => (int) $model->id, 'application_id' => $applicationId, 'organization_id' => (int) $organization->id, 'provider_scope_application_key' => $applicationId, 'status' => 1]);
                }
                $this->bind($applicationId, $manifest['package_code'], 'identity_provider', $item['code'], (int) $model->id);
            }
            foreach ($manifest['policies'] as $item) {
                $resource = $resources[$item['resource_code']];
                $role = $roles[$item['role_code']];
                $model = $this->policy($applicationId, $manifest['package_code'], $item['key'], (int) $resource->id, (int) $role->id, $item, true);
                $payload = [
                    'application_id' => $applicationId, 'resource_id' => (int) $resource->id, 'role_id' => (int) $role->id, 'identity_id' => null,
                    'action' => $item['action'], 'effect' => $item['effect'], 'condition' => $item['condition'], 'scope' => $item['scope'],
                    'priority' => $item['priority'], 'state' => $item['state'], 'status' => $item['status'],
                ];
                $model = $this->upsert($recorded, 'policy', $item['key'], $model, $payload, ['resource_id', 'role_id', 'action', 'effect', 'condition', 'scope', 'priority', 'state', 'status']);
                $this->bind($applicationId, $manifest['package_code'], 'policy', $item['key'], (int) $model->id);
            }
            $run = InitializationRun::create([
                'organization_id' => (int) $organization->id, 'application_id' => $applicationId,
                'package_code' => $manifest['package_code'], 'package_hash' => $preview['package_hash'], 'preview_hash' => $preview['preview_hash'],
                'manifest' => $manifest, 'changes' => $recorded, 'state' => 'applied', 'applied_by' => $adminId,
                'applied_time' => date('Y-m-d H:i:s'), 'request_id' => $requestId,
            ]);
            (new AuditWriter())->write('admin', (string) $adminId, (int) $organization->id, $applicationId, 'initialization.apply', 'initialization_run', (int) $run->id, 'succeeded', $requestId, ['package_code' => $manifest['package_code'], 'package_hash' => $preview['package_hash'], 'change_count' => count($recorded)]);
            $counts = ['create' => 0, 'update' => 0];
            foreach ($recorded as $change) $counts[$change['operation']]++;
            return ['resource_id' => (int) $run->id, 'result' => ['run_id' => (int) $run->id, 'application_id' => $applicationId, 'package_hash' => (string) $preview['package_hash'], 'applied' => $counts]];
                },
                0,
                false,
            );
            Db::commit();
        } catch (ApiException $exception) {
            Db::rollback();
            if (str_starts_with($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_RETRY_REQUIRED')) {
                return $operations->replay('admin', (string) $adminId, 'initialization.apply', $requestId, $fingerprint)['result'];
            }
            throw $exception;
        }
        catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return $result['result'];
    }

    /** @return array{run_id:int,rolled_back:int} */
    public function rollback(int $runId, string $confirmation, int $adminId, string $requestId): array
    {
        if ($runId <= 0 || $adminId <= 0) throw new ApiException('SAND_IAM_INITIALIZATION_ROLLBACK_INVALID', 400);
        $requestId = RequestId::normalize($requestId);
        $fingerprint = IdempotencyService::fingerprint(['run_id' => $runId, 'confirmation' => $confirmation]);
        $operations = new IdempotencyService();
        $replay = $operations->replayIfCompleted('admin', (string) $adminId, 'initialization.rollback', $requestId, $fingerprint);
        if ($replay !== null) return $replay['result'];
        Db::startTrans();
        try {
            $result = $operations->execute(
                'admin',
                (string) $adminId,
                'initialization.rollback',
                $requestId,
                $fingerprint,
                'initialization_run',
                function () use ($runId, $confirmation, $adminId, $requestId): array {
            $run = InitializationRun::where('id', $runId)->where('state', 'applied')->lock(true)->find();
            if ($run === null) throw new ApiException('SAND_IAM_INITIALIZATION_RUN_NOT_FOUND', 404);
            $expected = hash('sha256', "sand-iam-init-rollback\0{$runId}\0{$run->package_hash}");
            if (!hash_equals($expected, $confirmation)) throw new ApiException('SAND_IAM_INITIALIZATION_ROLLBACK_CONFIRMATION_INVALID', 403);
            $changes = $run->changes;
            if (is_string($changes)) $changes = json_decode($changes, true);
            if (!is_array($changes)) throw new ApiException('SAND_IAM_INITIALIZATION_RUN_INVALID', 500);
            $organizationId = (int) $run->organization_id;
            $applicationId = $run->application_id === null ? null : (int) $run->application_id;
            $applicationWasCreated = false;
            foreach ($changes as $change) {
                if (($change['object_type'] ?? '') === 'application' && ($change['operation'] ?? '') === 'create') $applicationWasCreated = true;
            }
            if ($applicationWasCreated) $run->save(['application_id' => null]);
            foreach (array_reverse($changes) as $change) $this->rollbackChange($change);
            if ($applicationId !== null) InitializationBinding::where('application_id', $applicationId)->where('package_code', (string) $run->package_code)->delete();
            $run->save(['state' => 'rolled_back', 'application_id' => $applicationWasCreated ? null : $applicationId, 'rollback_by' => $adminId, 'rollback_time' => date('Y-m-d H:i:s')]);
            (new AuditWriter())->write('admin', (string) $adminId, $organizationId, $applicationWasCreated ? null : $applicationId, 'initialization.rollback', 'initialization_run', $runId, 'succeeded', $requestId, ['package_code' => (string) $run->package_code, 'change_count' => count($changes)]);
            return ['resource_id' => $runId, 'result' => ['run_id' => $runId, 'rolled_back' => count($changes)]];
                },
                0,
                false,
            );
            Db::commit();
        } catch (ApiException $exception) {
            Db::rollback();
            if (str_starts_with($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_RETRY_REQUIRED')) {
                return $operations->replay('admin', (string) $adminId, 'initialization.rollback', $requestId, $fingerprint)['result'];
            }
            throw $exception;
        }
        catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return $result['result'];
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $preview
     * @return array{draft_id:int,revision:int,status:int,manifest_hash:string}
     */
    private function changeDraft(string $action, int $draftId, int $expectedRevision, array $manifest, string $hash, array $preview, int $adminId, string $requestId, string $fingerprint): array
    {
        $operations = new IdempotencyService();
        $operationName = 'initialization_draft.' . $action;
        $replay = $operations->replayIfCompleted('admin', (string) $adminId, $operationName, $requestId, $fingerprint);
        if ($replay !== null) return $replay['result'];
        Db::startTrans();
        try {
            $result = $operations->execute('admin', (string) $adminId, $operationName, $requestId, $fingerprint, 'initialization_draft', function () use ($action, $draftId, $expectedRevision, $manifest, $hash, $preview, $adminId, $requestId): array {
                $draft = InitializationDraft::where('id', $draftId)->lock(true)->find();
                if ($draft === null) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_NOT_FOUND', 404);
                if ((int) $draft->revision !== $expectedRevision) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_REVISION_STALE: 草稿已被其他管理员修改，请刷新后重试', 409);
                if ((int) $draft->status !== 1) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_DISABLED: 已停用草稿不可修改', 409);
                if ((int) $draft->organization_id !== (int) $preview['organization_id']) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_SCOPE_MISMATCH: 草稿不可变更客户主体', 409);
                if ((string) $draft->package_code !== $manifest['package_code']) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_CODE_IMMUTABLE: 草稿代码创建后不可变更', 409);
                $draftApplicationId = $draft->application_id === null ? null : (int) $draft->application_id;
                $previewApplicationId = $preview['application_id'] === null ? null : (int) $preview['application_id'];
                if ($draftApplicationId !== null && $previewApplicationId !== $draftApplicationId) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_SCOPE_MISMATCH: 草稿不可变更接入应用', 409);
                $applicationId = $draftApplicationId ?? $previewApplicationId;
                $revision = $expectedRevision + 1;
                $draft->save(['application_id' => $applicationId, 'manifest' => $manifest, 'manifest_hash' => $hash, 'revision' => $revision, 'updated_by' => $adminId, 'update_time' => date('Y-m-d H:i:s')]);
                $this->appendDraftRevision($draftId, $revision, $manifest, $hash, 1, 'updated', $adminId, $requestId);
                (new AuditWriter())->write('admin', (string) $adminId, (int) $draft->organization_id, $applicationId, 'initialization_draft.update', 'initialization_draft', $draftId, 'succeeded', $requestId, ['package_code' => (string) $draft->package_code, 'manifest_hash' => $hash]);
                $payload = ['draft_id' => $draftId, 'revision' => $revision, 'status' => 1, 'manifest_hash' => $hash];
                return ['resource_id' => $draftId, 'result' => $payload];
            }, 0, false);
            Db::commit();
        } catch (ApiException $exception) {
            Db::rollback();
            if (str_starts_with($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_RETRY_REQUIRED')) return $operations->replay('admin', (string) $adminId, $operationName, $requestId, $fingerprint)['result'];
            throw $exception;
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        return $result['result'];
    }

    /** @return array<string,mixed> */
    private function draftManifest(InitializationDraft $draft): array
    {
        $manifest = $draft->manifest;
        if (is_string($manifest)) $manifest = json_decode($manifest, true);
        if (!is_array($manifest)) throw new ApiException('SAND_IAM_INITIALIZATION_DRAFT_INVALID', 500);
        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function appendDraftRevision(int $draftId, int $revision, array $manifest, string $hash, int $status, string $action, int $adminId, string $requestId): void
    {
        InitializationDraftRevision::create([
            'draft_id' => $draftId,
            'revision' => $revision,
            'manifest' => $manifest,
            'manifest_hash' => $hash,
            'status' => $status,
            'action' => $action,
            'actor_id' => $adminId,
            'request_id' => $requestId,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param list<array<string,mixed>> $recorded @param list<string> $mutable */
    private function upsert(array &$recorded, string $type, string $key, ?object $model, array $payload, array $mutable): object
    {
        [, $class] = self::TABLES[$type];
        if ($model === null) {
            $model = $class::create($payload);
            $after = $this->snapshot($model, array_keys($payload));
            $recorded[] = $this->record($type, $key, 'create', (int) $model->id, null, $after);
            return $model;
        }
        $before = $this->snapshot($model, $mutable);
        $after = [];
        foreach ($mutable as $field) $after[$field] = $payload[$field] ?? null;
        if ($this->canonicalJson($before) !== $this->canonicalJson($after)) {
            $model->save($after);
            $recorded[] = $this->record($type, $key, 'update', (int) $model->id, $before, $after);
        }
        return $model;
    }

    /** @param array<string,mixed> $change */
    private function rollbackChange(array $change): void
    {
        $type = (string) ($change['object_type'] ?? '');
        if (!isset(self::TABLES[$type])) throw new ApiException('SAND_IAM_INITIALIZATION_RUN_INVALID', 500);
        [$table, $class] = self::TABLES[$type];
        $id = (int) ($change['resource_id'] ?? 0);
        $model = $class::where('id', $id)->lock(true)->find();
        if ($model === null) throw new ApiException('SAND_IAM_INITIALIZATION_ROLLBACK_DRIFT: 初始化对象已被删除，不能安全回滚', 409);
        $after = is_array($change['after'] ?? null) ? $change['after'] : [];
        $current = $this->snapshot($model, array_keys($after));
        if ($this->canonicalJson($current) !== $this->canonicalJson($after)) throw new ApiException('SAND_IAM_INITIALIZATION_ROLLBACK_DRIFT: 初始化后配置又被修改，请先人工处理差异', 409);
        if (($change['operation'] ?? '') === 'update') {
            $before = is_array($change['before'] ?? null) ? $change['before'] : throw new ApiException('SAND_IAM_INITIALIZATION_RUN_INVALID', 500);
            $model->save($before);
            return;
        }
        if (($change['operation'] ?? '') !== 'create') throw new ApiException('SAND_IAM_INITIALIZATION_RUN_INVALID', 500);
        if ($type === 'identity_provider') IdentityProviderApplication::where('identity_provider_id', $id)->delete();
        Db::table($table)->where('id', $id)->delete();
    }

    private function bind(int $applicationId, string $packageCode, string $type, string $key, int $resourceId): void
    {
        [$table] = self::TABLES[$type];
        $binding = InitializationBinding::where('application_id', $applicationId)->where('package_code', $packageCode)->where('object_type', $type)->where('object_key', $key)->lock(true)->find();
        if ($binding === null) InitializationBinding::create(['application_id' => $applicationId, 'package_code' => $packageCode, 'object_type' => $type, 'object_key' => $key, 'table_name' => $table, 'resource_id' => $resourceId]);
        elseif ((string) $binding->table_name !== $table || (int) $binding->resource_id !== $resourceId) throw new ApiException('SAND_IAM_INITIALIZATION_BINDING_CONFLICT: 初始化键已绑定到其他对象', 409);
    }

    /** @param array<string,mixed> $item */
    private function policy(int $applicationId, string $packageCode, string $key, int $resourceId, int $roleId, array $item, bool $lock = false): ?Policy
    {
        $bindingQuery = InitializationBinding::where('application_id', $applicationId)->where('package_code', $packageCode)->where('object_type', 'policy')->where('object_key', $key);
        if ($lock) $bindingQuery->lock(true);
        $binding = $bindingQuery->find();

        $query = Policy::where('application_id', $applicationId)->where('resource_id', $resourceId)->where('role_id', $roleId)
            ->whereNull('identity_id')->where('action', $item['action'])->where('effect', $item['effect'])->where('priority', $item['priority']);
        if ($lock) $query->lock(true);
        $rows = $query->limit(2)->select()->all();
        if (count($rows) > 1) throw new ApiException('SAND_IAM_INITIALIZATION_POLICY_AMBIGUOUS: 现有策略存在重复自然键，请先人工整理', 409);
        $natural = $rows[0] ?? null;
        if ($binding === null) return $natural;
        if ((string) $binding->table_name !== self::TABLES['policy'][0]) throw new ApiException('SAND_IAM_INITIALIZATION_BINDING_CONFLICT: 策略键绑定到了错误的数据表', 409);

        $boundQuery = Policy::where('id', (int) $binding->resource_id)->where('application_id', $applicationId)->whereNull('identity_id');
        if ($lock) $boundQuery->lock(true);
        $bound = $boundQuery->find();
        if ($bound === null) throw new ApiException('SAND_IAM_INITIALIZATION_BINDING_DRIFT: 策略键绑定的记录已不存在或不再是角色策略', 409);
        if ($natural !== null && (int) $natural->id !== (int) $bound->id) throw new ApiException('SAND_IAM_INITIALIZATION_POLICY_TARGET_CONFLICT: 策略修改后的目标已被其他策略占用', 409);
        return $bound;
    }

    /** @param array<string,mixed> $desired @return array<string,mixed> */
    private function policyChange(string $key, ?Policy $model, array $desired): array
    {
        if ($model === null) return ['object_type' => 'policy', 'object_key' => $key, 'operation' => 'create', 'before' => null, 'after' => $desired];
        $resource = Resource::where('id', (int) $model->resource_id)->where('application_id', (int) $model->application_id)->find();
        $role = Role::where('id', (int) $model->role_id)->where('application_id', (int) $model->application_id)->find();
        if ($resource === null || $role === null) throw new ApiException('SAND_IAM_INITIALIZATION_BINDING_DRIFT: 策略引用的角色或业务资源已不存在', 409);
        $before = [
            'key' => $key,
            'resource_code' => (string) $resource->code,
            'role_code' => (string) $role->code,
            'action' => (string) $model->action,
            'effect' => (string) $model->effect,
            'condition' => $this->jsonObject($model->condition),
            'scope' => $this->jsonObject($model->scope),
            'priority' => (int) $model->priority,
            'state' => (string) $model->state,
            'status' => (int) $model->status,
        ];
        return ['object_type' => 'policy', 'object_key' => $key, 'operation' => $this->canonicalJson($before) === $this->canonicalJson($desired) ? 'no_change' : 'update', 'before' => $before, 'after' => $desired];
    }

    /** @param list<string> $fields @param array<string,mixed> $identity */
    private function change(string $type, string $key, ?object $model, array $desired, array $fields, array $identity = []): array
    {
        $after = $identity;
        foreach ($fields as $field) $after[$field] = $desired[$field] ?? null;
        if ($model === null) return ['object_type' => $type, 'object_key' => $key, 'operation' => 'create', 'before' => null, 'after' => $desired];
        $before = $identity + $this->snapshot($model, $fields);
        return ['object_type' => $type, 'object_key' => $key, 'operation' => $this->canonicalJson($before) === $this->canonicalJson($after) ? 'no_change' : 'update', 'before' => $before, 'after' => $after];
    }

    /** @param list<string> $fields @return array<string,mixed> */
    private function snapshot(object $model, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $value = $model->$field;
            if (is_string($value) && in_array($field, ['condition', 'scope'], true)) $value = json_decode($value, true);
            $result[$field] = $value;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function record(string $type, string $key, string $operation, int $id, ?array $before, array $after): array
    {
        return ['object_type' => $type, 'object_key' => $key, 'operation' => $operation, 'resource_id' => $id, 'before' => $before, 'after' => $after];
    }

    private function canonicalJson(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item);
            foreach ($item as $key => $child) $item[$key] = $sort($child);
            return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        return is_array($value) ? $value : [];
    }

}
