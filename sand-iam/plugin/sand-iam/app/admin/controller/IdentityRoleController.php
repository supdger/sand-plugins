<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityRole;
use plugin\SandIam\app\model\Role;
use plugin\SandIam\app\service\AuditWriter;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class IdentityRoleController extends BaseController
{
    #[Permission('SandIAM 身份角色列表', 'sand_iam:identity_role:index')]
    public function index(Request $request): Response
    {
        $identity = $this->identity((int) $request->input('identity_id', 0));
        return $this->success(IdentityRole::where('identity_id', $identity->id)->order('id', 'desc')->select()->toArray());
    }

    #[Permission('SandIAM 身份角色授予', 'sand_iam:identity_role:grant')]
    public function grant(Request $request): Response
    {
        $identity = $this->identity((int) $request->post('identity_id', 0));
        $role = Role::where('id', (int) $request->post('role_id', 0))->where('application_id', $identity->application_id)->where('status', 1)->find();
        if (!$role) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: role', 400); }
        $binding = IdentityRole::where('identity_id', $identity->id)->where('role_id', $role->id)->find();
        if ($binding) { $binding->save(['status' => 1]); } else { $binding = IdentityRole::create(['identity_id' => $identity->id, 'role_id' => $role->id, 'status' => 1]); }
        $this->audit('identity_role.grant', (int) $binding->id, $identity, $request);
        return $this->success(['id' => (int) $binding->id], '已授予');
    }

    #[Permission('SandIAM 身份角色撤销', 'sand_iam:identity_role:revoke')]
    public function revoke(Request $request): Response
    {
        $binding = IdentityRole::findOrEmpty((int) $request->post('id', 0));
        if ($binding->isEmpty()) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: identity role', 400); }
        $identity = $this->identity((int) $binding->identity_id);
        $binding->save(['status' => 2]);
        $this->audit('identity_role.revoke', (int) $binding->id, $identity, $request);
        return $this->success('已撤销');
    }

    private function identity(int $identityId): Identity
    {
        $identity = Identity::where('id', $identityId)->where('status', 1)->find();
        $application = $identity ? Application::find($identity->application_id) : null;
        if (!$identity || !$application) { throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: identity', 400); }
        (new AdminOrganizationAccess($this->adminId ?? 0, is_array($this->adminInfo ?? null) ? $this->adminInfo : null))->assertOrganization((int) $application->organization_id);
        return $identity;
    }

    private function audit(string $action, int $resourceId, Identity $identity, Request $request): void
    {
        $application = Application::find($identity->application_id);
        (new AuditWriter())->write('admin', (string) ($this->adminId ?? 0), $application ? (int) $application->organization_id : null, (int) $identity->application_id, $action, 'identity_role', $resourceId, 'succeeded', (string) $request->header('X-Request-Id', bin2hex(random_bytes(12))));
    }
}
