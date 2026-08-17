<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\logic;

use plugin\sandadmin\basic\think\BaseLogic;
use plugin\sandadmin\exception\ApiException;
use plugin\sandworkflow\app\model\FlowDefinition;
use plugin\sandworkflow\app\model\FlowDefinitionVersion;
use plugin\sandworkflow\app\contract\OrganizationResolutionRequest;
use plugin\sandworkflow\app\service\OrganizationParticipantMaterializer;
use plugin\sandworkflow\app\service\OrganizationParticipantRegistry;
use plugin\sandworkflow\common\FlowConstant;
use support\think\Db;

class DefinitionLogic extends BaseLogic
{
    public function __construct()
    {
        $this->model = new FlowDefinition();
        $this->orderField = 'id';
        $this->orderType = 'desc';
    }

    public function publish(array $data): mixed
    {
        $definition = $data['definition_json'] ?? [];
        if (is_string($definition)) {
            $decoded = json_decode($definition, true);
            $definition = is_array($decoded) ? $decoded : [];
        }
        $definition = $this->prepareDefinition($definition);

        $row = [
            'group_id' => $data['group_id'] ?? null,
            'name' => $data['name'] ?? '',
            'icon' => $data['icon'] ?? '',
            'description' => $data['description'] ?? '',
            'definition_json' => $definition,
            'permission_type' => $data['permission_type'] ?? 1,
            'is_cancelable' => $data['is_cancelable'] ?? 1,
            'publish_time' => date('Y-m-d H:i:s'),
            'status' => $data['status'] ?? 1,
        ];

        return Db::transaction(function () use ($data, $definition, $row) {
            $definitionId = (int) ($data['id'] ?? 0);
            if ($definitionId > 0) {
                $current = FlowDefinition::where('id', $definitionId)->lock(true)->findOrEmpty();
                if ($current->isEmpty()) {
                    throw new ApiException('流程定义不存在');
                }
                $versionNo = max(1, (int) $current->getAttr('current_version') + 1);
                $row['current_version'] = $versionNo;
                $this->edit($definitionId, $row);
            } else {
                $versionNo = 1;
                $row['current_version'] = $versionNo;
                $definitionId = (int) $this->add($row);
            }

            FlowDefinitionVersion::create([
                'definition_id' => $definitionId,
                'version_no' => $versionNo,
                'definition_json' => $definition,
                'published_by' => $this->currentUserId(),
                'publish_time' => $row['publish_time'],
            ]);

            return $definitionId;
        });
    }

    protected function prepareDefinition(array $definition): array
    {
        if (!isset($definition['nodeConfig']) || !is_array($definition['nodeConfig'])) {
            throw new ApiException('流程定义不能为空');
        }
        if ((int) ($definition['nodeConfig']['type'] ?? -1) !== FlowConstant::NODE['START']) {
            throw new ApiException('流程必须从发起节点开始');
        }
        $widgets = $definition['flowWidgets'] ?? [];
        if (!is_array($widgets)) {
            throw new ApiException('流程表单字段格式错误');
        }
        $fieldSchema = $this->formFieldSchema($widgets);
        $conditionFields = [];
        $this->collectConditionFields($definition['nodeConfig'], $conditionFields);
        $this->normalizeNodeFormAuths(
            $definition['nodeConfig'],
            $widgets,
            $fieldSchema,
            $conditionFields
        );
        $this->assignNodeIds($definition['nodeConfig']);
        $seenChoiceKeys = [];
        $this->normalizeInitiatorChoiceRules($definition['nodeConfig'], $seenChoiceKeys);
        try {
            $definition['nodeConfig'] = (new OrganizationParticipantMaterializer(
                new OrganizationParticipantRegistry([])
            ))->normalize($definition['nodeConfig']);
        } catch (\InvalidArgumentException $exception) {
            throw new ApiException($exception->getMessage());
        }
        $seenNodeIds = [];
        $hasActionNode = false;
        $this->validateNodeTree($definition['nodeConfig'], $seenNodeIds, $hasActionNode);
        if (!$hasActionNode) {
            throw new ApiException('流程至少需要一个审批或办理节点');
        }
        return $definition;
    }

    protected function formFieldSchema(array $widgets): array
    {
        $schema = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                throw new ApiException('流程表单字段格式错误');
            }
            $name = trim((string) ($widget['name'] ?? ''));
            if ($name === '') {
                throw new ApiException('流程表单字段名称不能为空');
            }
            if (isset($schema[$name])) {
                throw new ApiException('流程表单字段名称不能重复：' . $name);
            }
            $type = (int) ($widget['type'] ?? -1);
            $schema[$name] = ['type' => $type, 'parent' => null];
            foreach ((array) ($widget['details'] ?? []) as $child) {
                if (!is_array($child)) {
                    throw new ApiException('明细字段格式错误：' . $name);
                }
                $childName = trim((string) ($child['name'] ?? ''));
                if ($childName === '') {
                    throw new ApiException('明细字段名称不能为空：' . $name);
                }
                if (isset($schema[$childName])) {
                    throw new ApiException('流程表单字段名称不能重复：' . $childName);
                }
                $schema[$childName] = [
                    'type' => (int) ($child['type'] ?? -1),
                    'parent' => $name,
                ];
            }
        }
        return $schema;
    }

    protected function collectConditionFields(array $node, array &$conditionFields): void
    {
        foreach ((array) ($node['conditionGroups'] ?? []) as $group) {
            foreach ((array) (is_array($group) ? ($group['conditions'] ?? []) : []) as $condition) {
                if (is_array($condition) && trim((string) ($condition['varName'] ?? '')) !== '') {
                    $conditionFields[(string) $condition['varName']] = true;
                }
            }
        }
        if (is_array($node['childNode'] ?? null)) {
            $this->collectConditionFields($node['childNode'], $conditionFields);
        }
        foreach ((array) ($node['conditionNodes'] ?? []) as $branch) {
            if (is_array($branch)) {
                $this->collectConditionFields($branch, $conditionFields);
            }
        }
    }

    protected function normalizeNodeFormAuths(
        array &$node,
        array $widgets,
        array $fieldSchema,
        array $conditionFields
    ): void {
        $type = (int) ($node['type'] ?? -1);
        if (in_array($type, [
            FlowConstant::NODE['APPROVE'],
            FlowConstant::NODE['TRANSACT'],
            FlowConstant::NODE['COPY'],
        ], true)) {
            $node['formAuths'] = $this->normalizeFormAuths(
                $node['formAuths'] ?? [],
                $widgets,
                $fieldSchema,
                $conditionFields,
                (string) ($node['name'] ?? '未命名')
            );
        }
        if (is_array($node['childNode'] ?? null)) {
            $this->normalizeNodeFormAuths(
                $node['childNode'],
                $widgets,
                $fieldSchema,
                $conditionFields
            );
        }
        if (is_array($node['conditionNodes'] ?? null)) {
            foreach ($node['conditionNodes'] as &$branch) {
                if (is_array($branch)) {
                    $this->normalizeNodeFormAuths(
                        $branch,
                        $widgets,
                        $fieldSchema,
                        $conditionFields
                    );
                }
            }
            unset($branch);
        }
    }

    protected function normalizeFormAuths(
        mixed $rawAuths,
        array $widgets,
        array $fieldSchema,
        array $conditionFields,
        string $nodeName
    ): array {
        if (!is_array($rawAuths)) {
            throw new ApiException('节点“' . $nodeName . '”的表单权限格式错误');
        }
        $authMap = [];
        foreach ($rawAuths as $auth) {
            if (!is_array($auth)) {
                throw new ApiException('节点“' . $nodeName . '”的表单权限格式错误');
            }
            $name = trim((string) ($auth['name'] ?? ''));
            if ($name === '' || !isset($fieldSchema[$name]) || $fieldSchema[$name]['parent'] !== null) {
                throw new ApiException('节点“' . $nodeName . '”包含未知表单字段：' . $name);
            }
            if (isset($authMap[$name])) {
                throw new ApiException('节点“' . $nodeName . '”的表单权限字段不能重复：' . $name);
            }
            $authMap[$name] = $auth;
        }

        $normalized = [];
        foreach ($widgets as $widget) {
            $name = (string) $widget['name'];
            $auth = $authMap[$name] ?? [];
            $details = is_array($widget['details'] ?? null) ? $widget['details'] : [];
            $entry = array_merge($auth, [
                'name' => $name,
                'type' => (int) ($widget['type'] ?? -1),
                'label' => (string) ($widget['label'] ?? $name),
            ]);
            if ($details !== []) {
                $entry['details'] = $this->normalizeDetailFormAuths(
                    $auth['details'] ?? [],
                    $details,
                    $fieldSchema,
                    $conditionFields,
                    $nodeName,
                    $name
                );
                unset($entry['readable'], $entry['editable']);
            } else {
                [$entry['readable'], $entry['editable']] = $this->normalizedPermissionFlags(
                    $auth,
                    $fieldSchema[$name],
                    $conditionFields,
                    $nodeName,
                    $name
                );
                unset($entry['details']);
            }
            $normalized[] = $entry;
        }
        return $normalized;
    }

    private function normalizeDetailFormAuths(
        mixed $rawDetails,
        array $details,
        array $fieldSchema,
        array $conditionFields,
        string $nodeName,
        string $parentName
    ): array {
        if (!is_array($rawDetails)) {
            throw new ApiException('节点“' . $nodeName . '”的明细权限格式错误：' . $parentName);
        }
        $detailMap = [];
        foreach ($rawDetails as $detailAuth) {
            if (!is_array($detailAuth)) {
                throw new ApiException('节点“' . $nodeName . '”的明细权限格式错误：' . $parentName);
            }
            $name = trim((string) ($detailAuth['name'] ?? ''));
            if ($name === '' || ($fieldSchema[$name]['parent'] ?? null) !== $parentName) {
                throw new ApiException('节点“' . $nodeName . '”包含未知明细字段：' . $name);
            }
            if (isset($detailMap[$name])) {
                throw new ApiException('节点“' . $nodeName . '”的明细权限字段不能重复：' . $name);
            }
            $detailMap[$name] = $detailAuth;
        }

        $normalized = [];
        foreach ($details as $detail) {
            $name = (string) $detail['name'];
            $auth = $detailMap[$name] ?? [];
            [$readable, $editable] = $this->normalizedPermissionFlags(
                $auth,
                $fieldSchema[$name],
                $conditionFields,
                $nodeName,
                $name
            );
            $normalized[] = array_merge($auth, [
                'name' => $name,
                'type' => (int) ($detail['type'] ?? -1),
                'label' => (string) ($detail['label'] ?? $name),
                'readable' => $readable,
                'editable' => $editable,
            ]);
        }
        return $normalized;
    }

    private function normalizedPermissionFlags(
        array $auth,
        array $field,
        array $conditionFields,
        string $nodeName,
        string $fieldName
    ): array {
        $readable = $this->normalizedPermissionFlag($auth['readable'] ?? true, $nodeName, $fieldName);
        $editable = $this->normalizedPermissionFlag($auth['editable'] ?? false, $nodeName, $fieldName);
        if ($editable && !$readable) {
            throw new ApiException('节点“' . $nodeName . '”的可编辑字段必须同时可读：' . $fieldName);
        }
        if ($editable && ((int) $field['type'] === 23 || isset($conditionFields[$fieldName]))) {
            throw new ApiException('节点“' . $nodeName . '”的公式或条件字段不能编辑：' . $fieldName);
        }
        return [$readable, $editable];
    }

    private function normalizedPermissionFlag(mixed $value, string $nodeName, string $fieldName): bool
    {
        if (!in_array($value, [true, false, 1, 0, '1', '0'], true)) {
            throw new ApiException(
                '节点“' . $nodeName . '”的表单权限值无效：' . $fieldName
            );
        }
        return $value === true || $value === 1 || $value === '1';
    }

    public function versions(int $definitionId): array
    {
        if ($definitionId <= 0) {
            throw new ApiException('流程定义ID不能为空');
        }
        return FlowDefinitionVersion::where('definition_id', $definitionId)
            ->order('version_no', 'desc')
            ->select()
            ->toArray();
    }

    public function assignNodeIds(array &$nodeConfig): void
    {
        if (!isset($nodeConfig['id']) || $nodeConfig['id'] === '') {
            $nodeConfig['id'] = uniqid('node_', true);
        }
        if (!isset($nodeConfig['nodeId']) || $nodeConfig['nodeId'] === '') {
            $nodeType = (int) ($nodeConfig['type'] ?? FlowConstant::NODE['START']);
            $prefix = match ($nodeType) {
                FlowConstant::NODE['START'] => 'SE',
                FlowConstant::NODE['APPROVE'], FlowConstant::NODE['TRANSACT'] => 'UT',
                FlowConstant::NODE['COPY'] => 'CC',
                FlowConstant::NODE['CONDITION'] => 'CG',
                FlowConstant::NODE['EXCLUSIVE_GATEWAY'] => 'EG',
                FlowConstant::NODE['END'] => 'EE',
                default => 'ND',
            };
            $nodeConfig['nodeId'] = $prefix . uniqid();
        }

        if (isset($nodeConfig['childNode']) && is_array($nodeConfig['childNode'])) {
            $this->assignNodeIds($nodeConfig['childNode']);
        }
        if (isset($nodeConfig['conditionNodes']) && is_array($nodeConfig['conditionNodes'])) {
            foreach ($nodeConfig['conditionNodes'] as &$condition) {
                if (is_array($condition)) {
                    $this->assignNodeIds($condition);
                }
            }
        }
    }

    protected function normalizeInitiatorChoiceRules(array &$node, array &$seenChoiceKeys): void
    {
        $type = (int) ($node['type'] ?? -1);
        if (in_array($type, [FlowConstant::NODE['APPROVE'], FlowConstant::NODE['TRANSACT']], true)) {
            $rulesKey = $type === FlowConstant::NODE['APPROVE'] ? 'assignees' : 'transactors';
            $typeKey = $type === FlowConstant::NODE['APPROVE'] ? 'assigneeType' : 'transactorType';
            $rules = is_array($node[$rulesKey] ?? null) ? $node[$rulesKey] : [];
            $choiceIndexes = [];
            foreach ($rules as $index => $rule) {
                if (is_array($rule)
                    && (int) ($rule[$typeKey] ?? -1) === FlowConstant::ASSIGNEE['INITIATOR_CHOICE']) {
                    $choiceIndexes[] = $index;
                }
            }
            if ($choiceIndexes !== []) {
                $nodeName = (string) ($node['name'] ?? '未命名');
                if (count($rules) !== 1 || count($choiceIndexes) !== 1) {
                    throw new ApiException('节点“' . $nodeName . '”的发起人自选不能与其他处理人规则混用');
                }
                $index = $choiceIndexes[0];
                $choiceKey = trim((string) ($rules[$index]['choiceKey'] ?? ''));
                if ($choiceKey === '') {
                    $choiceKey = 'choice_' . substr(
                        hash('sha256', (string) ($node['nodeId'] ?? $node['id'] ?? '')),
                        0,
                        16
                    );
                }
                if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $choiceKey)) {
                    throw new ApiException('节点“' . $nodeName . '”的发起人自选标识格式无效');
                }
                if (isset($seenChoiceKeys[$choiceKey])) {
                    throw new ApiException('发起人自选标识不能重复：' . $choiceKey);
                }
                $seenChoiceKeys[$choiceKey] = true;
                $node[$rulesKey][$index]['choiceKey'] = $choiceKey;
            }
        }

        if (is_array($node['childNode'] ?? null)) {
            $this->normalizeInitiatorChoiceRules($node['childNode'], $seenChoiceKeys);
        }
        if (is_array($node['conditionNodes'] ?? null)) {
            foreach ($node['conditionNodes'] as &$branch) {
                if (is_array($branch)) {
                    $this->normalizeInitiatorChoiceRules($branch, $seenChoiceKeys);
                }
            }
            unset($branch);
        }
    }

    protected function validateNodeTree(
        array $node,
        array &$seenNodeIds,
        bool &$hasActionNode,
        int $depth = 0
    ): void {
        if ($depth > 100) {
            throw new ApiException('流程节点层级不能超过100级');
        }

        $type = (int) ($node['type'] ?? -1);
        $supportedTypes = [
            FlowConstant::NODE['START'],
            FlowConstant::NODE['APPROVE'],
            FlowConstant::NODE['COPY'],
            FlowConstant::NODE['CONDITION'],
            FlowConstant::NODE['EXCLUSIVE_GATEWAY'],
            FlowConstant::NODE['TRANSACT'],
            FlowConstant::NODE['END'],
        ];
        if (!in_array($type, $supportedTypes, true)) {
            throw new ApiException('流程中包含当前版本不支持的节点类型：' . $type);
        }

        $nodeId = (string) ($node['nodeId'] ?? '');
        if ($nodeId === '' || isset($seenNodeIds[$nodeId])) {
            throw new ApiException($nodeId === '' ? '流程节点ID不能为空' : '流程节点ID不能重复：' . $nodeId);
        }
        $seenNodeIds[$nodeId] = true;

        if (in_array($type, [FlowConstant::NODE['APPROVE'], FlowConstant::NODE['TRANSACT']], true)) {
            $hasActionNode = true;
            $key = $type === FlowConstant::NODE['APPROVE'] ? 'assignees' : 'transactors';
            if (empty($node[$key]) || !is_array($node[$key])) {
                throw new ApiException('节点“' . ($node['name'] ?? '未命名') . '”未配置处理人规则');
            }
            $this->validateAssigneeRules(
                $node[$key],
                (string) ($node['name'] ?? '未命名'),
                $type === FlowConstant::NODE['TRANSACT']
            );
            $signType = (int) ($node['multiInstanceApprovalType'] ?? 0);
            if (!in_array($signType, [0, 1, 2, 3], true)) {
                throw new ApiException('节点“' . ($node['name'] ?? '未命名') . '”的审批方式不受支持');
            }
            if ($signType === 0 && $this->hasInitiatorChoiceRule(
                $node[$key],
                $type === FlowConstant::NODE['TRANSACT']
            )) {
                throw new ApiException(
                    '节点“' . ($node['name'] ?? '未命名') . '”的发起人自选必须配置多人处理方式'
                );
            }
            if ($signType !== 3 && $this->hasContinuousDepartmentLeaderRule(
                $node[$key],
                $type === FlowConstant::NODE['TRANSACT']
            )) {
                throw new ApiException(
                    '节点“' . ($node['name'] ?? '未命名') . '”的连续多级部门负责人必须配置依次审批'
                );
            }
        }
        if ($type === FlowConstant::NODE['COPY']) {
            $ccs = $node['ccs'] ?? [];
            if (!is_array($ccs) || $ccs === []) {
                throw new ApiException('节点“' . ($node['name'] ?? '未命名') . '”未配置抄送人规则');
            }
            $this->validateCopyRules($ccs, (string) ($node['name'] ?? '未命名'));
        }

        if ($type === FlowConstant::NODE['EXCLUSIVE_GATEWAY']) {
            $branches = $node['conditionNodes'] ?? [];
            if (!is_array($branches) || count($branches) < 2) {
                throw new ApiException('排他网关至少需要两个条件分支');
            }
            foreach ($branches as $index => $branch) {
                if (!is_array($branch)) {
                    throw new ApiException('条件分支格式错误');
                }
                $isDefault = empty($branch['conditionGroups']);
                if ($isDefault && $index !== array_key_last($branches)) {
                    throw new ApiException('默认条件分支必须放在最后');
                }
                if (!$isDefault) {
                    $this->validateConditionGroups($branch['conditionGroups']);
                }
            }
        }

        if (isset($node['childNode']) && is_array($node['childNode'])) {
            $this->validateNodeTree($node['childNode'], $seenNodeIds, $hasActionNode, $depth + 1);
        }
        if (isset($node['conditionNodes']) && is_array($node['conditionNodes'])) {
            foreach ($node['conditionNodes'] as $branch) {
                if (is_array($branch)) {
                    $this->validateNodeTree($branch, $seenNodeIds, $hasActionNode, $depth + 1);
                }
            }
        }
    }

    protected function validateAssigneeRules(array $rules, string $nodeName, bool $isTransactor): void
    {
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                throw new ApiException('节点“' . $nodeName . '”的处理人规则格式错误');
            }
            if (isset($rule['account_space'], $rule['account_id'])
                || isset($rule['participant_key'])
                || isset($rule['id'])) {
                continue;
            }

            $typeField = $isTransactor ? 'transactorType' : 'assigneeType';
            $type = (int) ($rule[$typeField] ?? -1);
            if ($type === FlowConstant::ASSIGNEE['SELF']) {
                continue;
            }
            if ($type === FlowConstant::ASSIGNEE['ROLE']) {
                $roleIds = array_values(array_filter(array_map('intval', (array) ($rule['roles'] ?? []))));
                if ($roleIds === []) {
                    throw new ApiException('节点“' . $nodeName . '”的角色审批未选择有效角色');
                }
                continue;
            }
            if ($type === FlowConstant::ASSIGNEE['ASSIGNEE']) {
                $assigneeKey = $isTransactor ? 'transactors' : 'assignees';
                $assigneeIds = array_values(array_filter(array_map(
                    static fn (mixed $assignee): int => is_array($assignee)
                        ? (int) ($assignee['id'] ?? 0)
                        : (int) $assignee,
                    (array) ($rule[$assigneeKey] ?? [])
                )));
                if ($assigneeIds === []) {
                    throw new ApiException('节点“' . $nodeName . '”未选择有效处理人');
                }
                continue;
            }
            if ($type === FlowConstant::ASSIGNEE['INITIATOR_CHOICE']) {
                $choiceKey = trim((string) ($rule['choiceKey'] ?? ''));
                if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $choiceKey)) {
                    throw new ApiException('节点“' . $nodeName . '”的发起人自选标识格式无效');
                }
                continue;
            }
            if (in_array($type, [
                FlowConstant::ASSIGNEE['DEPARTMENT_LEADER'],
                FlowConstant::ASSIGNEE['MULTISTEP_DEPARTMENT_LEADER'],
            ], true)) {
                try {
                    OrganizationResolutionRequest::assertRule($rule);
                } catch (\InvalidArgumentException $exception) {
                    throw new ApiException(
                        '节点“' . $nodeName . '”的组织关系规则无效：' . $exception->getMessage()
                    );
                }
                $relationKey = trim((string) ($rule['relationKey'] ?? ''));
                if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $relationKey)) {
                    throw new ApiException('节点“' . $nodeName . '”的组织关系标识格式无效');
                }
                continue;
            }

            throw new ApiException(
                '节点“' . $nodeName . '”的处理人规则当前版本尚未接入：' . $type
            );
        }
    }

    private function hasInitiatorChoiceRule(array $rules, bool $isTransactor): bool
    {
        $typeField = $isTransactor ? 'transactorType' : 'assigneeType';
        foreach ($rules as $rule) {
            if (is_array($rule)
                && (int) ($rule[$typeField] ?? -1) === FlowConstant::ASSIGNEE['INITIATOR_CHOICE']) {
                return true;
            }
        }
        return false;
    }

    private function hasContinuousDepartmentLeaderRule(array $rules, bool $isTransactor): bool
    {
        $typeField = $isTransactor ? 'transactorType' : 'assigneeType';
        foreach ($rules as $rule) {
            if (is_array($rule)
                && (int) ($rule[$typeField] ?? -1)
                    === FlowConstant::ASSIGNEE['MULTISTEP_DEPARTMENT_LEADER']) {
                return true;
            }
        }
        return false;
    }

    protected function validateCopyRules(array $rules, string $nodeName): void
    {
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                throw new ApiException('节点“' . $nodeName . '”的抄送人规则格式错误');
            }
            if (isset($rule['id'])) {
                if ((int) $rule['id'] <= 0) {
                    throw new ApiException('节点“' . $nodeName . '”未选择有效抄送人');
                }
                continue;
            }
            $type = (int) ($rule['ccType'] ?? -1);
            if ($type === FlowConstant::ASSIGNEE['SELF']) {
                continue;
            }
            if ($type === FlowConstant::ASSIGNEE['ROLE']) {
                $roleIds = array_values(array_filter(array_map('intval', (array) ($rule['roles'] ?? []))));
                if ($roleIds === []) {
                    throw new ApiException('节点“' . $nodeName . '”的角色抄送未选择有效角色');
                }
                continue;
            }
            if ($type === FlowConstant::ASSIGNEE['ASSIGNEE']) {
                $assigneeIds = array_values(array_filter(array_map(
                    static fn (mixed $assignee): int => is_array($assignee)
                        ? (int) ($assignee['id'] ?? 0)
                        : (int) $assignee,
                    (array) ($rule['assignees'] ?? [])
                )));
                if ($assigneeIds === []) {
                    throw new ApiException('节点“' . $nodeName . '”未选择有效抄送人');
                }
                continue;
            }
            throw new ApiException(
                '节点“' . $nodeName . '”的抄送人规则当前版本尚未接入：' . $type
            );
        }
    }

    protected function validateConditionGroups(array $groups): void
    {
        $operators = [0, 1, 2, 3, 4, 5, 10, 11, 12, 13, 14, 15, 20, 21];
        foreach ($groups as $group) {
            $conditions = is_array($group) ? ($group['conditions'] ?? []) : [];
            if (!is_array($conditions) || $conditions === []) {
                throw new ApiException('条件组不能为空');
            }
            foreach ($conditions as $condition) {
                if (!is_array($condition) || empty($condition['varName'])) {
                    throw new ApiException('条件字段不能为空');
                }
                if (!in_array((int) ($condition['operator'] ?? -1), $operators, true)) {
                    throw new ApiException('条件操作符不受支持');
                }
            }
        }
    }

    protected function currentUserId(): int
    {
        $info = function_exists('getCurrentInfo') ? getCurrentInfo() : null;
        return (int) ($info['id'] ?? 0);
    }
}
