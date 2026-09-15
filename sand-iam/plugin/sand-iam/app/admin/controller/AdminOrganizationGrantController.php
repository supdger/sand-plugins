<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminResourceController;
use plugin\SandIam\app\model\AdminOrganizationGrant;
use plugin\SandIam\app\model\Organization;
use plugin\sandadmin\app\model\system\SystemUser;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class AdminOrganizationGrantController extends AdminResourceController
{
    protected string $modelClass = AdminOrganizationGrant::class;
    protected array $writeFields = ['admin_user_id', 'organization_id', 'status'];
    protected array $requiredFields = ['admin_user_id', 'organization_id'];
    protected string $resourceType = 'admin_organization_grant';
    protected bool $requiresSuperAdmin = true;
    protected bool $atomicCreateAudit = true;
    protected bool $atomicMutationAudit = true;
    #[Permission('SandIAM 客户主体管理委派保存', 'sand_iam:admin_organization_grant:save')]
    public function adminOptions(Request $request): Response
    {
        $this->access()->assertSuperAdmin();
        $id = (int) $request->input('id', 0);
        $keyword = trim((string) $request->input('keywords', ''));
        if ($id <= 0 && mb_strlen($keyword) < 2) return $this->success([]);
        $query = SystemUser::where('status', 1)->field(['id', 'username', 'realname']);
        if ($id > 0) {
            $query->where('id', $id);
        } else {
            $keyword = mb_substr($keyword, 0, 64);
            $query->where(static function ($scope) use ($keyword): void {
                $scope->whereLike('username', '%' . $keyword . '%')->whereOr('realname', 'like', '%' . $keyword . '%');
            });
        }
        $rows = array_map(static function (SystemUser $user): array {
            $name = trim((string) ($user->realname ?: $user->username));
            return ['id' => (int) $user->id, 'name' => $name !== '' ? $name : '未命名后台管理员', 'username' => (string) $user->username];
        }, $query->order('id')->limit(20)->select()->all());
        return $this->success($rows);
    }
    #[Permission('SandIAM 客户主体管理委派列表', 'sand_iam:admin_organization_grant:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 客户主体管理委派读取', 'sand_iam:admin_organization_grant:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 客户主体管理委派保存', 'sand_iam:admin_organization_grant:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 客户主体管理委派更新', 'sand_iam:admin_organization_grant:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 客户主体管理委派停用', 'sand_iam:admin_organization_grant:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        if (isset($payload['admin_user_id']) && ((int) $payload['admin_user_id'] <= 0 || SystemUser::where('id', (int) $payload['admin_user_id'])->where('status', 1)->find() === null)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 请选择有效的后台管理员账号', 400);
        }
        if (isset($payload['organization_id']) && !Organization::where('id', (int) $payload['organization_id'])->where('status', 1)->find()) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所选客户主体不存在或已停用，请刷新后重新选择', 400);
        }
    }
}
