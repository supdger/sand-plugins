<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\service;

use DomainException;
use plugin\sandadmin\app\model\system\SystemDept;
use plugin\sandadmin\app\model\system\SystemUser;
use plugin\sandworkflow\app\contract\ActorRef;
use plugin\sandworkflow\app\contract\OrganizationParticipantProvider;
use plugin\sandworkflow\app\contract\OrganizationResolutionRequest;
use plugin\sandworkflow\common\FlowConstant;

/**
 * Demo / SaiAdmin 宿主：按部门树解析部门负责人 / 连续多级部门负责人。
 */
class SandAdminDepartmentLeaderProvider implements OrganizationParticipantProvider
{
    public function supports(string $accountSpace): bool
    {
        return $accountSpace === 'sandadmin';
    }

    public function resolve(OrganizationResolutionRequest $request): array
    {
        if ($request->initiator->accountSpace !== 'sandadmin'
            || $request->tenantId !== 'sandadmin'
            || $request->ownerScope !== 'sandadmin') {
            throw new DomainException('SaiAdmin组织关系作用域无效');
        }
        if (!ctype_digit($request->initiator->accountId)
            || (int) $request->initiator->accountId <= 0) {
            throw new DomainException('SaiAdmin发起人账户标识无效');
        }
        if (!in_array($request->ruleType, [
            FlowConstant::ASSIGNEE['DEPARTMENT_LEADER'],
            FlowConstant::ASSIGNEE['MULTISTEP_DEPARTMENT_LEADER'],
        ], true)) {
            throw new DomainException('SaiAdmin当前没有可信的用户直属上级数据源');
        }

        $initiator = $this->findUser((int) $request->initiator->accountId);
        if ($initiator === null || (int) ($initiator['status'] ?? 0) !== 1) {
            throw new DomainException('SaiAdmin发起人不存在或已停用');
        }
        $deptId = (int) ($initiator['dept_id'] ?? 0);
        if ($deptId <= 0) {
            throw new DomainException('SaiAdmin发起人未配置主归属部门');
        }

        $chain = $this->departmentChain($deptId);
        $targetIndex = $request->layerType() === 0
            ? $request->layer()
            : count($chain) - 1 - $request->layer();
        if (!isset($chain[$targetIndex])) {
            throw new DomainException('组织关系层级超出发起人部门树范围');
        }
        $selected = $request->ruleType === FlowConstant::ASSIGNEE['DEPARTMENT_LEADER']
            ? [$chain[$targetIndex]]
            : array_slice($chain, 0, $targetIndex + 1);

        return array_map(
            fn (array $department): ActorRef => $this->departmentLeader($department),
            $selected
        );
    }

    /** @return list<array> */
    private function departmentChain(int $deptId): array
    {
        $chain = [];
        $visited = [];
        while ($deptId > 0) {
            if (isset($visited[$deptId]) || count($chain) >= 100) {
                throw new DomainException('SaiAdmin部门树存在循环或层级过深');
            }
            $visited[$deptId] = true;
            $department = $this->findDepartment($deptId);
            if ($department === null || (int) ($department['status'] ?? 0) !== 1) {
                throw new DomainException('SaiAdmin部门不存在或已停用：' . $deptId);
            }
            $chain[] = $department;
            $deptId = (int) ($department['parent_id'] ?? 0);
        }
        return $chain;
    }

    private function departmentLeader(array $department): ActorRef
    {
        $leaderId = (int) ($department['leader_id'] ?? 0);
        $departmentName = (string) ($department['name'] ?? $department['id'] ?? '');
        if ($leaderId <= 0) {
            throw new DomainException('部门未配置负责人：' . $departmentName);
        }
        $leader = $this->findUser($leaderId);
        if ($leader === null || (int) ($leader['status'] ?? 0) !== 1) {
            throw new DomainException('部门负责人不存在或已停用：' . $departmentName);
        }
        return new ActorRef('sandadmin', (string) $leaderId, [
            'name' => (string) (
                ($leader['realname'] ?? '')
                ?: ($leader['username'] ?? $leaderId)
            ),
            'department_id' => (string) ($department['id'] ?? ''),
            'department_name' => $departmentName,
        ]);
    }

    protected function findUser(int $userId): ?array
    {
        $user = SystemUser::findOrEmpty($userId);
        return $user->isEmpty() ? null : $user->toArray();
    }

    protected function findDepartment(int $deptId): ?array
    {
        $department = SystemDept::findOrEmpty($deptId);
        return $department->isEmpty() ? null : $department->toArray();
    }
}
