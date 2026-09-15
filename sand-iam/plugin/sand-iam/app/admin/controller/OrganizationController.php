<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\service\OrganizationHumanSessionRevoker;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;
use think\facade\Db;

final class OrganizationController extends AdminResourceController
{
    protected string $modelClass = Organization::class;
    protected array $writeFields = ['code', 'name', 'status'];
    protected string $resourceType = 'organization';
    protected bool $atomicCreateAudit = true;
    protected function applyOrganizationScope(object $query, array $organizationIds): void { $query->whereIn('id', $organizationIds); }
    #[Permission('SandIAM 客户主体列表', 'sand_iam:organization:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 客户主体读取', 'sand_iam:organization:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 客户主体保存', 'sand_iam:organization:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 客户主体更新', 'sand_iam:organization:update')]
    public function update(Request $request): Response
    {
        $model = $this->find($request);
        $payload = $this->payload($request, true);
        unset($payload['code']);
        $payload = $this->normalizePayload($payload, $model);
        $this->assertReferences($payload, $model);
        $this->assertPayloadAccess($payload, $model);
        if ((int) $model->status === 1 && array_key_exists('status', $payload) && (int) $payload['status'] === 2) {
            (new OrganizationHumanSessionRevoker())->disable((int) $model->id, $payload, $this->adminId($request), RequestId::fromRequestCached($request));
            return $this->success('客户主体已停用，全部用户会话已撤销');
        }
        Db::startTrans();
        try {
            $model->save($payload);
            $this->audit('update', (int) $model->id, $request);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return $this->success('更新成功');
    }

    #[Permission('SandIAM 客户主体停用', 'sand_iam:organization:disable')]
    public function disable(Request $request): Response
    {
        $model = $this->find($request);
        if ((int) $model->status === 1) {
            (new OrganizationHumanSessionRevoker())->disable((int) $model->id, ['status' => 2], $this->adminId($request), RequestId::fromRequestCached($request));
            return $this->success('客户主体已停用，全部用户会话已撤销');
        }
        $this->audit('disable', (int) $model->id, $request);
        return $this->success('已停用');
    }

    protected function normalizePayload(array $payload, ?object $existing = null): array
    {
        if (array_key_exists('name', $payload)) {
            if (!is_string($payload['name']) || trim($payload['name']) === '') {
                throw new ApiException('SAND_IAM_VALIDATION_ERROR: 客户主体名称必须是非空文本', 400);
            }
            $payload['name'] = trim($payload['name']);
        }
        return $payload;
    }

    private function adminId(Request $request): int
    {
        $token = $request->header('check_admin', []);
        return is_array($token) ? (int) ($token['id'] ?? 0) : 0;
    }
}
