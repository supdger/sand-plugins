<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityGroup;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class IdentityGroupService
{
    public function __construct(private readonly AuditWriter $audit = new AuditWriter())
    {
    }

    public function create(int $applicationId, string $code, string $name, ?int $parentId, string $description, string $actor, string $requestId): int
    {
        $application = $this->application($applicationId);
        $this->code($code); $this->name($name);
        Db::startTrans();
        try {
            $depth = $parentId === null ? 1 : $this->parent($parentId, $applicationId)->depth + 1;
            if ($depth > 8) throw new ApiException('SAND_IAM_IDENTITY_GROUP_DEPTH_EXCEEDED', 409);
            $group = IdentityGroup::create(['application_id' => $applicationId, 'parent_id' => $parentId, 'code' => $code, 'name' => $name, 'description' => mb_substr(trim($description), 0, 500), 'depth' => $depth, 'status' => 1]);
            $this->writeAudit($application, 'identity_group.create', (int) $group->id, $actor, $requestId);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_IDENTITY_GROUP_CONFLICT', 409);
            throw $exception;
        }
        return (int) $group->id;
    }

    public function update(int $id, int $applicationId, string $name, ?int $parentId, string $description, int $status, string $actor, string $requestId): void
    {
        $application = $this->application($applicationId); $this->name($name);
        if (!in_array($status, [1, 2], true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 用户组状态无效', 400);
        Db::startTrans();
        try {
            $group = $this->group($id, $applicationId, true);
            if ($parentId === $id) throw new ApiException('SAND_IAM_IDENTITY_GROUP_CYCLE', 409);
            $depth = 1;
            if ($parentId !== null) {
                $parent = $this->parent($parentId, $applicationId);
                $this->assertNotDescendant($group, $parent);
                $depth = (int) $parent->depth + 1;
            }
            if ($depth > 8) throw new ApiException('SAND_IAM_IDENTITY_GROUP_DEPTH_EXCEEDED', 409);
            if ((int) $group->parent_id !== (int) ($parentId ?? 0) && IdentityGroup::where('parent_id', $id)->where('application_id', $applicationId)->where('status', 1)->count() > 0) throw new ApiException('SAND_IAM_IDENTITY_GROUP_HAS_CHILDREN', 409);
            if ($status === 2) $this->assertEmpty($id, $applicationId);
            $group->save(['name' => $name, 'parent_id' => $parentId, 'description' => mb_substr(trim($description), 0, 500), 'depth' => $depth, 'status' => $status]);
            $this->writeAudit($application, 'identity_group.update', $id, $actor, $requestId);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
    }

    public function addMember(int $groupId, int $identityId, int $applicationId, string $actor, string $requestId): int
    {
        $application = $this->application($applicationId);
        $changed = false;
        Db::startTrans();
        try {
            $group = $this->group($groupId, $applicationId, true);
            if ((int) $group->status !== 1) throw new ApiException('SAND_IAM_IDENTITY_GROUP_DISABLED', 409);
            $identity = Identity::where('id', $identityId)->where('application_id', $applicationId)->where('status', 1)->lock(true)->find();
            if ($identity === null || (string) ($identity->lifecycle_state ?? 'active') === 'deleted') throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 应用用户不存在或不可加入用户组', 404);
            $member = IdentityGroupMember::where('identity_group_id', $groupId)->where('identity_id', $identityId)->lock(true)->find();
            if ($member === null) { $member = IdentityGroupMember::create(['identity_group_id' => $groupId, 'application_id' => $applicationId, 'identity_id' => $identityId, 'status' => 1]); $changed = true; }
            elseif ((int) $member->status !== 1) { $member->save(['status' => 1]); $changed = true; }
            if ($changed) (new IdentityEventPublisher())->publish($application, $identity, 'identity.updated', ['groups'], $requestId);
            $this->writeAudit($application, 'identity_group.member_add', $groupId, $actor, $requestId, ['identity_id' => $identityId]);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_IDENTITY_GROUP_MEMBER_CONFLICT', 409); throw $exception; }
        return (int) $member->id;
    }

    public function removeMember(int $groupId, int $identityId, int $applicationId, string $actor, string $requestId): void
    {
        $application = $this->application($applicationId);
        Db::startTrans();
        try {
            $member = IdentityGroupMember::where('identity_group_id', $groupId)->where('identity_id', $identityId)->where('application_id', $applicationId)->where('status', 1)->lock(true)->find();
            $identity = Identity::where('id', $identityId)->where('application_id', $applicationId)->lock(true)->find();
            if ($member === null || $identity === null) throw new ApiException('SAND_IAM_IDENTITY_GROUP_MEMBER_NOT_FOUND', 404);
            $member->save(['status' => 2]);
            (new IdentityEventPublisher())->publish($application, $identity, 'identity.updated', ['groups'], $requestId);
            $this->writeAudit($application, 'identity_group.member_remove', $groupId, $actor, $requestId, ['identity_id' => $identityId]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    private function assertNotDescendant(IdentityGroup $group, IdentityGroup $candidate): void
    {
        $seen = [];
        while (true) {
            if ((int) $candidate->id === (int) $group->id) throw new ApiException('SAND_IAM_IDENTITY_GROUP_CYCLE', 409);
            if (isset($seen[(int) $candidate->id])) throw new ApiException('SAND_IAM_IDENTITY_GROUP_CYCLE', 409);
            $seen[(int) $candidate->id] = true;
            if ($candidate->parent_id === null) return;
            $next = IdentityGroup::where('id', (int) $candidate->parent_id)->where('application_id', (int) $group->application_id)->lock(true)->find();
            if ($next === null) throw new ApiException('SAND_IAM_IDENTITY_GROUP_PARENT_INVALID', 409);
            $candidate = $next;
        }
    }
    private function assertEmpty(int $id, int $applicationId): void
    {
        if (IdentityGroup::where('parent_id', $id)->where('application_id', $applicationId)->where('status', 1)->count() > 0) throw new ApiException('SAND_IAM_IDENTITY_GROUP_HAS_CHILDREN', 409);
        if (IdentityGroupMember::where('identity_group_id', $id)->where('application_id', $applicationId)->where('status', 1)->count() > 0) throw new ApiException('SAND_IAM_IDENTITY_GROUP_HAS_MEMBERS', 409);
    }
    private function group(int $id, int $applicationId, bool $lock = false): IdentityGroup { $query = IdentityGroup::where('id', $id)->where('application_id', $applicationId); if ($lock) $query->lock(true); $group = $query->find(); if ($group === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 用户组不存在', 404); return $group; }
    private function parent(int $id, int $applicationId): IdentityGroup { $group = $this->group($id, $applicationId, true); if ((int) $group->status !== 1) throw new ApiException('SAND_IAM_IDENTITY_GROUP_PARENT_INVALID', 409); return $group; }
    private function application(int $id): Application { $application = Application::where('id', $id)->where('status', 1)->find(); if ($application === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 404); return $application; }
    private function code(string $value): void { if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $value)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 用户组系统代码格式无效', 400); }
    private function name(string $value): void { if (trim($value) === '' || mb_strlen($value) > 128) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 用户组名称须为 1–128 个字符', 400); }
    /** @param array<string,mixed> $context */ private function writeAudit(Application $application, string $action, int $id, string $actor, string $requestId, array $context = []): void { $this->audit->write('admin', $actor, (int) $application->organization_id, (int) $application->id, $action, 'identity_group', $id, 'succeeded', $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16)), $context); }
}
