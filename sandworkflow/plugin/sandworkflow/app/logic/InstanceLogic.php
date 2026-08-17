<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\logic;

use plugin\sandadmin\basic\think\BaseLogic;
use plugin\sandadmin\app\model\system\SystemUser;
use plugin\sandadmin\app\model\system\SystemUserRole;
use plugin\sandadmin\exception\ApiException;
use plugin\sandworkflow\app\model\FlowDefinition;
use plugin\sandworkflow\app\model\FlowDefinitionVersion;
use plugin\sandworkflow\app\model\FlowInstance;
use plugin\sandworkflow\app\model\FlowLog;
use plugin\sandworkflow\app\model\FlowTask;
use plugin\sandworkflow\app\model\FlowTaskAssignee;
use plugin\sandworkflow\app\contract\ActorRef;
use plugin\sandworkflow\app\contract\WorkflowStartContext;
use plugin\sandworkflow\app\service\FormValueWriteGuard;
use plugin\sandworkflow\app\service\OrganizationParticipantMaterializer;
use plugin\sandworkflow\common\FlowConstant;
use support\think\Db;

class InstanceLogic extends BaseLogic
{
    private ?ActorRef $actorOverride = null;
    private array $scopeOverride = [];
    private array $participantsOverride = [];
    private bool $monitoringOverride = false;
    private ?WorkflowStartContext $startContextOverride = null;
    private ?OrganizationParticipantMaterializer $organizationMaterializer = null;

    public function __construct()
    {
        $this->model = new FlowInstance();
        $this->orderField = 'id';
        $this->orderType = 'desc';
    }

    public function start(array $data): array
    {
        $actor = $this->requireCurrentActor();
        if ($actor->accountId === '') {
            throw new ApiException('请先登录后再发起流程');
        }
        $requestId = $this->requestId($data);
        if ($requestId !== '') {
            $existing = FlowInstance::where('account_space', $actor->accountSpace)
                ->where('account_id', $actor->accountId)
                ->where('request_id', $requestId)
                ->findOrEmpty();
            if (!$existing->isEmpty()) {
                return $existing->toArray();
            }
        }
        $definitionId = (int) ($data['definition_id'] ?? 0);
        if ($definitionId <= 0 || empty($data['name'])) {
            throw new ApiException('流程定义和实例名称不能为空');
        }
        $definition = FlowDefinition::findOrEmpty($definitionId);
        if ($definition->isEmpty()) {
            throw new ApiException('流程定义不存在');
        }
        if ((int) $definition->getAttr('status') !== 1) {
            throw new ApiException('流程定义已停用');
        }
        if (empty($definition->getAttr('publish_time'))) {
            throw new ApiException('流程定义尚未发布');
        }

        return Db::transaction(function () use ($data, $definition, $definitionId, $requestId, $actor) {
            $definitionJson = $definition->getAttr('definition_json') ?: [];
            $version = FlowDefinitionVersion::where('definition_id', $definitionId)
                ->where('version_no', (int) $definition->getAttr('current_version'))
                ->findOrEmpty();
            $nodeConfig = $definitionJson['nodeConfig'] ?? [];
            if ($this->organizationMaterializer !== null) {
                if ($this->startContextOverride === null || !is_array($nodeConfig)) {
                    throw new ApiException('组织关系启动上下文无效');
                }
                try {
                    $materializedContext = $this->organizationMaterializer->materializeContext(
                        $nodeConfig,
                        $this->startContextOverride
                    );
                } catch (\Throwable $exception) {
                    throw new ApiException($exception->getMessage());
                }
                $this->participantsOverride = $materializedContext->runtimeParticipants();
            }
            $formValue = is_array($data['form_value'] ?? null) ? $data['form_value'] : [];
            $route = $this->resolveNextTaskRoute(
                is_array($nodeConfig) ? $nodeConfig : null,
                $formValue
            );
            $firstNode = $route['task'];

            $instance = FlowInstance::create([
                'definition_id' => $definitionId,
                'definition_version_id' => $version->isEmpty() ? null : $version->getKey(),
                'definition_snapshot' => $definitionJson,
                'request_id' => $requestId === '' ? null : $requestId,
                'account_space' => $actor->accountSpace,
                'account_id' => $actor->accountId,
                'tenant_id' => $this->scopeOverride['tenant_id'] ?? 'sandadmin',
                'owner_scope' => $this->scopeOverride['owner_scope'] ?? 'sandadmin',
                'business_type' => $this->scopeOverride['business_type'] ?? 'admin',
                'business_id' => $this->scopeOverride['business_id'] ?? (string) $definitionId,
                'participants' => $this->participantsOverride,
                'name' => $data['name'],
                'form_value' => $formValue,
                'act_node_id' => $firstNode ? $this->nodeIdentity($firstNode) : 'end',
                'flow_status' => FlowConstant::STATUS['UNDERWAY'],
            ]);

            FlowLog::create([
                'instance_id' => $instance->getKey(),
                'node_id' => 'start',
                'node_name' => '发起',
                'operation_type' => FlowConstant::CMD['START'],
                'account_space' => $actor->accountSpace,
                'account_id' => $actor->accountId,
                'assignee_snapshot' => [$actor->toArray()],
            ]);
            $this->recordCopyNodes($instance, $route['copies'], $actor);

            if ($firstNode) {
                $this->createPendingTask((string) $instance->getKey(), $definitionId, $firstNode);
            } else {
                $instance->save([
                    'act_node_id' => 'end',
                    'flow_status' => FlowConstant::STATUS['APPROVED'],
                    'ended_time' => date('Y-m-d H:i:s'),
                ]);
            }

            return $instance->toArray();
        });
    }

    public function startForContext(
        array $data,
        WorkflowStartContext $context,
        ?OrganizationParticipantMaterializer $organizationMaterializer = null
    ): array {
        $previousStartContext = $this->startContextOverride;
        $previousMaterializer = $this->organizationMaterializer;
        $this->startContextOverride = $context;
        $this->organizationMaterializer = $organizationMaterializer;
        try {
            return $this->withContext($context->initiator, [
                'tenant_id' => $context->tenantId,
                'owner_scope' => $context->ownerScope,
                'business_type' => $context->businessType,
                'business_id' => $context->businessId,
            ], $context->runtimeParticipants(), function () use ($data, $context): array {
                $data['request_id'] = $context->requestId;
                return $this->start($data);
            });
        } finally {
            $this->startContextOverride = $previousStartContext;
            $this->organizationMaterializer = $previousMaterializer;
        }
    }

    public function approveForActor(
        string $taskId,
        ActorRef $actor,
        array $scope,
        string $comment = '',
        string $requestId = '',
        array $formValue = []
    ): array
    {
        return $this->withContext($actor, $scope, [], function () use (
            $taskId,
            $comment,
            $requestId,
            $formValue
        ): array {
            $input = [
                'task_id' => $taskId,
                'comment' => $comment,
                'request_id' => $requestId,
            ];
            if ($formValue !== []) {
                $input['form_value'] = $formValue;
            }
            return $this->handleAction($input, FlowConstant::CMD['APPROVED']);
        });
    }

    public function commentForActor(array $data, ActorRef $actor, array $scope): array
    {
        return $this->withContext($actor, $scope, [], fn (): array => $this->comment($data));
    }

    public function transferForActor(array $data, ActorRef $actor, array $scope): array
    {
        return $this->withContext($actor, $scope, [], fn (): array => $this->transfer($data));
    }

    public function addSignForActor(array $data, ActorRef $actor, array $scope): array
    {
        return $this->withContext($actor, $scope, [], fn (): array => $this->addSign($data));
    }

    public function delSignForActor(array $data, ActorRef $actor, array $scope): array
    {
        return $this->withContext($actor, $scope, [], fn (): array => $this->delSign($data));
    }

    public function backNodeListForActor(string $taskId, ActorRef $actor, array $scope): array
    {
        return $this->withContext($actor, $scope, [], fn (): array => $this->backNodeList($taskId));
    }

    public function backForActor(array $data, ActorRef $actor, array $scope): array
    {
        return $this->withContext($actor, $scope, [], fn (): array => $this->back($data));
    }

    public function copyQueryForActor(ActorRef $actor, array $scope): mixed
    {
        return $this->withContext($actor, $scope, [], fn () => $this->copyQuery());
    }

    public function detailForActor(
        string $instanceId,
        ActorRef $actor,
        array $scope,
        bool $monitoring = false
    ): array
    {
        $previousMonitoring = $this->monitoringOverride;
        $this->monitoringOverride = $monitoring;
        try {
            return $this->withContext($actor, $scope, [], fn (): array => $this->detail($instanceId));
        } finally {
            $this->monitoringOverride = $previousMonitoring;
        }
    }

    public function pendingForActor(ActorRef $actor, array $scope): mixed
    {
        return $this->withContext($actor, $scope, [], fn () => $this->pendingQuery());
    }

    public function handleAction(array $data, int $operationType): array
    {
        $requestId = $this->requestId($data);
        if ($requestId !== '') {
            $actor = $this->requireCurrentActor();
            $processed = FlowTaskAssignee::where('account_space', $actor->accountSpace)
                ->where('account_id', $actor->accountId)
                ->where('request_id', $requestId)
                ->findOrEmpty();
            if (!$processed->isEmpty()) {
                $existingTask = FlowTask::findOrEmpty((string) $processed->getAttr('task_id'));
                if (!$existingTask->isEmpty()) {
                    return $existingTask->toArray();
                }
            }
        }
        $taskId = trim((string) ($data['task_id'] ?? ''));
        if ($taskId === '') {
            throw new ApiException('任务ID不能为空');
        }

        return Db::transaction(function () use ($data, $operationType, $taskId, $requestId) {
            $taskSnapshot = FlowTask::findOrEmpty($taskId);
            if ($taskSnapshot->isEmpty()) {
                throw new ApiException('任务不存在');
            }
            $instance = FlowInstance::where('id', (string) $taskSnapshot->getAttr('instance_id'))
                ->lock(true)
                ->findOrEmpty();
            if ($instance->isEmpty()) {
                throw new ApiException('流程实例不存在');
            }
            $this->assertScope($instance);
            $this->loadInstanceParticipants($instance);
            if ((int) $instance->getAttr('flow_status') !== FlowConstant::STATUS['UNDERWAY']) {
                throw new ApiException('当前流程已结束，不能继续处理');
            }

            $task = FlowTask::where('id', $taskId)->lock(true)->findOrEmpty();
            if ($task->isEmpty()) {
                throw new ApiException('任务不存在');
            }
            if ((int) $task->getAttr('task_status') !== 0) {
                throw new ApiException('任务已处理');
            }
            $this->assertTaskAssignee($task);

            $formChanges = [];
            if (array_key_exists('form_value', $data)) {
                $currentFormValue = is_array($instance->getAttr('form_value'))
                    ? $instance->getAttr('form_value')
                    : [];
                $writeResult = (new FormValueWriteGuard())->applyWithAudit(
                    is_array($instance->getAttr('definition_snapshot'))
                        ? $instance->getAttr('definition_snapshot')
                        : [],
                    (string) $task->getAttr('node_id'),
                    $currentFormValue,
                    $data['form_value']
                );
                $nextFormValue = $writeResult['form_value'];
                $formChanges = $writeResult['changes'];
                if ($nextFormValue !== $currentFormValue) {
                    $instance->save(['form_value' => $nextFormValue]);
                }
            }

            $now = date('Y-m-d H:i:s');
            $actor = $this->requireCurrentActor();
            FlowTaskAssignee::where('task_id', $taskId)
                ->where('account_space', $actor->accountSpace)
                ->where('account_id', $actor->accountId)
                ->where('action_status', 0)
                ->update([
                    'action_status' => 1,
                    'operation_type' => $operationType,
                    'comment' => $data['comment'] ?? '',
                    'request_id' => $requestId === '' ? null : $requestId,
                    'ended_time' => $now,
                ]);

            FlowLog::create([
                'instance_id' => $task->getAttr('instance_id'),
                'task_id' => $task->getKey(),
                'node_id' => $task->getAttr('node_id'),
                'node_name' => $task->getAttr('node_name'),
                'operation_type' => $operationType,
                'account_space' => $actor->accountSpace,
                'account_id' => $actor->accountId,
                'assignee_snapshot' => $task->getAttr('assignee_refs') ?: [],
                'form_change_snapshot' => $formChanges === [] ? null : $formChanges,
                'comment' => $data['comment'] ?? '',
            ]);

            if ($operationType === FlowConstant::CMD['REJECTED'] || $operationType === FlowConstant::CMD['CANCELED']) {
                $this->finishTask($task, $operationType, $data['comment'] ?? '', $now);
                $instance->save([
                    'act_node_id' => 'end',
                    'flow_status' => $operationType === FlowConstant::CMD['REJECTED']
                        ? FlowConstant::STATUS['REJECTED']
                        : FlowConstant::STATUS['CANCELLED'],
                    'ended_time' => $now,
                ]);

                return $task->toArray();
            }

            $signType = (int) $task->getAttr('sign_type');
            if ($signType === 1 || $signType === 3) {
                $pendingCount = FlowTaskAssignee::where('task_id', $taskId)
                    ->where('action_status', 0)
                    ->count();
                if ($pendingCount > 0) {
                    return $task->toArray();
                }
            }

            $this->finishTask($task, $operationType, $data['comment'] ?? '', $now);

            $route = $this->findNextTaskRoute($task);
            $this->recordCopyNodes($instance, $route['copies'], $actor);
            $nextNode = $route['task'];
            if ($nextNode) {
                $this->createPendingTask(
                    (string) $task->getAttr('instance_id'),
                    (int) $task->getAttr('definition_id'),
                    $nextNode
                );
                $instance->save([
                    'act_node_id' => $this->nodeIdentity($nextNode),
                    'flow_status' => FlowConstant::STATUS['UNDERWAY'],
                    'ended_time' => null,
                ]);

                return $task->toArray();
            }

            $instance->save([
                'act_node_id' => 'end',
                'flow_status' => FlowConstant::STATUS['APPROVED'],
                'ended_time' => $now,
            ]);

            return $task->toArray();
        });
    }

    public function comment(array $data): array
    {
        $instanceId = trim((string) ($data['instance_id'] ?? ''));
        $comment = trim((string) ($data['comment'] ?? ''));
        $taskId = trim((string) ($data['task_id'] ?? ''));
        if ($instanceId === '' || $comment === '') {
            throw new ApiException('流程实例和评论内容不能为空');
        }
        if (mb_strlen($comment) > 1000) {
            throw new ApiException('评论内容不能超过1000个字符');
        }

        return Db::transaction(function () use ($instanceId, $taskId, $comment): array {
            $instance = FlowInstance::where('id', $instanceId)->lock(true)->findOrEmpty();
            if ($instance->isEmpty()) {
                throw new ApiException('流程实例不存在');
            }
            $this->assertScope($instance);
            $actor = $this->requireCurrentActor();
            $isInitiator = $instance->getAttr('account_space') === $actor->accountSpace
                && $instance->getAttr('account_id') === $actor->accountId;
            $isAssignee = !FlowTaskAssignee::where('instance_id', $instanceId)
                ->where('account_space', $actor->accountSpace)
                ->where('account_id', $actor->accountId)
                ->findOrEmpty()
                ->isEmpty();
            if (!$isInitiator && !$isAssignee) {
                throw new ApiException('当前用户不是该流程的参与者');
            }

            $task = null;
            if ($taskId !== '') {
                $task = FlowTask::where('id', $taskId)->where('instance_id', $instanceId)->findOrEmpty();
                if ($task->isEmpty()) {
                    throw new ApiException('任务不属于当前流程实例');
                }
            }
            $hasTask = $task !== null && !$task->isEmpty();
            $log = FlowLog::create([
                'instance_id' => $instanceId,
                'task_id' => $hasTask ? $task->getKey() : null,
                'node_id' => $hasTask ? $task->getAttr('node_id') : null,
                'node_name' => $hasTask ? $task->getAttr('node_name') : '评论',
                'operation_type' => FlowConstant::CMD['COMMENT'],
                'account_space' => $actor->accountSpace,
                'account_id' => $actor->accountId,
                'assignee_snapshot' => [],
                'comment' => $comment,
            ]);
            return $log->toArray();
        });
    }

    public function transfer(array $data): array
    {
        $taskId = trim((string) ($data['task_id'] ?? ''));
        $rawAssigneeId = $data['assignee_id'] ?? null;
        $comment = trim((string) ($data['comment'] ?? ''));
        $requestId = $this->requestId($data);
        if ($taskId === '') {
            throw new ApiException('任务ID不能为空');
        }
        if ((!is_int($rawAssigneeId) && !is_string($rawAssigneeId))
            || !ctype_digit((string) $rawAssigneeId)
            || (int) $rawAssigneeId <= 0) {
            throw new ApiException('目标处理人不能为空');
        }
        if (mb_strlen($comment) > 1000) {
            throw new ApiException('转办说明不能超过1000个字符');
        }

        $actor = $this->requireCurrentActor();
        if ($actor->accountSpace !== 'sandadmin' || !ctype_digit($actor->accountId)) {
            throw new ApiException('当前仅支持转办给SandAdmin用户');
        }
        $targetAccountId = (string) (int) $rawAssigneeId;
        if ($targetAccountId === $actor->accountId) {
            throw new ApiException('不能转办给自己');
        }

        return Db::transaction(function () use (
            $taskId,
            $targetAccountId,
            $comment,
            $requestId,
            $actor
        ): array {
            $taskSnapshot = FlowTask::findOrEmpty($taskId);
            if ($taskSnapshot->isEmpty()) {
                throw new ApiException('任务不存在');
            }
            $instance = FlowInstance::where('id', (string) $taskSnapshot->getAttr('instance_id'))
                ->lock(true)
                ->findOrEmpty();
            if ($instance->isEmpty()) {
                throw new ApiException('流程实例不存在');
            }
            $this->assertScope($instance);

            $task = FlowTask::where('id', $taskId)->lock(true)->findOrEmpty();
            if ($task->isEmpty()) {
                throw new ApiException('任务不存在');
            }

            if ($requestId !== '') {
                $processed = FlowTaskAssignee::where('task_id', $taskId)
                    ->where('account_space', $actor->accountSpace)
                    ->where('account_id', $actor->accountId)
                    ->where('operation_type', FlowConstant::CMD['TRANSFER'])
                    ->where('request_id', $requestId)
                    ->findOrEmpty();
                if (!$processed->isEmpty()) {
                    return $this->taskWithAssignees($taskId);
                }
            }

            if ((int) $instance->getAttr('flow_status') !== FlowConstant::STATUS['UNDERWAY']) {
                throw new ApiException('当前流程已结束，不能转办');
            }
            if ((int) $task->getAttr('task_status') !== 0) {
                throw new ApiException('任务已处理，不能转办');
            }
            $this->assertTaskAssignee($task);

            $currentAssignee = FlowTaskAssignee::where('task_id', $taskId)
                ->where('account_space', $actor->accountSpace)
                ->where('account_id', $actor->accountId)
                ->where('action_status', 0)
                ->lock(true)
                ->findOrEmpty();
            if ($currentAssignee->isEmpty()) {
                throw new ApiException('当前用户不是该任务的待办处理人');
            }

            $targetUser = SystemUser::where('id', (int) $targetAccountId)
                ->where('status', 1)
                ->findOrEmpty();
            if ($targetUser->isEmpty()) {
                throw new ApiException('目标处理人不存在或已停用');
            }
            $duplicatedTarget = FlowTaskAssignee::where('task_id', $taskId)
                ->where('account_space', 'sandadmin')
                ->where('account_id', $targetAccountId)
                ->findOrEmpty();
            if (!$duplicatedTarget->isEmpty()) {
                throw new ApiException('目标处理人已在当前任务中');
            }

            $sourceUser = SystemUser::where('id', (int) $actor->accountId)->findOrEmpty();
            $sourceRef = new ActorRef('sandadmin', $actor->accountId, [
                'name' => $sourceUser->isEmpty()
                    ? $actor->accountId
                    : (string) ($sourceUser->getAttr('realname') ?: $sourceUser->getAttr('username')),
            ]);
            $targetRef = new ActorRef('sandadmin', $targetAccountId, [
                'name' => (string) ($targetUser->getAttr('realname') ?: $targetUser->getAttr('username')),
            ]);
            $now = date('Y-m-d H:i:s');

            $currentAssignee->save([
                'action_status' => 2,
                'operation_type' => FlowConstant::CMD['TRANSFER'],
                'comment' => $comment,
                'request_id' => $requestId === '' ? null : $requestId,
                'ended_time' => $now,
            ]);
            FlowTaskAssignee::create([
                'task_id' => $taskId,
                'instance_id' => $task->getAttr('instance_id'),
                'account_space' => 'sandadmin',
                'account_id' => $targetAccountId,
                'sequence_no' => (int) $currentAssignee->getAttr('sequence_no'),
                'action_status' => 0,
            ]);

            $task->save([
                'assignee_refs' => $this->replaceAssigneeRef(
                    (array) ($task->getAttr('assignee_refs') ?: []),
                    $actor,
                    $targetRef
                ),
            ]);
            FlowLog::create([
                'instance_id' => $task->getAttr('instance_id'),
                'task_id' => $taskId,
                'node_id' => $task->getAttr('node_id'),
                'node_name' => $task->getAttr('node_name'),
                'operation_type' => FlowConstant::CMD['TRANSFER'],
                'account_space' => $actor->accountSpace,
                'account_id' => $actor->accountId,
                'assignee_snapshot' => [
                    'from' => $sourceRef->toArray(),
                    'to' => $targetRef->toArray(),
                ],
                'comment' => $comment,
            ]);

            return $this->taskWithAssignees($taskId);
        });
    }

    public function addSign(array $data): array
    {
        return $this->changeTaskAssignee($data, FlowConstant::CMD['ADD_SIGN']);
    }

    public function delSign(array $data): array
    {
        return $this->changeTaskAssignee($data, FlowConstant::CMD['DEL_SIGN']);
    }

    private function changeTaskAssignee(array $data, int $operationType): array
    {
        $taskId = trim((string) ($data['task_id'] ?? ''));
        $rawAssigneeId = $data['assignee_id'] ?? null;
        $comment = trim((string) ($data['comment'] ?? ''));
        $requestId = $this->requestId($data);
        $isAdd = $operationType === FlowConstant::CMD['ADD_SIGN'];
        $actionName = $isAdd ? '加签' : '减签';
        if ($taskId === '') {
            throw new ApiException('任务ID不能为空');
        }
        if ((!is_int($rawAssigneeId) && !is_string($rawAssigneeId))
            || !ctype_digit((string) $rawAssigneeId)
            || (int) $rawAssigneeId <= 0) {
            throw new ApiException($actionName . '处理人不能为空');
        }
        if ($comment === '') {
            throw new ApiException($actionName . '说明不能为空');
        }
        if (mb_strlen($comment) > 1000) {
            throw new ApiException($actionName . '说明不能超过1000个字符');
        }

        $actor = $this->requireCurrentActor();
        if ($actor->accountSpace !== 'sandadmin' || !ctype_digit($actor->accountId)) {
            throw new ApiException('当前仅支持SandAdmin用户加签/减签');
        }
        $targetAccountId = (string) (int) $rawAssigneeId;
        if ($targetAccountId === $actor->accountId) {
            throw new ApiException('不能对自己加签或减签');
        }

        return Db::transaction(function () use (
            $taskId,
            $targetAccountId,
            $comment,
            $requestId,
            $operationType,
            $isAdd,
            $actionName,
            $actor
        ): array {
            $taskSnapshot = FlowTask::findOrEmpty($taskId);
            if ($taskSnapshot->isEmpty()) {
                throw new ApiException('任务不存在');
            }
            $instance = FlowInstance::where('id', (string) $taskSnapshot->getAttr('instance_id'))
                ->lock(true)
                ->findOrEmpty();
            if ($instance->isEmpty()) {
                throw new ApiException('流程实例不存在');
            }
            $this->assertScope($instance);

            $task = FlowTask::where('id', $taskId)->lock(true)->findOrEmpty();
            if ($task->isEmpty()) {
                throw new ApiException('任务不存在');
            }

            if ($requestId !== '') {
                $processed = FlowTaskAssignee::where('request_id', $requestId)
                    ->lock(true)
                    ->findOrEmpty();
                if (!$processed->isEmpty()) {
                    if ((string) $processed->getAttr('task_id') === $taskId
                        && (string) $processed->getAttr('account_space') === 'sandadmin'
                        && (string) $processed->getAttr('account_id') === $targetAccountId
                        && (int) $processed->getAttr('operation_type') === $operationType) {
                        return $this->taskWithAssignees($taskId);
                    }
                    throw new ApiException('请求幂等键已被其他操作使用');
                }
            }

            if ((int) $instance->getAttr('flow_status') !== FlowConstant::STATUS['UNDERWAY']) {
                throw new ApiException('当前流程已结束，不能' . $actionName);
            }
            if ((int) $task->getAttr('task_status') !== 0) {
                throw new ApiException('任务已处理，不能' . $actionName);
            }
            $this->assertTaskAssignee($task);
            $this->assertTaskSignable($task, $instance);

            $targetUser = SystemUser::where('id', (int) $targetAccountId)
                ->where('status', 1)
                ->findOrEmpty();
            if ($targetUser->isEmpty()) {
                throw new ApiException('目标处理人不存在或已停用');
            }
            $targetRef = new ActorRef('sandadmin', $targetAccountId, [
                'name' => (string) ($targetUser->getAttr('realname') ?: $targetUser->getAttr('username')),
            ]);
            $operatorUser = SystemUser::where('id', (int) $actor->accountId)->findOrEmpty();
            $operatorRef = new ActorRef('sandadmin', $actor->accountId, [
                'name' => $operatorUser->isEmpty()
                    ? $actor->accountId
                    : (string) ($operatorUser->getAttr('realname') ?: $operatorUser->getAttr('username')),
            ]);
            $now = date('Y-m-d H:i:s');

            if ($isAdd) {
                $existingTarget = FlowTaskAssignee::where('task_id', $taskId)
                    ->where('account_space', 'sandadmin')
                    ->where('account_id', $targetAccountId)
                    ->lock(true)
                    ->findOrEmpty();
                if (!$existingTarget->isEmpty()) {
                    throw new ApiException('目标处理人已在当前任务中');
                }
                $sequenceNo = (int) FlowTaskAssignee::where('task_id', $taskId)->max('sequence_no') + 1;
                FlowTaskAssignee::create([
                    'task_id' => $taskId,
                    'instance_id' => $task->getAttr('instance_id'),
                    'account_space' => 'sandadmin',
                    'account_id' => $targetAccountId,
                    'sequence_no' => max(1, $sequenceNo),
                    'action_status' => 0,
                    'operation_type' => FlowConstant::CMD['ADD_SIGN'],
                    'comment' => $comment,
                    'request_id' => $requestId === '' ? null : $requestId,
                ]);
                $task->save([
                    'sign_type' => (int) $task->getAttr('sign_type') === 0
                        ? 1
                        : (int) $task->getAttr('sign_type'),
                    'assignee_refs' => $this->appendAssigneeRef(
                        (array) ($task->getAttr('assignee_refs') ?: []),
                        $targetRef
                    ),
                ]);
            } else {
                $targetAssignee = FlowTaskAssignee::where('task_id', $taskId)
                    ->where('account_space', 'sandadmin')
                    ->where('account_id', $targetAccountId)
                    ->where('action_status', 0)
                    ->lock(true)
                    ->findOrEmpty();
                if ($targetAssignee->isEmpty()) {
                    throw new ApiException('减签目标不是当前任务的待办处理人');
                }
                $pendingCount = FlowTaskAssignee::where('task_id', $taskId)
                    ->where('action_status', 0)
                    ->count();
                if ($pendingCount <= 1) {
                    throw new ApiException('当前任务至少保留一个待办处理人');
                }
                $targetAssignee->save([
                    'action_status' => 2,
                    'operation_type' => FlowConstant::CMD['DEL_SIGN'],
                    'comment' => $comment,
                    'request_id' => $requestId === '' ? null : $requestId,
                    'ended_time' => $now,
                ]);
                $task->save([
                    'assignee_refs' => $this->removeAssigneeRef(
                        (array) ($task->getAttr('assignee_refs') ?: []),
                        $targetRef
                    ),
                ]);
            }

            FlowLog::create([
                'instance_id' => $task->getAttr('instance_id'),
                'task_id' => $taskId,
                'node_id' => $task->getAttr('node_id'),
                'node_name' => $task->getAttr('node_name'),
                'operation_type' => $operationType,
                'account_space' => $actor->accountSpace,
                'account_id' => $actor->accountId,
                'assignee_snapshot' => [
                    'operator' => $operatorRef->toArray(),
                    'target' => $targetRef->toArray(),
                ],
                'comment' => $comment,
            ]);

            return $this->taskWithAssignees($taskId);
        });
    }

    public function backNodeList(string $taskId): array
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new ApiException('任务ID不能为空');
        }
        $task = FlowTask::findOrEmpty($taskId);
        if ($task->isEmpty()) {
            throw new ApiException('任务不存在');
        }
        $instance = FlowInstance::findOrEmpty((string) $task->getAttr('instance_id'));
        if ($instance->isEmpty()) {
            throw new ApiException('流程实例不存在');
        }
        $this->assertScope($instance);
        if ((int) $instance->getAttr('flow_status') !== FlowConstant::STATUS['UNDERWAY']
            || (int) $task->getAttr('task_status') !== 0) {
            throw new ApiException('当前任务已结束，不能回退');
        }
        $this->assertTaskAssignee($task);
        $this->assertTaskBackable($task, $instance);
        return $this->backHistoryNodes($task, $instance);
    }

    public function back(array $data): array
    {
        $taskId = trim((string) ($data['task_id'] ?? ''));
        $targetNodeId = trim((string) ($data['target_node_id'] ?? ''));
        $comment = trim((string) ($data['comment'] ?? ''));
        $requestId = $this->requestId($data);
        if ($taskId === '' || $targetNodeId === '') {
            throw new ApiException('任务ID和回退节点不能为空');
        }
        if ($comment === '') {
            throw new ApiException('回退说明不能为空');
        }
        if (mb_strlen($comment) > 1000) {
            throw new ApiException('回退说明不能超过1000个字符');
        }

        return Db::transaction(function () use ($taskId, $targetNodeId, $comment, $requestId): array {
            $taskSnapshot = FlowTask::findOrEmpty($taskId);
            if ($taskSnapshot->isEmpty()) {
                throw new ApiException('任务不存在');
            }
            $instance = FlowInstance::where('id', (string) $taskSnapshot->getAttr('instance_id'))
                ->lock(true)
                ->findOrEmpty();
            if ($instance->isEmpty()) {
                throw new ApiException('流程实例不存在');
            }
            $this->assertScope($instance);
            $this->loadInstanceParticipants($instance);
            $task = FlowTask::where('id', $taskId)->lock(true)->findOrEmpty();
            if ($task->isEmpty()) {
                throw new ApiException('任务不存在');
            }

            if ($requestId !== ''
                && (int) $task->getAttr('operation_type') === FlowConstant::CMD['BACK']
                && (string) $task->getAttr('request_id') === $requestId) {
                return $this->detail((string) $instance->getKey());
            }
            if ((int) $instance->getAttr('flow_status') !== FlowConstant::STATUS['UNDERWAY']) {
                throw new ApiException('当前流程已结束，不能回退');
            }
            if ((int) $task->getAttr('task_status') !== 0) {
                throw new ApiException('任务已处理，不能回退');
            }
            $this->assertTaskAssignee($task);
            $sourceNode = $this->assertTaskBackable($task, $instance);
            $targetNode = null;
            foreach ($this->backHistoryNodes($task, $instance) as $historyNode) {
                if ((string) $historyNode['id'] === $targetNodeId) {
                    $targetNode = $this->instanceActionNode($instance, $targetNodeId);
                    break;
                }
            }
            if ($targetNode === null) {
                throw new ApiException('目标节点不是当前流程可回退的历史节点');
            }

            $actor = $this->requireCurrentActor();
            $currentAssignee = FlowTaskAssignee::where('task_id', $taskId)
                ->where('account_space', $actor->accountSpace)
                ->where('account_id', $actor->accountId)
                ->where('action_status', 0)
                ->lock(true)
                ->findOrEmpty();
            if ($currentAssignee->isEmpty()) {
                throw new ApiException('当前用户不是该任务的待办处理人');
            }
            $now = date('Y-m-d H:i:s');
            $currentAssignee->save([
                'action_status' => 1,
                'operation_type' => FlowConstant::CMD['BACK'],
                'comment' => $comment,
                'request_id' => $requestId === '' ? null : $requestId,
                'ended_time' => $now,
            ]);
            FlowTaskAssignee::where('task_id', $taskId)
                ->where('action_status', 0)
                ->update([
                    'action_status' => 2,
                    'ended_time' => $now,
                ]);
            $task->save([
                'operation_type' => FlowConstant::CMD['BACK'],
                'comment' => $comment,
                'request_id' => $requestId === '' ? null : $requestId,
                'ended_time' => $now,
                'task_status' => 1,
            ]);

            $this->createPendingTask(
                (string) $instance->getKey(),
                (int) $task->getAttr('definition_id'),
                $targetNode,
                new ActorRef(
                    (string) $instance->getAttr('account_space'),
                    (string) $instance->getAttr('account_id')
                )
            );
            $instance->save([
                'act_node_id' => $targetNodeId,
                'flow_status' => FlowConstant::STATUS['UNDERWAY'],
                'ended_time' => null,
            ]);
            FlowLog::create([
                'instance_id' => $instance->getKey(),
                'task_id' => $taskId,
                'node_id' => $targetNodeId,
                'node_name' => $targetNode['name'] ?? '回退节点',
                'operation_type' => FlowConstant::CMD['BACK'],
                'account_space' => $actor->accountSpace,
                'account_id' => $actor->accountId,
                'assignee_snapshot' => [
                    'operator' => $actor->toArray(),
                    'from_node' => [
                        'id' => $this->nodeIdentity($sourceNode),
                        'name' => $sourceNode['name'] ?? $task->getAttr('node_name'),
                    ],
                    'to_node' => [
                        'id' => $targetNodeId,
                        'name' => $targetNode['name'] ?? '回退节点',
                    ],
                ],
                'comment' => $comment,
            ]);

            return $this->detail((string) $instance->getKey());
        });
    }

    public function pendingQuery()
    {
        $actor = $this->requireCurrentActor();
        $query = FlowTask::alias('task')
            ->with('instance')
            ->join('sand_workflow_task_assignee assignee', 'assignee.task_id = task.id')
            ->where('task.task_status', 0)
            ->where('assignee.account_space', $actor->accountSpace)
            ->where('assignee.account_id', $actor->accountId)
            ->where('assignee.action_status', 0)
            ->whereRaw("task.sign_type <> 3 OR NOT EXISTS (SELECT 1 FROM sand_workflow_task_assignee previous_assignee WHERE previous_assignee.task_id = task.id AND previous_assignee.sequence_no < assignee.sequence_no AND previous_assignee.action_status <> 1)")
            ->field('task.*');
        if ($this->scopeOverride !== []) {
            $query->hasWhere('instance', function ($query): void {
                foreach (['tenant_id', 'owner_scope', 'business_type', 'business_id'] as $field) {
                    if (isset($this->scopeOverride[$field])) {
                        $query->where($field, $this->scopeOverride[$field]);
                    }
                }
            });
        }
        return $query;
    }

    public function myQuery()
    {
        $actor = $this->requireCurrentActor();
        return FlowInstance::where('account_space', $actor->accountSpace)
            ->where('account_id', $actor->accountId);
    }

    public function processedQuery()
    {
        return FlowTask::alias('task')
            ->with('instance')
            ->join('sand_workflow_task_assignee assignee', 'assignee.task_id = task.id')
            ->where('assignee.account_space', $this->requireCurrentActor()->accountSpace)
            ->where('assignee.account_id', $this->requireCurrentActor()->accountId)
            ->where('assignee.action_status', 1)
            ->field('task.*, assignee.operation_type, assignee.comment, assignee.ended_time');
    }

    public function copyQuery()
    {
        $actor = $this->requireCurrentActor();
        // PostgreSQL JSONB containment keeps the copy recipient check in SQL.
        $query = FlowInstance::alias('instance')
            ->whereRaw(
                "EXISTS (
                    SELECT 1
                    FROM sand_workflow_log copy_log
                    WHERE copy_log.instance_id = instance.id
                      AND copy_log.operation_type = ?
                      AND copy_log.delete_time IS NULL
                      AND copy_log.assignee_snapshot @> ?::jsonb
                )",
                [
                    FlowConstant::CMD['COPY'],
                    json_encode(['account_space' => $actor->accountSpace, 'account_id' => $actor->accountId], JSON_THROW_ON_ERROR),
                ]
            )
            ->field('instance.*');
        foreach (['tenant_id', 'owner_scope', 'business_type', 'business_id'] as $field) {
            if (isset($this->scopeOverride[$field])) {
                $query->where('instance.' . $field, $this->scopeOverride[$field]);
            }
        }
        return $query;
    }

    public function cancel(string $instanceId): array
    {
        if ($instanceId === '') {
            throw new ApiException('流程实例ID不能为空');
        }

        return Db::transaction(function () use ($instanceId) {
            $instance = FlowInstance::where('id', $instanceId)->lock(true)->findOrEmpty();
            if ($instance->isEmpty()) {
                throw new ApiException('流程实例不存在');
            }
            $this->assertScope($instance);
            $actor = $this->requireCurrentActor();
            if ($instance->getAttr('account_space') !== $actor->accountSpace || $instance->getAttr('account_id') !== $actor->accountId) {
                throw new ApiException('只有发起人可以撤销流程');
            }
            if ((int) $instance->getAttr('flow_status') !== FlowConstant::STATUS['UNDERWAY']) {
                throw new ApiException('当前流程不能撤销');
            }

            $definition = FlowDefinition::findOrEmpty((int) $instance->getAttr('definition_id'));
            if ($definition->isEmpty() || (int) $definition->getAttr('is_cancelable') !== 1) {
                throw new ApiException('该流程不允许撤销');
            }

            $now = date('Y-m-d H:i:s');
            $activeNodeId = (string) $instance->getAttr('act_node_id');
            FlowTask::where('instance_id', $instanceId)->where('task_status', 0)->update([
                'task_status' => 2,
                'ended_time' => $now,
            ]);
            FlowTaskAssignee::where('instance_id', $instanceId)->where('action_status', 0)->update([
                'action_status' => 2,
                'ended_time' => $now,
            ]);
            $instance->save([
                'act_node_id' => 'end',
                'flow_status' => FlowConstant::STATUS['CANCELLED'],
                'ended_time' => $now,
            ]);
            FlowLog::create([
                'instance_id' => $instanceId,
                'node_id' => $activeNodeId,
                'node_name' => '撤销流程',
                'operation_type' => FlowConstant::CMD['CANCELED'],
                'account_space' => $actor->accountSpace,
                'account_id' => $actor->accountId,
                'assignee_snapshot' => [],
            ]);

            return $instance->toArray();
        });
    }

    public function detail(string $id): array
    {
        if (trim($id) === '') {
            throw new ApiException('流程实例ID不能为空');
        }
        $instance = FlowInstance::with(['definition', 'tasks.assignees', 'logs'])->findOrEmpty($id);
        if ($instance->isEmpty()) {
            throw new ApiException('流程实例不存在');
        }
        $this->assertScope($instance);
        $this->assertInstanceViewer($instance);
        $detail = $this->normalizeProcessView($instance->toArray());
        // monitoring 仅放宽参与者校验；表单裁剪与 form_access 仍按当前 Actor 计算。
        return $this->applyFormVisibility($detail, $this->requireCurrentActor());
    }

    protected function normalizeProcessView(array $detail): array
    {
        $sortByTimeAndId = static function (array $left, array $right): int {
            $leftKey = (string) ($left['create_time'] ?? '') . '|' . (string) ($left['id'] ?? '');
            $rightKey = (string) ($right['create_time'] ?? '') . '|' . (string) ($right['id'] ?? '');
            return $leftKey <=> $rightKey;
        };
        $tasks = is_array($detail['tasks'] ?? null) ? $detail['tasks'] : [];
        usort($tasks, $sortByTimeAndId);
        foreach ($tasks as &$task) {
            $assignees = is_array($task['assignees'] ?? null) ? $task['assignees'] : [];
            usort($assignees, static function (array $left, array $right) use ($sortByTimeAndId): int {
                $sequence = (int) ($left['sequence_no'] ?? 0) <=> (int) ($right['sequence_no'] ?? 0);
                return $sequence === 0 ? $sortByTimeAndId($left, $right) : $sequence;
            });
            $task['assignees'] = $assignees;
        }
        unset($task);
        $detail['tasks'] = $tasks;

        $logs = is_array($detail['logs'] ?? null) ? $detail['logs'] : [];
        usort($logs, $sortByTimeAndId);
        $detail['logs'] = $logs;
        return $detail;
    }

    protected function applyFormVisibility(array $detail, ActorRef $actor): array
    {
        $formValue = is_array($detail['form_value'] ?? null) ? $detail['form_value'] : [];
        $snapshot = is_array($detail['definition_snapshot'] ?? null)
            ? $detail['definition_snapshot']
            : [];
        $widgets = is_array($snapshot['flowWidgets'] ?? null) ? $snapshot['flowWidgets'] : [];
        $context = $this->formVisibilityContext($detail, $actor);
        $nodeId = $context['node_id'];
        $source = $context['source'];
        $legacyFallback = false;
        $readable = [];
        $detailChildren = [];

        if ($source === 'initiator') {
            $readable = $this->allFormFieldNames($formValue, $widgets);
        } else {
            $nodeConfig = $snapshot['nodeConfig'] ?? null;
            $node = is_array($nodeConfig) && $nodeId !== null
                ? $this->findNodeByIdentity($nodeConfig, $nodeId)
                : null;
            if ($node === null || !array_key_exists('formAuths', $node)) {
                $legacyFallback = true;
                $readable = $this->allFormFieldNames($formValue, $widgets);
            } else {
                [$readable, $detailChildren] = $this->readableFormFields(
                    is_array($node['formAuths']) ? $node['formAuths'] : []
                );
            }
        }

        $detail['form_value'] = $legacyFallback
            ? $formValue
            : $this->filterFormValue($formValue, $readable, $detailChildren);
        if (!$legacyFallback) {
            $snapshot['flowWidgets'] = $this->filterFormWidgets($widgets, $readable);
            $detail['definition_snapshot'] = $snapshot;
        }
        $detail['form_access'] = [
            'source' => $source,
            'node_id' => $nodeId,
            'readable_fields' => array_values(array_keys($readable)),
            'legacy_fallback' => $legacyFallback,
        ];
        return $detail;
    }

    private function formVisibilityContext(array $detail, ActorRef $actor): array
    {
        if ((string) ($detail['account_space'] ?? '') === $actor->accountSpace
            && (string) ($detail['account_id'] ?? '') === $actor->accountId) {
            return ['source' => 'initiator', 'node_id' => null];
        }

        $tasks = is_array($detail['tasks'] ?? null) ? array_reverse($detail['tasks']) : [];
        $latestTaskNodeId = null;
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $matchedAssignee = null;
            foreach ((array) ($task['assignees'] ?? []) as $assignee) {
                if (is_array($assignee) && $this->actorMatches($assignee, $actor)) {
                    $matchedAssignee = $assignee;
                    break;
                }
            }
            if ($matchedAssignee === null) {
                continue;
            }
            $latestTaskNodeId ??= (string) ($task['node_id'] ?? '');
            if ((int) ($task['task_status'] ?? -1) === 0
                && (int) ($matchedAssignee['action_status'] ?? -1) === 0) {
                return ['source' => 'task', 'node_id' => (string) ($task['node_id'] ?? '')];
            }
        }
        if ($latestTaskNodeId !== null && $latestTaskNodeId !== '') {
            return ['source' => 'task', 'node_id' => $latestTaskNodeId];
        }

        $logs = is_array($detail['logs'] ?? null) ? array_reverse($detail['logs']) : [];
        foreach ($logs as $log) {
            if (!is_array($log) || (int) ($log['operation_type'] ?? -1) !== FlowConstant::CMD['COPY']) {
                continue;
            }
            foreach ((array) ($log['assignee_snapshot'] ?? []) as $recipient) {
                if (is_array($recipient) && $this->actorMatches($recipient, $actor)) {
                    return ['source' => 'copy', 'node_id' => (string) ($log['node_id'] ?? '')];
                }
            }
        }
        return ['source' => 'participant', 'node_id' => null];
    }

    private function actorMatches(array $reference, ActorRef $actor): bool
    {
        return (string) ($reference['account_space'] ?? '') === $actor->accountSpace
            && (string) ($reference['account_id'] ?? '') === $actor->accountId;
    }

    private function allFormFieldNames(array $formValue, array $widgets): array
    {
        $fields = array_fill_keys(array_map('strval', array_keys($formValue)), true);
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $name = trim((string) ($widget['name'] ?? ''));
            if ($name !== '') {
                $fields[$name] = true;
            }
            foreach ((array) ($widget['details'] ?? []) as $child) {
                if (is_array($child) && trim((string) ($child['name'] ?? '')) !== '') {
                    $fields[(string) $child['name']] = true;
                }
            }
        }
        return $fields;
    }

    private function readableFormFields(array $formAuths): array
    {
        $readable = [];
        $detailChildren = [];
        foreach ($formAuths as $auth) {
            if (!is_array($auth)) {
                continue;
            }
            $name = trim((string) ($auth['name'] ?? ''));
            $children = is_array($auth['details'] ?? null) ? $auth['details'] : [];
            if ($children !== []) {
                foreach ($children as $child) {
                    if (!is_array($child) || !$this->permissionFlag($child['readable'] ?? false)) {
                        continue;
                    }
                    $childName = trim((string) ($child['name'] ?? ''));
                    if ($childName !== '') {
                        $readable[$childName] = true;
                        if ($name !== '') {
                            $detailChildren[$name][$childName] = true;
                        }
                    }
                }
                if ($name !== '' && isset($detailChildren[$name])) {
                    $readable[$name] = true;
                }
                continue;
            }
            if ($name !== '' && $this->permissionFlag($auth['readable'] ?? false)) {
                $readable[$name] = true;
            }
        }
        return [$readable, $detailChildren];
    }

    private function permissionFlag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function filterFormValue(array $formValue, array $readable, array $detailChildren): array
    {
        $filtered = [];
        foreach ($formValue as $name => $value) {
            $fieldName = (string) $name;
            if (!isset($readable[$fieldName])) {
                continue;
            }
            if (!isset($detailChildren[$fieldName]) || !is_array($value)) {
                $filtered[$fieldName] = $value;
                continue;
            }
            $rows = [];
            foreach ($value as $row) {
                if (is_array($row)) {
                    $rows[] = array_intersect_key($row, $detailChildren[$fieldName]);
                }
            }
            $filtered[$fieldName] = $rows;
        }
        return $filtered;
    }

    private function filterFormWidgets(array $widgets, array $readable): array
    {
        $filtered = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $name = (string) ($widget['name'] ?? '');
            if ($name === '' || !isset($readable[$name])) {
                continue;
            }
            if (is_array($widget['details'] ?? null)) {
                $widget['details'] = array_values(array_filter(
                    $widget['details'],
                    static fn (mixed $child): bool => is_array($child)
                        && isset($readable[(string) ($child['name'] ?? '')])
                ));
            }
            $filtered[] = $widget;
        }
        return $filtered;
    }

    protected function findNextTaskNode(FlowTask $task): ?array
    {
        return $this->findNextTaskRoute($task)['task'];
    }

    protected function findNextTaskRoute(FlowTask $task): array
    {
        $instance = FlowInstance::findOrEmpty((string) $task->getAttr('instance_id'));
        if ($instance->isEmpty()) {
            throw new ApiException('流程实例不存在');
        }
        $definitionJson = $instance->getAttr('definition_snapshot') ?: [];
        if ($definitionJson === []) {
            $definition = FlowDefinition::findOrEmpty((int) $task->getAttr('definition_id'));
            if ($definition->isEmpty()) {
                throw new ApiException('流程定义不存在');
            }
            $this->assertScope($instance);
            $definitionJson = $definition->getAttr('definition_json') ?: [];
        }
        $nodeConfig = $definitionJson['nodeConfig'] ?? null;
        if (!is_array($nodeConfig)) {
            return ['task' => null, 'copies' => []];
        }

        $currentNode = $this->findNodeByIdentity($nodeConfig, (string) $task->getAttr('node_id'));
        if (!$currentNode) {
            throw new ApiException('当前流程节点不存在');
        }

        $childNode = $currentNode['childNode'] ?? null;
        $formValue = (array) ($instance->getAttr('form_value') ?: []);
        return $this->resolveNextTaskRoute(is_array($childNode) ? $childNode : null, $formValue);
    }

    protected function resolveNextTaskNode(?array $node, array $formValue): ?array
    {
        return $this->resolveNextTaskRoute($node, $formValue)['task'];
    }

    protected function resolveNextTaskRoute(?array $node, array $formValue): array
    {
        if ($node === null) {
            return ['task' => null, 'copies' => []];
        }

        $type = (int) ($node['type'] ?? -1);
        if (in_array($type, [FlowConstant::NODE['APPROVE'], FlowConstant::NODE['TRANSACT']], true)) {
            return ['task' => $node, 'copies' => []];
        }
        if ($type === FlowConstant::NODE['COPY']) {
            $childNode = $node['childNode'] ?? null;
            $route = $this->resolveNextTaskRoute(
                is_array($childNode) ? $childNode : null,
                $formValue
            );
            array_unshift($route['copies'], $node);
            return $route;
        }

        if (isset($node['conditionNodes']) && is_array($node['conditionNodes'])) {
            foreach ($node['conditionNodes'] as $conditionNode) {
                if (!is_array($conditionNode)) {
                    continue;
                }
                if (!$this->matchesConditionBranch($conditionNode, $formValue)) {
                    continue;
                }
                return $this->resolveNextTaskRoute($conditionNode, $formValue);
            }
        }

        if (isset($node['childNode']) && is_array($node['childNode'])) {
            return $this->resolveNextTaskRoute($node['childNode'], $formValue);
        }

        return ['task' => null, 'copies' => []];
    }

    protected function findNodeByIdentity(array $node, string $nodeId): ?array
    {
        if ($this->nodeIdentity($node) === $nodeId) {
            return $node;
        }

        if (isset($node['childNode']) && is_array($node['childNode'])) {
            $matched = $this->findNodeByIdentity($node['childNode'], $nodeId);
            if ($matched) {
                return $matched;
            }
        }

        if (isset($node['conditionNodes']) && is_array($node['conditionNodes'])) {
            foreach ($node['conditionNodes'] as $conditionNode) {
                if (!is_array($conditionNode)) {
                    continue;
                }
                $matched = $this->findNodeByIdentity($conditionNode, $nodeId);
                if ($matched) {
                    return $matched;
                }
            }
        }

        return null;
    }

    protected function createPendingTask(
        string $instanceId,
        int $definitionId,
        array $node,
        ?ActorRef $selfActor = null
    ): void
    {
        $assigneeRefs = $this->extractAssigneeRefs($node, $selfActor);
        if ($assigneeRefs === []) {
            throw new ApiException('审批节点未配置有效处理人');
        }

        $task = FlowTask::create([
            'instance_id' => $instanceId,
            'definition_id' => $definitionId,
            'node_id' => $this->nodeIdentity($node),
            'node_name' => $node['name'] ?? '审批',
            'node_type' => (int) ($node['type'] ?? FlowConstant::NODE['APPROVE']),
            'sign_type' => (int) ($node['multiInstanceApprovalType'] ?? 0),
            'assignee_refs' => $assigneeRefs,
            'task_status' => 0,
        ]);
        foreach ($assigneeRefs as $index => $assignee) {
            FlowTaskAssignee::create([
                'task_id' => $task->getKey(),
                'instance_id' => $instanceId,
                'account_space' => $assignee['account_space'],
                'account_id' => $assignee['account_id'],
                'sequence_no' => $index + 1,
                'action_status' => 0,
            ]);
        }
    }

    protected function nodeIdentity(array $node): string
    {
        return (string) ($node['nodeId'] ?? $node['id'] ?? '');
    }

    protected function finishTask(FlowTask $task, int $operationType, string $comment, string $endedTime): void
    {
        $task->save([
            'operation_type' => $operationType,
            'comment' => $comment,
            'ended_time' => $endedTime,
            'task_status' => 1,
        ]);
        FlowTaskAssignee::where('task_id', $task->getKey())
            ->where('action_status', 0)
            ->update([
                'action_status' => 2,
                'ended_time' => $endedTime,
            ]);
    }

    protected function extractAssigneeRefs(array $node, ?ActorRef $selfActor = null): array
    {
        $items = $node['assignees'] ?? $node['transactors'] ?? [];
        $refs = [];
        foreach ((array) $items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['account_space'], $item['account_id'])) {
                $refs[] = (new ActorRef((string) $item['account_space'], (string) $item['account_id']))->toArray();
                continue;
            }
            if (isset($item['participant_key'])) {
                $participant = $this->participantsOverride[(string) $item['participant_key']] ?? null;
                if (is_array($participant)
                    && isset($participant['account_space'], $participant['account_id'])) {
                    $refs[] = (new ActorRef((string) $participant['account_space'], (string) $participant['account_id'], (array) ($participant['snapshot'] ?? [])))->toArray();
                }
                continue;
            }
            if (isset($item['id'])) {
                $refs[] = (new ActorRef('sandadmin', (string) $item['id']))->toArray();
                continue;
            }

            $type = (int) ($item['assigneeType'] ?? $item['transactorType'] ?? -1);
            if ($type === FlowConstant::ASSIGNEE['SELF']) {
                $refs[] = ($selfActor ?? $this->requireCurrentActor())->toArray();
            } elseif ($type === FlowConstant::ASSIGNEE['ROLE']) {
                $roleIds = array_values(array_filter(array_map('intval', (array) ($item['roles'] ?? []))));
                if ($roleIds !== []) {
                    foreach (SystemUserRole::whereIn('role_id', $roleIds)->column('user_id') as $userId) {
                        $refs[] = (new ActorRef('sandadmin', (string) $userId))->toArray();
                    }
                }
            } elseif ($type === FlowConstant::ASSIGNEE['ASSIGNEE']) {
                foreach ((array) ($item['assignees'] ?? $item['transactors'] ?? []) as $assignee) {
                    if (is_array($assignee) && isset($assignee['id'])) {
                        $refs[] = (new ActorRef('sandadmin', (string) $assignee['id']))->toArray();
                    }
                }
            } elseif ($type === FlowConstant::ASSIGNEE['INITIATOR_CHOICE']) {
                $choiceKey = trim((string) ($item['choiceKey'] ?? ''));
                $choices = $choiceKey === '' ? null : ($this->participantsOverride[$choiceKey] ?? null);
                if (!is_array($choices) || $choices === []) {
                    throw new ApiException('发起人自选处理人不能为空：' . $choiceKey);
                }
                foreach ($choices as $choice) {
                    if (!is_array($choice)
                        || !isset($choice['account_space'], $choice['account_id'])) {
                        throw new ApiException('发起人自选处理人格式无效：' . $choiceKey);
                    }
                    $refs[] = (new ActorRef(
                        (string) $choice['account_space'],
                        (string) $choice['account_id'],
                        (array) ($choice['snapshot'] ?? [])
                    ))->toArray();
                }
            } elseif (in_array($type, [
                FlowConstant::ASSIGNEE['DEPARTMENT_LEADER'],
                FlowConstant::ASSIGNEE['MULTISTEP_DEPARTMENT_LEADER'],
            ], true)) {
                $relationKey = trim((string) ($item['relationKey'] ?? ''));
                $participants = $relationKey === ''
                    ? null
                    : ($this->participantsOverride[$relationKey] ?? null);
                if (!is_array($participants) || $participants === []) {
                    throw new ApiException('组织关系处理人不能为空：' . $relationKey);
                }
                foreach ($participants as $participant) {
                    if (!is_array($participant)
                        || !isset($participant['account_space'], $participant['account_id'])) {
                        throw new ApiException('组织关系处理人格式无效：' . $relationKey);
                    }
                    $refs[] = (new ActorRef(
                        (string) $participant['account_space'],
                        (string) $participant['account_id'],
                        (array) ($participant['snapshot'] ?? [])
                    ))->toArray();
                }
            }
        }
        $unique = [];
        foreach ($refs as $ref) {
            $unique[$ref['account_space'] . ':' . $ref['account_id']] = $ref;
        }
        return array_values($unique);
    }

    protected function extractCopyRefs(array $node, ActorRef $initiator): array
    {
        if ($initiator->accountSpace !== 'sandadmin' || !ctype_digit($initiator->accountId)) {
            throw new ApiException('当前抄送节点仅支持SandAdmin接收人');
        }
        $userIds = [];
        foreach ((array) ($node['ccs'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (isset($rule['id'])) {
                $userIds[] = (int) $rule['id'];
                continue;
            }
            $type = (int) ($rule['ccType'] ?? -1);
            if ($type === FlowConstant::ASSIGNEE['SELF']) {
                $userIds[] = (int) $initiator->accountId;
            } elseif ($type === FlowConstant::ASSIGNEE['ROLE']) {
                $roleIds = array_values(array_filter(array_map('intval', (array) ($rule['roles'] ?? []))));
                if ($roleIds !== []) {
                    $userIds = array_merge(
                        $userIds,
                        array_map('intval', SystemUserRole::whereIn('role_id', $roleIds)->column('user_id'))
                    );
                }
            } elseif ($type === FlowConstant::ASSIGNEE['ASSIGNEE']) {
                foreach ((array) ($rule['assignees'] ?? []) as $assignee) {
                    $userIds[] = is_array($assignee)
                        ? (int) ($assignee['id'] ?? 0)
                        : (int) $assignee;
                }
            }
        }
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            throw new ApiException('抄送节点未解析到有效接收人');
        }
        $users = SystemUser::whereIn('id', $userIds)
            ->where('status', 1)
            ->select()
            ->toArray();
        $userMap = [];
        foreach ($users as $user) {
            $userMap[(int) $user['id']] = $user;
        }
        $refs = [];
        foreach ($userIds as $userId) {
            $user = $userMap[$userId] ?? null;
            if ($user === null) {
                continue;
            }
            $refs[] = (new ActorRef('sandadmin', (string) $userId, [
                'name' => (string) (($user['realname'] ?? '') ?: ($user['username'] ?? $userId)),
            ]))->toArray();
        }
        if ($refs === []) {
            throw new ApiException('抄送节点接收人不存在或已停用');
        }
        return $refs;
    }

    private function recordCopyNodes(FlowInstance $instance, array $copyNodes, ActorRef $operator): void
    {
        if ($copyNodes === []) {
            return;
        }
        $initiator = new ActorRef(
            (string) $instance->getAttr('account_space'),
            (string) $instance->getAttr('account_id')
        );
        foreach ($copyNodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $recipients = $this->extractCopyRefs($node, $initiator);
            FlowLog::create([
                'instance_id' => $instance->getKey(),
                'node_id' => $this->nodeIdentity($node),
                'node_name' => $node['name'] ?? '抄送',
                'operation_type' => FlowConstant::CMD['COPY'],
                'account_space' => $operator->accountSpace,
                'account_id' => $operator->accountId,
                'assignee_snapshot' => $recipients,
                'comment' => '流程抄送',
            ]);
        }
    }

    protected function replaceAssigneeRef(array $refs, ActorRef $source, ActorRef $target): array
    {
        $replaced = false;
        foreach ($refs as $index => $ref) {
            if (!is_array($ref)
                || (string) ($ref['account_space'] ?? '') !== $source->accountSpace
                || (string) ($ref['account_id'] ?? '') !== $source->accountId) {
                continue;
            }
            $refs[$index] = $target->toArray();
            $replaced = true;
        }
        if (!$replaced) {
            throw new ApiException('任务处理人快照与待办明细不一致');
        }
        return array_values($refs);
    }

    protected function appendAssigneeRef(array $refs, ActorRef $target): array
    {
        foreach ($refs as $ref) {
            if (is_array($ref)
                && (string) ($ref['account_space'] ?? '') === $target->accountSpace
                && (string) ($ref['account_id'] ?? '') === $target->accountId) {
                throw new ApiException('目标处理人已在当前任务中');
            }
        }
        $refs[] = $target->toArray();
        return array_values($refs);
    }

    protected function removeAssigneeRef(array $refs, ActorRef $target): array
    {
        $removed = false;
        $filtered = [];
        foreach ($refs as $ref) {
            if (!$removed
                && is_array($ref)
                && (string) ($ref['account_space'] ?? '') === $target->accountSpace
                && (string) ($ref['account_id'] ?? '') === $target->accountId) {
                $removed = true;
                continue;
            }
            $filtered[] = $ref;
        }
        if (!$removed) {
            throw new ApiException('任务处理人快照与待办明细不一致');
        }
        return array_values($filtered);
    }

    private function taskWithAssignees(string $taskId): array
    {
        $task = FlowTask::with('assignees')->findOrEmpty($taskId);
        if ($task->isEmpty()) {
            throw new ApiException('任务不存在');
        }
        return $task->toArray();
    }

    private function backHistoryNodes(FlowTask $task, FlowInstance $instance): array
    {
        $currentNodeId = (string) $task->getAttr('node_id');
        $logs = FlowLog::where('instance_id', (string) $instance->getKey())
            ->whereIn('operation_type', [
                FlowConstant::CMD['APPROVED'],
                FlowConstant::CMD['TRANSACT'],
            ])
            ->order('create_time', 'desc')
            ->select()
            ->toArray();
        $nodes = [];
        foreach ($logs as $log) {
            $nodeId = (string) ($log['node_id'] ?? '');
            if ($nodeId === '' || $nodeId === $currentNodeId || isset($nodes[$nodeId])) {
                continue;
            }
            $node = $this->instanceActionNode($instance, $nodeId);
            if ($node === null) {
                continue;
            }
            $nodes[$nodeId] = [
                'id' => $nodeId,
                'name' => (string) ($node['name'] ?? $log['node_name'] ?? '历史节点'),
                'node_type' => (int) ($node['type'] ?? FlowConstant::NODE['APPROVE']),
                'completed_time' => $log['create_time'] ?? null,
            ];
        }
        return array_values($nodes);
    }

    private function assertTaskBackable(FlowTask $task, FlowInstance $instance): array
    {
        $node = $this->instanceActionNode($instance, (string) $task->getAttr('node_id'));
        if ($node === null || (int) ($node['backable'] ?? 0) !== 1) {
            throw new ApiException('当前节点未启用回退');
        }
        return $node;
    }

    private function instanceActionNode(FlowInstance $instance, string $nodeId): ?array
    {
        $definitionJson = (array) ($instance->getAttr('definition_snapshot') ?: []);
        $nodeConfig = $definitionJson['nodeConfig'] ?? null;
        if (!is_array($nodeConfig)) {
            return null;
        }
        $node = $this->findNodeByIdentity($nodeConfig, $nodeId);
        if (!$node || !in_array((int) ($node['type'] ?? -1), [
            FlowConstant::NODE['APPROVE'],
            FlowConstant::NODE['TRANSACT'],
        ], true)) {
            return null;
        }
        return $node;
    }

    protected function matchesConditionBranch(array $branch, array $formValue): bool
    {
        $groups = $branch['conditionGroups'] ?? [];
        if (!is_array($groups) || $groups === []) {
            return true;
        }

        foreach ($groups as $group) {
            $conditions = is_array($group) ? ($group['conditions'] ?? []) : [];
            if (!is_array($conditions) || $conditions === []) {
                continue;
            }
            $matched = true;
            foreach ($conditions as $condition) {
                if (!is_array($condition) || !$this->matchesCondition($condition, $formValue)) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return true;
            }
        }

        return false;
    }

    protected function matchesCondition(array $condition, array $formValue): bool
    {
        $field = (string) ($condition['varName'] ?? '');
        if ($field === '' || !array_key_exists($field, $formValue)) {
            return false;
        }

        $actual = $formValue[$field];
        $expected = $condition['val'] ?? null;
        $operator = (int) ($condition['operator'] ?? -1);

        return match ($operator) {
            0 => (float) $actual === (float) $expected,
            1 => (float) $actual !== (float) $expected,
            2 => (float) $actual < (float) $expected,
            3 => (float) $actual <= (float) $expected,
            4 => (float) $actual > (float) $expected,
            5 => (float) $actual >= (float) $expected,
            10 => in_array((string) $actual, $this->conditionValues($expected), true),
            11 => !in_array((string) $actual, $this->conditionValues($expected), true),
            12 => (string) $actual === (string) $expected,
            13 => (string) $actual !== (string) $expected,
            14 => str_contains((string) $actual, (string) $expected),
            15 => !str_contains((string) $actual, (string) $expected),
            20 => array_intersect($this->conditionValues($actual), $this->conditionValues($expected)) !== [],
            21 => array_intersect($this->conditionValues($actual), $this->conditionValues($expected)) === [],
            default => false,
        };
    }

    protected function conditionValues(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_map('strval', $values));
    }

    protected function requireCurrentActor(): ActorRef
    {
        if ($this->actorOverride !== null) {
            return $this->actorOverride;
        }
        $info = function_exists('getCurrentInfo') ? getCurrentInfo() : null;
        $accountId = (string) ($info['id'] ?? '');
        if ($accountId === '') {
            throw new ApiException('请先登录后再操作流程');
        }
        return new ActorRef('sandadmin', $accountId);
    }

    private function withContext(ActorRef $actor, array $scope, array $participants, callable $callback): mixed
    {
        $previousActor = $this->actorOverride;
        $previousScope = $this->scopeOverride;
        $previousParticipants = $this->participantsOverride;
        $this->actorOverride = $actor;
        $this->scopeOverride = $scope;
        $this->participantsOverride = $participants;
        try {
            return $callback();
        } finally {
            $this->actorOverride = $previousActor;
            $this->scopeOverride = $previousScope;
            $this->participantsOverride = $previousParticipants;
        }
    }

    private function loadInstanceParticipants(FlowInstance $instance): void
    {
        $participants = $instance->getAttr('participants');
        $this->participantsOverride = is_array($participants) ? $participants : [];
    }

    private function assertScope(FlowInstance $instance): void
    {
        foreach ($this->scopeOverride as $field => $value) {
            if (in_array($field, ['tenant_id', 'owner_scope', 'business_type', 'business_id'], true)
                && (string) $instance->getAttr($field) !== (string) $value) {
                throw new ApiException('流程实例不属于当前业务作用域');
            }
        }
    }

    private function assertInstanceViewer(FlowInstance $instance): void
    {
        if ($this->monitoringOverride) {
            return;
        }
        $actor = $this->requireCurrentActor();
        if ((string) $instance->getAttr('account_space') === $actor->accountSpace
            && (string) $instance->getAttr('account_id') === $actor->accountId) {
            return;
        }

        $instanceId = (string) $instance->getKey();
        $assignee = FlowTaskAssignee::where('instance_id', $instanceId)
            ->where('account_space', $actor->accountSpace)
            ->where('account_id', $actor->accountId)
            ->findOrEmpty();
        if (!$assignee->isEmpty()) {
            return;
        }

        $copyLog = FlowLog::where('instance_id', $instanceId)
            ->where('operation_type', FlowConstant::CMD['COPY'])
            ->whereRaw(
                'assignee_snapshot @> ?::jsonb',
                [json_encode(['account_space' => $actor->accountSpace, 'account_id' => $actor->accountId], JSON_THROW_ON_ERROR)]
            )
            ->findOrEmpty();
        if (!$copyLog->isEmpty()) {
            return;
        }

        throw new ApiException('当前用户不是该流程的参与者');
    }

    protected function requestId(array $data): string
    {
        $requestId = trim((string) ($data['request_id'] ?? ''));
        if (strlen($requestId) > 64) {
            throw new ApiException('请求幂等键长度不能超过64个字符');
        }
        return $requestId;
    }

    protected function assertTaskAssignee(FlowTask $task): void
    {
        $actor = $this->requireCurrentActor();
        $assigned = FlowTaskAssignee::where('task_id', $task->getKey())
            ->where('account_space', $actor->accountSpace)
            ->where('account_id', $actor->accountId)
            ->where('action_status', 0)
            ->findOrEmpty();
        if ($assigned->isEmpty()) {
            throw new ApiException('当前用户不是该任务的处理人');
        }

        if ((int) $task->getAttr('sign_type') !== 3) {
            return;
        }

        $hasPreviousPending = FlowTaskAssignee::where('task_id', $task->getKey())
            ->where('sequence_no', '<', (int) $assigned->getAttr('sequence_no'))
            ->where('action_status', '<>', 1)
            ->findOrEmpty();
        if (!$hasPreviousPending->isEmpty()) {
            throw new ApiException('请等待前一位审批人处理完成');
        }
    }

    private function assertTaskSignable(FlowTask $task, FlowInstance $instance): void
    {
        if ((int) $task->getAttr('sign_type') === 3) {
            throw new ApiException('依次审批任务不支持加签/减签');
        }
        $definitionJson = (array) ($instance->getAttr('definition_snapshot') ?: []);
        $nodeConfig = $definitionJson['nodeConfig'] ?? null;
        $node = is_array($nodeConfig)
            ? $this->findNodeByIdentity($nodeConfig, (string) $task->getAttr('node_id'))
            : null;
        if (!$node || (int) ($node['signable'] ?? 0) !== 1) {
            throw new ApiException('当前节点未启用加签/减签');
        }
    }
}
