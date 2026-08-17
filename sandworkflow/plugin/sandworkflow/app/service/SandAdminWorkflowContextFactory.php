<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\service;

use plugin\sandadmin\app\model\system\SystemUser;
use plugin\sandworkflow\app\contract\ActorRef;
use plugin\sandworkflow\app\contract\WorkflowStartContext;
use plugin\sandadmin\exception\ApiException;

final class SandAdminWorkflowContextFactory
{
    public static function actor(): ActorRef
    {
        $info = function_exists('getCurrentInfo') ? getCurrentInfo() : null;
        $id = (string) ($info['id'] ?? '');
        if ($id === '') {
            throw new ApiException('请先登录后再操作流程');
        }
        return new ActorRef('sandadmin', $id);
    }

    public static function start(array $input): WorkflowStartContext
    {
        $definitionId = (string) ($input['definition_id'] ?? '');
        return new WorkflowStartContext(
            'sandadmin',
            'sandadmin',
            'admin_flow',
            $definitionId === '' ? 'unknown' : $definitionId,
            self::actor(),
            [],
            (string) ($input['request_id'] ?? ''),
            self::participantChoices($input['assignee_choices'] ?? []),
        );
    }

    public static function organizationMaterializer(): OrganizationParticipantMaterializer
    {
        return new OrganizationParticipantMaterializer(
            new OrganizationParticipantRegistry(self::organizationProviders())
        );
    }

    /** @return list<ConfiguredOrganizationParticipantProvider> */
    private static function organizationProviders(): array
    {
        $classes = config('plugin.sandworkflow.app.organization_provider_classes', []);
        if (!is_array($classes)) {
            throw new ApiException('组织关系Provider配置格式错误');
        }
        $providers = [];
        foreach ($classes as $className) {
            if (!is_string($className) || $className === '') {
                throw new ApiException('组织关系Provider配置格式错误');
            }
            $providers[] = new ConfiguredOrganizationParticipantProvider($className);
        }
        return $providers;
    }

    private static function participantChoices(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }
        if (!is_array($input)) {
            throw new ApiException('发起人自选处理人格式错误');
        }
        $result = [];
        foreach ($input as $choiceKey => $rawUserIds) {
            if (!is_string($choiceKey)
                || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $choiceKey)
                || !is_array($rawUserIds)) {
                throw new ApiException('发起人自选处理人格式错误');
            }
            $userIds = [];
            foreach ($rawUserIds as $rawUserId) {
                if ((!is_int($rawUserId) && !is_string($rawUserId))
                    || !ctype_digit((string) $rawUserId)
                    || (int) $rawUserId <= 0) {
                    throw new ApiException('发起人自选处理人必须是有效用户');
                }
                $userIds[(int) $rawUserId] = true;
            }
            $userIds = array_keys($userIds);
            if ($userIds === [] || count($userIds) > 25) {
                throw new ApiException('发起人自选处理人数量必须为1至25人');
            }
            $users = SystemUser::whereIn('id', $userIds)
                ->where('status', 1)
                ->select()
                ->toArray();
            $userMap = [];
            foreach ($users as $user) {
                $userMap[(int) $user['id']] = $user;
            }
            if (count($userMap) !== count($userIds)) {
                throw new ApiException('发起人自选处理人不存在或已停用');
            }
            $result[$choiceKey] = array_map(
                static function (int $userId) use ($userMap): array {
                    $user = $userMap[$userId];
                    return (new ActorRef('sandadmin', (string) $userId, [
                        'name' => (string) (($user['realname'] ?? '') ?: ($user['username'] ?? $userId)),
                    ]))->toArray();
                },
                $userIds
            );
        }
        return $result;
    }
}
