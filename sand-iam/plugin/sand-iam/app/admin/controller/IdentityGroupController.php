<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\SandIam\app\service\IdentityGroupService;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class IdentityGroupController extends BaseController
{
    #[Permission('SandIAM 用户组列表', 'sand_iam:identity_group:index')]
    public function index(Request $request): Response
    {
        $this->enabled(); $applicationId = (int) $request->input('application_id', 0); $this->access($request)->assertApplication($applicationId);
        $query = IdentityGroup::where('application_id', $applicationId)->order('depth', 'asc')->order('name', 'asc');
        $status = (int) $request->input('status', 0); if (in_array($status, [1, 2], true)) $query->where('status', $status);
        $keyword = trim((string) $request->input('keywords', '')); if ($keyword !== '') $query->whereLike('name', '%' . $keyword . '%');
        $items = $query->select(); $result = [];
        foreach ($items as $group) $result[] = $this->payload($group);
        return $this->success($result);
    }
    #[Permission('SandIAM 用户组读取', 'sand_iam:identity_group:read')]
    public function read(Request $request): Response { $group = $this->group($request); return $this->success($this->payload($group)); }
    #[Permission('SandIAM 用户组保存', 'sand_iam:identity_group:save')]
    public function save(Request $request): Response
    {
        $this->enabled(); $applicationId = (int) $request->post('application_id', 0); $this->access($request)->assertApplication($applicationId);
        $id = (new IdentityGroupService())->create($applicationId, trim((string) $request->post('code', '')), trim((string) $request->post('name', '')), $this->nullableId($request->post('parent_id')), (string) $request->post('description', ''), $this->actor($request), $this->requestId($request));
        return $this->success(['id' => $id], '用户组已创建');
    }
    #[Permission('SandIAM 用户组更新', 'sand_iam:identity_group:update')]
    public function update(Request $request): Response
    {
        $group = $this->group($request);
        (new IdentityGroupService())->update((int) $group->id, (int) $group->application_id, trim((string) $request->post('name', (string) $group->name)), $this->nullableId($request->post('parent_id', $group->parent_id)), (string) $request->post('description', (string) ($group->description ?? '')), (int) $request->post('status', (int) $group->status), $this->actor($request), $this->requestId($request));
        return $this->success('用户组已更新');
    }
    #[Permission('SandIAM 用户组停用', 'sand_iam:identity_group:disable')]
    public function disable(Request $request): Response
    {
        $group = $this->group($request); (new IdentityGroupService())->update((int) $group->id, (int) $group->application_id, (string) $group->name, $group->parent_id === null ? null : (int) $group->parent_id, (string) ($group->description ?? ''), 2, $this->actor($request), $this->requestId($request));
        return $this->success('用户组已停用');
    }
    #[Permission('SandIAM 用户组成员加入', 'sand_iam:identity_group_member:add')]
    public function addMember(Request $request): Response
    {
        $group = $this->group($request); $identityId = (int) $request->post('identity_id', 0);
        $id = (new IdentityGroupService())->addMember((int) $group->id, $identityId, (int) $group->application_id, $this->actor($request), $this->requestId($request));
        return $this->success(['id' => $id], '用户已加入用户组');
    }
    #[Permission('SandIAM 用户组成员移除', 'sand_iam:identity_group_member:remove')]
    public function removeMember(Request $request): Response
    {
        $group = $this->group($request); (new IdentityGroupService())->removeMember((int) $group->id, (int) $request->post('identity_id', 0), (int) $group->application_id, $this->actor($request), $this->requestId($request));
        return $this->success('用户已移出用户组');
    }
    #[Permission('SandIAM 用户组成员列表', 'sand_iam:identity_group_member:index')]
    public function members(Request $request): Response
    {
        $group = $this->group($request); $result = [];
        foreach (IdentityGroupMember::where('identity_group_id', (int) $group->id)->where('application_id', (int) $group->application_id)->where('status', 1)->select() as $member) {
            $identity = Identity::find((int) $member->identity_id);
            if ($identity !== null) $result[] = ['identity_id' => (int) $identity->id, 'display_name' => (string) $identity->display_name, 'code' => (string) $identity->code, 'lifecycle_state' => (string) ($identity->lifecycle_state ?? ((int) $identity->status === 1 ? 'active' : 'disabled'))];
        }
        return $this->success($result);
    }
    private function group(Request $request): IdentityGroup { $this->enabled(); $group = IdentityGroup::find((int) $request->input('id', $request->post('id', 0))); if ($group === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 用户组不存在', 404); $this->access($request)->assertApplication((int) $group->application_id); return $group; }
    /** @return array<string,mixed> */ private function payload(IdentityGroup $group): array { $parent = $group->parent_id === null ? null : IdentityGroup::find((int) $group->parent_id); return ['id' => (int) $group->id, 'application_id' => (int) $group->application_id, 'code' => (string) $group->code, 'name' => (string) $group->name, 'parent_id' => $group->parent_id === null ? null : (int) $group->parent_id, 'parent_name' => $parent ? (string) $parent->name : '', 'description' => (string) ($group->description ?? ''), 'depth' => (int) $group->depth, 'member_count' => IdentityGroupMember::where('identity_group_id', (int) $group->id)->where('status', 1)->count(), 'status' => (int) $group->status]; }
    private function nullableId(mixed $value): ?int { $id = (int) $value; return $id > 0 ? $id : null; }
    private function enabled(): void { if ((int) config('plugin.sand-iam.app.identity_lifecycle_enabled', 0) !== 1) throw new ApiException('SAND_IAM_IDENTITY_LIFECYCLE_UNAVAILABLE', 503); }
    private function access(Request $request): AdminOrganizationAccess { $token = $request->header('check_admin', []); return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null); }
    private function actor(Request $request): string { $token = $request->header('check_admin', []); return (string) (is_array($token) ? ($token['id'] ?? 0) : 0); }
    private function requestId(Request $request): string { return substr((string) $request->header('X-Request-Id', ''), 0, 96); }
}
