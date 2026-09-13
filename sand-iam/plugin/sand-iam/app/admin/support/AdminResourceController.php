<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\support;

use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\IdempotencyService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

abstract class AdminResourceController extends BaseController
{
    /** @var class-string */
    protected string $modelClass;
    /** @var list<string> */
    protected array $writeFields = [];
    /** @var list<string> */
    protected array $requiredFields = ['code', 'name'];
    protected string $resourceType;
    protected bool $requiresSuperAdmin = false;
    /**
     * Opt in only for create routes whose object and audit must be atomic.
     * Existing resource controllers retain their established behavior.
     */
    protected bool $atomicCreateAudit = false;
    protected ?string $keywordField = 'name';

    public function index(Request $request): Response
    {
        $modelClass = $this->modelClass;
        $page = max(1, (int) $request->input('page', 1));
        $limit = min(100, max(1, (int) $request->input('limit', 20)));
        $query = $modelClass::order('id', 'desc');
        $this->assertAdministrativeAccess();
        $this->scopeIndexToOrganizations($query);
        foreach (['organization_id', 'application_id', 'environment_id', 'service_id', 'workload_client_id', 'status'] as $field) {
            $value = $request->input($field, '');
            if ($value !== '' && in_array($field, $this->writeFields, true)) {
                $query->where($field, (int) $value);
            }
        }
        $keyword = trim((string) $request->input('keywords', ''));
        if ($keyword !== '' && $this->keywordField !== null) {
            $query->whereLike($this->keywordField, '%' . $keyword . '%');
        }
        return $this->success($query->paginate(['page' => $page, 'list_rows' => $limit])->toArray());
    }

    public function read(Request $request): Response
    {
        return $this->success($this->find($request)->toArray());
    }

    public function save(Request $request): Response
    {
        $this->assertAdministrativeAccess();
        $payload = $this->payload($request, false);
        $payload = $this->normalizePayload($payload);
        $this->assertReferences($payload);
        $this->assertPayloadAccess($payload);
        $modelClass = $this->modelClass;
        if ($this->atomicCreateAudit) {
            $token = $request->header('check_admin', []);
            $adminId = is_array($token) ? (int) ($token['id'] ?? 0) : 0;
            $execution = (new IdempotencyService())->execute(
                'admin',
                (string) $adminId,
                $this->resourceType . '.create',
                RequestId::fromRequestCached($request),
                IdempotencyService::fingerprint($payload),
                $this->resourceType,
                function () use ($modelClass, $payload, $request): array {
                    $model = $modelClass::create($payload);
                    $this->audit('create', (int) $model->id, $request);
                    return ['resource_id' => (int) $model->id, 'result' => ['id' => (int) $model->id]];
                },
            );
            $result = $execution['result'];
            unset($result['secret_available']);
            return $this->success($result, '保存成功');
        }
        $model = $modelClass::create($payload);
        $this->audit('create', (int) $model->id, $request);
        return $this->success(['id' => (int) $model->id], '保存成功');
    }

    public function update(Request $request): Response
    {
        $this->assertAdministrativeAccess();
        $model = $this->find($request);
        $payload = $this->payload($request, true);
        unset($payload['code']);
        $payload = $this->normalizePayload($payload, $model);
        $this->assertReferences($payload, $model);
        $this->assertPayloadAccess($payload, $model);
        $model->save($payload);
        $this->audit('update', (int) $model->id, $request);
        return $this->success('更新成功');
    }

    public function disable(Request $request): Response
    {
        $this->assertAdministrativeAccess();
        $model = $this->find($request);
        $model->save(['status' => 2]);
        $this->audit('disable', (int) $model->id, $request);
        return $this->success('已停用');
    }

    /** @return array<string, mixed> */
    protected function payload(Request $request, bool $updating): array
    {
        $posted = $request->post();
        $payload = [];
        foreach ($this->writeFields as $field) {
            if (array_key_exists($field, $posted)) {
                $payload[$field] = $posted[$field];
            }
        }
        if (!$updating) {
            foreach ($this->requiredFields as $field) {
                if (!isset($payload[$field]) || trim((string) $payload[$field]) === '') {
                    throw new ApiException(
                        'SAND_IAM_VALIDATION_ERROR: ' . $this->fieldLabel($field) . '不能为空',
                        400
                    );
                }
            }
            if (isset($payload['code']) && !$this->isValidCode((string) $payload['code'])) {
                throw new ApiException(
                    'SAND_IAM_VALIDATION_ERROR: ' . $this->codeValidationMessage(),
                    400
                );
            }
        }
        if (isset($payload['status']) && !in_array((int) $payload['status'], [1, 2], true)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 状态只能选择已启用或已停用', 400);
        }
        return $payload;
    }

    protected function fieldLabel(string $field): string
    {
        return match ($field) {
            'organization_id' => '所属客户主体',
            'application_id' => '所属接入应用',
            'environment_id' => '所属应用环境',
            'workload_client_id' => '服务调用身份',
            'service_action_id' => '服务动作',
            'identity_id' => '应用身份',
            'identity_provider_id' => '身份源',
            'resource_id' => '业务资源',
            'api_resource_id' => '接口目录记录',
            'code' => '系统代码',
            'name' => '名称',
            'display_name' => '显示名称',
            'audience' => '服务受众',
            'action' => '操作代码',
            'operation' => '数据操作类型',
            'api_version' => '接口版本',
            'http_method' => '请求方法',
            'route_template' => '路由模板',
            'risk_level' => '风险等级',
            'effect' => '授权效果',
            default => $field,
        };
    }

    protected function assertReferences(array $payload, ?object $existing = null): void {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    protected function normalizePayload(array $payload, ?object $existing = null): array
    {
        return $payload;
    }

    protected function isValidCode(string $code): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code) === 1;
    }

    protected function codeValidationMessage(): string
    {
        return '系统代码须为 2–64 位小写字母、数字、短横线或下划线，且首位为字母或数字';
    }

    protected function find(Request $request): object
    {
        $id = (int) $request->input('id', $request->post('id', 0));
        $modelClass = $this->modelClass;
        $model = $modelClass::findOrEmpty($id);
        if ($id <= 0 || $model->isEmpty()) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 未找到目标记录，可能已被删除或当前账号无权访问，请刷新列表后重试', 400);
        }
        $this->assertAdministrativeAccess();
        $this->assertModelAccess($model);
        return $model;
    }

    protected function assertModelAccess(object $model): void
    {
        $organizationId = $this->organizationIdForModel($model);
        if ($organizationId === null) {
            $this->access()->assertSuperAdmin();
        } else {
            $this->access()->assertOrganization($organizationId);
        }
    }

    protected function scopeIndexToOrganizations(object $query): void
    {
        $organizationIds = $this->access()->organizationIds();
        if ($this->access()->isSuperAdmin()) {
            return;
        }
        if ($organizationIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $this->applyOrganizationScope($query, $organizationIds);
    }

    /** @param list<int> $organizationIds */
    protected function applyOrganizationScope(object $query, array $organizationIds): void
    {
        $query->whereIn('organization_id', $organizationIds);
    }

    /** Return null for globally managed resources. */
    protected function organizationIdForModel(object $model): ?int
    {
        return isset($model->organization_id) ? (int) $model->organization_id : null;
    }

    protected function assertPayloadAccess(array $payload, ?object $existing = null): void
    {
        $organizationId = isset($payload['organization_id']) ? (int) $payload['organization_id'] : $this->organizationIdForModel($existing ?? (object) []);
        if ($organizationId === null) {
            $this->access()->assertSuperAdmin();
            return;
        }
        $this->access()->assertOrganization($organizationId);
    }

    private function assertAdministrativeAccess(): void
    {
        if ($this->requiresSuperAdmin) {
            $this->access()->assertSuperAdmin();
        }
    }

    protected function access(): AdminOrganizationAccess
    {
        $token = request()->header('check_admin', []);
        $adminId = is_array($token) ? (int) ($token['id'] ?? 0) : 0;
        return new AdminOrganizationAccess($adminId, is_array($token) ? $token : null);
    }

    protected function audit(string $verb, int $id, Request $request): void
    {
        $token = request()->header('check_admin', []);
        $adminId = is_array($token) ? (int) ($token['id'] ?? 0) : 0;
        $modelClass = $this->modelClass;
        $model = $modelClass::find($id);
        [$organizationId, $applicationId] = $model === null
            ? [null, null]
            : $this->auditScopeForModel($model);
        (new AuditWriter())->write('admin', (string) $adminId, $organizationId, $applicationId, $this->resourceType . '.' . $verb, $this->resourceType, $id, 'succeeded', RequestId::fromRequestCached($request));
    }

    /** @return array{0: ?int, 1: ?int} */
    protected function auditScopeForModel(object $model): array
    {
        $applicationId = isset($model->application_id) ? (int) $model->application_id : null;
        return [$this->organizationIdForModel($model), $applicationId];
    }
}
