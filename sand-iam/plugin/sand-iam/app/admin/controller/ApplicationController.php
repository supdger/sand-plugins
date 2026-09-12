<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Organization;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ApplicationController extends AdminResourceController
{
    protected string $modelClass = Application::class;
    protected array $writeFields = ['organization_id', 'code', 'name', 'status'];
    protected array $requiredFields = ['organization_id', 'code', 'name'];
    protected string $resourceType = 'application';
    #[Permission('SandIAM 接入应用列表', 'sand_iam:application:index')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->input('page', 1));
        $limit = min(100, max(1, (int) $request->input('limit', 20)));
        $query = Application::alias('application')
            ->leftJoin('sand_iam_organization organization', 'organization.id = application.organization_id')
            ->field('application.*, organization.name AS organization_name')
            ->order('application.id', 'desc');
        $this->scopeIndexToOrganizations($query);
        foreach (['organization_id', 'status'] as $field) {
            $value = $request->input($field, '');
            if ($value !== '') {
                $query->where('application.' . $field, (int) $value);
            }
        }
        $keyword = trim((string) $request->input('keywords', ''));
        if ($keyword !== '') {
            $query->whereLike('application.name', '%' . $keyword . '%');
        }
        return $this->success($this->withOrganizationContext(
            $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray(),
        ));
    }

    #[Permission('SandIAM 接入应用读取', 'sand_iam:application:read')]
    public function read(Request $request): Response
    {
        $model = $this->find($request);
        $application = Application::alias('application')
            ->leftJoin('sand_iam_organization organization', 'organization.id = application.organization_id')
            ->field('application.*, organization.name AS organization_name')
            ->where('application.id', (int) $model->id)
            ->find();
        return $this->success($this->withOrganizationContext($application?->toArray() ?? []));
    }
    #[Permission('SandIAM 接入应用保存', 'sand_iam:application:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 接入应用更新', 'sand_iam:application:update')]
    public function update(Request $request): Response
    {
        $id = (int) $request->input('id', $request->post('id', 0));
        $model = Application::findOrEmpty($id);
        if ($id <= 0 || $model->isEmpty()) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 未找到目标记录，可能已被删除或当前账号无权访问，请刷新列表后重试', 400);
        }

        $payload = $this->payload($request, true);
        unset($payload['code']);
        $recovering = (int) $model->status === 2 && array_key_exists('status', $payload) && (int) $payload['status'] === 1;
        if ((int) $model->status === 2 && array_key_exists('status', $payload) && !$recovering) {
            throw new ApiException('SAND_IAM_APPLICATION_RECOVERY_STATUS_INVALID: 停用接入应用只能恢复为已启用状态', 400);
        }

        $this->assertUpdatePayloadAccess($payload, $model, $recovering);
        $payload = $this->normalizePayload($payload, $model);
        $this->assertReferences($payload, $model);
        // Keep the post-normalization check on the same recovery path. A
        // recovery must never fall back to the ordinary active-app guard.
        $this->assertUpdatePayloadAccess($payload, $model, $recovering);
        $model->save($payload);
        $this->audit('update', (int) $model->id, $request);
        return $this->success('更新成功');
    }
    #[Permission('SandIAM 接入应用停用', 'sand_iam:application:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['organization_id']) && !Organization::where('id', (int) $payload['organization_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属客户主体不存在或已停用', 400); }

    protected function scopeIndexToOrganizations(object $query): void
    {
        if ($this->access()->isSuperAdmin()) return;
        $applicationIds = $this->access()->applicationIds();
        if ($applicationIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn('application.id', $applicationIds);
    }

    protected function assertModelAccess(object $model): void
    {
        $this->access()->assertApplication((int) $model->id);
    }

    protected function assertPayloadAccess(array $payload, ?object $existing = null): void
    {
        if ($existing === null) {
            $this->access()->assertOrganization((int) ($payload['organization_id'] ?? 0));
            return;
        }

        $this->access()->assertApplication((int) $existing->id);
        $organizationId = (int) ($payload['organization_id'] ?? $existing->organization_id);
        if ($organizationId !== (int) $existing->organization_id) {
            $this->access()->assertOrganization((int) $existing->organization_id);
            $this->access()->assertOrganization($organizationId);
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertUpdatePayloadAccess(array $payload, object $model, bool $recovering): void
    {
        if (!$recovering) {
            $this->assertPayloadAccess($payload, $model);
            return;
        }
        if (array_key_exists('organization_id', $payload)) {
            throw new ApiException('SAND_IAM_APPLICATION_RECOVERY_OWNERSHIP_IMMUTABLE: 恢复停用接入应用时不得变更所属客户主体', 400);
        }
        $this->access()->assertApplicationRecovery((int) $model->id);
    }

    /**
     * Application-level delegates receive the parent organization as display
     * context only. They cannot change it without an organization grant.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function withOrganizationContext(array $payload): array
    {
        $access = $this->access();
        $organizationIds = $access->organizationIds();
        $isEditable = static function (array $row) use ($access, $organizationIds): bool {
            return $access->isSuperAdmin()
                || in_array((int) ($row['organization_id'] ?? 0), $organizationIds, true);
        };
        if (isset($payload['data']) && is_array($payload['data'])) {
            foreach ($payload['data'] as $index => $row) {
                if (is_array($row)) {
                    $row['organization_editable'] = $isEditable($row);
                    $payload['data'][$index] = $row;
                }
            }
            return $payload;
        }
        $payload['organization_editable'] = $isEditable($payload);
        return $payload;
    }
}
