<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupRole;
use plugin\SandIam\app\model\Role;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class IdentityGroupRoleService
{
    /** The optional callback exists only for bounded transaction-failure tests. */
    public function __construct(private readonly ?\Closure $auditOverride = null)
    {
    }

    public function grant(int $groupId, int $roleId, int $applicationId, string $actor, string $requestId): int
    {
        Db::startTrans();
        try {
            $group = IdentityGroup::where('id', $groupId)->where('application_id', $applicationId)->where('status', 1)->lock(true)->find();
            $role = Role::where('id', $roleId)->where('application_id', $applicationId)->where('status', 1)->lock(true)->find();
            if ($group === null || $role === null) {
                throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 用户组或角色不存在、已停用，或不属于当前接入应用', 400);
            }
            $binding = IdentityGroupRole::where('identity_group_id', $groupId)->where('role_id', $roleId)->where('application_id', $applicationId)->lock(true)->find();
            if ($binding === null) {
                $binding = IdentityGroupRole::create(['identity_group_id' => $groupId, 'role_id' => $roleId, 'application_id' => $applicationId, 'status' => 1]);
            } elseif ((int) $binding->status !== 1) {
                $binding->save(['status' => 1]);
            }
            $this->writeAudit('identity_group_role.grant', (int) $binding->id, $group, $roleId, $actor, $requestId);
            Db::commit();
            return (int) $binding->id;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    public function revoke(int $bindingId, int $applicationId, string $actor, string $requestId): void
    {
        Db::startTrans();
        try {
            $binding = IdentityGroupRole::where('id', $bindingId)->where('application_id', $applicationId)->find();
            if ($binding === null) {
                throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 未找到这条用户组角色关系，请刷新列表后重试', 400);
            }
            $groupId = (int) $binding->identity_group_id;
            $roleId = (int) $binding->role_id;
            // Match grant's group-first order; re-read the binding under that lock.
            $group = IdentityGroup::where('id', $groupId)->where('application_id', $applicationId)->lock(true)->find();
            if ($group === null) {
                throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 用户组角色关系的应用边界不一致', 400);
            }
            $binding = IdentityGroupRole::where('id', $bindingId)->where('application_id', $applicationId)
                ->where('identity_group_id', $groupId)->where('role_id', $roleId)->lock(true)->find();
            if ($binding === null) {
                throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 用户组角色关系已改变，请刷新列表后重试', 400);
            }
            if ((int) $binding->status !== 2) {
                $binding->save(['status' => 2]);
            }
            $this->writeAudit('identity_group_role.revoke', (int) $binding->id, $group, (int) $binding->role_id, $actor, $requestId);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    private function writeAudit(string $action, int $resourceId, IdentityGroup $group, int $roleId, string $actor, string $requestId): void
    {
        $application = Application::where('id', (int) $group->application_id)->find();
        if ($application === null) {
            throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 用户组所属接入应用不存在', 400);
        }
        $arguments = ['admin', $actor, (int) $application->organization_id, (int) $application->id, $action, 'identity_group_role', $resourceId, 'succeeded', $requestId, ['identity_group_id' => (int) $group->id, 'role_id' => $roleId, 'decision_source' => 'identity_group_role']];
        if ($this->auditOverride !== null) {
            ($this->auditOverride)(...$arguments);
            return;
        }
        (new AuditWriter())->write(...$arguments);
    }
}
