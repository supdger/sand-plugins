<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupRole;
use plugin\SandIam\app\model\Role;
use plugin\SandIam\app\service\IdentityGroupRoleService;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class IdentityGroupRoleController extends BaseController
{
    #[Permission('SandIAM 用户组角色列表', 'sand_iam:identity_group_role:index')]
    public function index(Request $request): Response
    {
        $group = $this->group((int) $request->input('identity_group_id', 0), $request);
        $items = [];
        foreach (IdentityGroupRole::alias('group_role')
            ->join('sand_iam_role iam_role', 'iam_role.id = group_role.role_id')
            ->where('group_role.identity_group_id', (int) $group->id)
            ->where('group_role.application_id', (int) $group->application_id)
            ->field('group_role.id,group_role.identity_group_id,group_role.role_id,group_role.status,iam_role.code AS role_code,iam_role.name AS role_name,iam_role.status AS role_status')
            ->order('group_role.id', 'desc')
            ->select() as $item) {
            $items[] = $item->toArray();
        }
        return $this->success($items);
    }

    #[Permission('SandIAM 用户组角色列表', 'sand_iam:identity_group_role:index')]
    public function roleIndex(Request $request): Response
    {
        $role = $this->role((int) $request->input('role_id', 0), $request, false);
        $items = [];
        foreach (IdentityGroupRole::alias('group_role')
            ->join('sand_iam_identity_group iam_group', 'iam_group.id = group_role.identity_group_id')
            ->where('group_role.role_id', (int) $role->id)
            ->where('group_role.application_id', (int) $role->application_id)
            ->field('group_role.id,group_role.identity_group_id,group_role.role_id,group_role.status,iam_group.code AS identity_group_code,iam_group.name AS identity_group_name,iam_group.status AS identity_group_status')
            ->order('group_role.id', 'desc')
            ->select() as $item) {
            $items[] = $item->toArray();
        }
        return $this->success($items);
    }

    #[Permission('SandIAM 用户组角色授予', 'sand_iam:identity_group_role:grant')]
    public function grant(Request $request): Response
    {
        $group = $this->group((int) $request->post('identity_group_id', 0), $request);
        if ((int) $group->status !== 1) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 用户组不存在、已停用，或不属于当前接入应用', 400);
        }
        $role = $this->role((int) $request->post('role_id', 0), $request, true);
        if ((int) $role->application_id !== (int) $group->application_id) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所选角色与用户组不属于同一接入应用', 400);
        }

        $id = (new IdentityGroupRoleService())->grant((int) $group->id, (int) $role->id, (int) $group->application_id, $this->actor($request), $this->requestId($request));
        return $this->success(['id' => $id], '已为用户组授予角色');
    }

    #[Permission('SandIAM 用户组角色撤销', 'sand_iam:identity_group_role:revoke')]
    public function revoke(Request $request): Response
    {
        $binding = IdentityGroupRole::findOrEmpty((int) $request->post('id', 0));
        if ($binding->isEmpty()) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 未找到这条用户组角色关系，请刷新列表后重试', 400);
        }
        $group = $this->group((int) $binding->identity_group_id, $request);
        if ((int) $binding->application_id !== (int) $group->application_id) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 用户组角色关系的应用边界不一致', 400);
        (new IdentityGroupRoleService())->revoke((int) $binding->id, (int) $group->application_id, $this->actor($request), $this->requestId($request));
        return $this->success('已撤销用户组角色');
    }

    private function group(int $groupId, Request $request): IdentityGroup
    {
        $group = IdentityGroup::find($groupId);
        if ($group === null) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 用户组不存在', 400);
        }
        $this->access($request)->assertApplication((int) $group->application_id);
        return $group;
    }

    private function role(int $roleId, Request $request, bool $enabled): Role
    {
        $query = Role::where('id', $roleId);
        if ($enabled) {
            $query->where('status', 1);
        }
        $role = $query->find();
        if ($role === null) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所选角色不存在或已停用', 400);
        }
        $this->access($request)->assertApplication((int) $role->application_id);
        return $role;
    }

    private function access(Request $request): AdminOrganizationAccess
    {
        $token = $request->header('check_admin', []);
        return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null);
    }

    private function actor(Request $request): string { $token = $request->header('check_admin', []); return (string) (is_array($token) ? ($token['id'] ?? 0) : 0); }
    private function requestId(Request $request): string { return substr((string) $request->header('X-Request-Id', ''), 0, 96) ?: bin2hex(random_bytes(16)); }
}
