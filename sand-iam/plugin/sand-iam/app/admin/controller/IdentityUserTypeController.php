<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityUserType;
use plugin\SandIam\app\model\UserType;
use plugin\SandIam\app\service\AuditWriter;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;
use think\facade\Db;

final class IdentityUserTypeController extends BaseController
{
    #[Permission('SandIAM 身份用户类型列表', 'sand_iam:identity_user_type:index')]
    public function index(Request $request): Response
    {
        $identity = $this->identity((int) $request->input('identity_id', 0));
        return $this->success(IdentityUserType::where('identity_id', $identity->id)->order('id', 'desc')->select()->toArray());
    }

    #[Permission('SandIAM 身份用户类型授予', 'sand_iam:identity_user_type:grant')]
    public function grant(Request $request): Response
    {
        $identity = $this->identity((int) $request->post('identity_id', 0));
        $userType = UserType::where('id', (int) $request->post('user_type_id', 0))->where('application_id', $identity->application_id)->where('status', 1)->find();
        if (!$userType) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所选用户类型不存在、已停用，或不属于该身份所在的接入应用', 400); }
        Db::startTrans();
        try {
            $binding = IdentityUserType::where('identity_id', $identity->id)->where('user_type_id', $userType->id)->find();
            if ($binding) { $binding->save(['status' => 1]); } else { $binding = IdentityUserType::create(['identity_id' => $identity->id, 'user_type_id' => $userType->id, 'status' => 1]); }
            $this->audit('identity_user_type.grant', (int) $binding->id, $identity, $request);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return $this->success(['id' => (int) $binding->id], '已授予');
    }

    #[Permission('SandIAM 身份用户类型撤销', 'sand_iam:identity_user_type:revoke')]
    public function revoke(Request $request): Response
    {
        $binding = IdentityUserType::findOrEmpty((int) $request->post('id', 0));
        if ($binding->isEmpty()) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 未找到这条身份用户类型关系，请刷新列表后重试', 400); }
        $identity = $this->identity((int) $binding->identity_id);
        Db::startTrans();
        try {
            $binding->save(['status' => 2]);
            $this->audit('identity_user_type.revoke', (int) $binding->id, $identity, $request);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return $this->success('已撤销');
    }

    private function identity(int $identityId): Identity
    {
        $identity = Identity::where('id', $identityId)->where('status', 1)->find();
        $application = $identity ? Application::find($identity->application_id) : null;
        if (!$identity || !$application) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所选应用用户身份不存在、已停用，或所属接入应用不可用', 400); }
        $this->access()->assertApplication((int) $identity->application_id);
        return $identity;
    }

    private function access(): AdminOrganizationAccess { return new AdminOrganizationAccess($this->adminId ?? 0, is_array($this->adminInfo ?? null) ? $this->adminInfo : null); }
    private function audit(string $action, int $resourceId, Identity $identity, Request $request): void { $application = Application::find($identity->application_id); (new AuditWriter())->write('admin', (string) ($this->adminId ?? 0), $application ? (int) $application->organization_id : null, (int) $identity->application_id, $action, 'identity_user_type', $resourceId, 'succeeded', (string) $request->header('X-Request-Id', bin2hex(random_bytes(12)))); }
}
