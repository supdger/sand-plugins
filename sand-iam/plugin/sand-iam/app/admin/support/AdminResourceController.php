<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\support;

use plugin\SandIam\app\service\AuditWriter;
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
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
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
        $this->assertReferences($payload);
        $this->assertPayloadAccess($payload);
        $modelClass = $this->modelClass;
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
                    throw new ApiException('SAND_IAM_VALIDATION_ERROR: ' . $field . ' is required', 400);
                }
            }
            if (isset($payload['code']) && !preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', (string) $payload['code'])) {
                throw new ApiException('SAND_IAM_VALIDATION_ERROR: invalid code', 400);
            }
        }
        if (isset($payload['status']) && !in_array((int) $payload['status'], [1, 2], true)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: invalid status', 400);
        }
        return $payload;
    }

    protected function assertReferences(array $payload, ?object $existing = null): void {}

    protected function find(Request $request): object
    {
        $id = (int) $request->input('id', $request->post('id', 0));
        $modelClass = $this->modelClass;
        $model = $modelClass::findOrEmpty($id);
        if ($id <= 0 || $model->isEmpty()) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND', 400);
        }
        $this->assertAdministrativeAccess();
        $organizationId = $this->organizationIdForModel($model);
        if ($organizationId === null) {
            $this->access()->assertSuperAdmin();
        } else {
            $this->access()->assertOrganization($organizationId);
        }
        return $model;
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
        return new AdminOrganizationAccess($this->adminId ?? 0, is_array($this->adminInfo ?? null) ? $this->adminInfo : null);
    }

    protected function audit(string $verb, int $id, Request $request): void
    {
        (new AuditWriter())->write('admin', (string) ($this->adminId ?? 0), null, null, $this->resourceType . '.' . $verb, $this->resourceType, $id, 'succeeded', (string) $request->header('X-Request-Id', bin2hex(random_bytes(12))));
    }
}
