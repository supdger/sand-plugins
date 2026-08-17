<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\service;

use InvalidArgumentException;
use plugin\sandworkflow\app\contract\OrganizationResolutionRequest;
use plugin\sandworkflow\app\contract\WorkflowStartContext;
use plugin\sandworkflow\common\FlowConstant;

final class OrganizationParticipantMaterializer
{
    public function __construct(
        private readonly OrganizationParticipantRegistry $registry
    ) {
    }

    public function normalize(array $nodeConfig): array
    {
        $seenKeys = [];
        $this->collectChoiceKeys($nodeConfig, $seenKeys);
        $this->normalizeNode($nodeConfig, $seenKeys);
        return $nodeConfig;
    }

    public function materializeContext(
        array $nodeConfig,
        WorkflowStartContext $context
    ): WorkflowStartContext {
        $groups = [];
        $this->materializeNode($nodeConfig, $context, $groups);
        if ($groups === []) {
            return $context;
        }
        return new WorkflowStartContext(
            $context->tenantId,
            $context->ownerScope,
            $context->businessType,
            $context->businessId,
            $context->initiator,
            $context->participants,
            $context->requestId,
            $context->participantChoices,
            array_merge($context->participantGroups, $groups)
        );
    }

    private function collectChoiceKeys(array $node, array &$seenKeys): void
    {
        foreach (['assignees', 'transactors'] as $rulesKey) {
            foreach ((array) ($node[$rulesKey] ?? []) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $choiceKey = trim((string) ($rule['choiceKey'] ?? ''));
                if ($choiceKey !== '') {
                    $seenKeys[$choiceKey] = true;
                }
            }
        }
        if (is_array($node['childNode'] ?? null)) {
            $this->collectChoiceKeys($node['childNode'], $seenKeys);
        }
        foreach ((array) ($node['conditionNodes'] ?? []) as $branch) {
            if (is_array($branch)) {
                $this->collectChoiceKeys($branch, $seenKeys);
            }
        }
    }

    private function normalizeNode(array &$node, array &$seenKeys): void
    {
        [$rulesKey, $typeKey] = $this->ruleKeys($node);
        if ($rulesKey !== null && is_array($node[$rulesKey] ?? null)) {
            foreach ($node[$rulesKey] as $index => &$rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $ruleType = (int) ($rule[$typeKey] ?? -1);
                if (!$this->isOrganizationRule($ruleType)) {
                    continue;
                }
                OrganizationResolutionRequest::assertRule($rule);
                $nodeId = trim((string) ($node['nodeId'] ?? ''));
                if ($nodeId === '') {
                    throw new InvalidArgumentException('组织关系规则所在节点缺少节点ID');
                }
                $relationKey = trim((string) ($rule['relationKey'] ?? ''));
                if ($relationKey === '') {
                    $relationKey = 'relation_' . substr(
                        hash('sha256', $nodeId . ':' . $ruleType . ':' . $index),
                        0,
                        16
                    );
                }
                $this->assertRelationKey($relationKey);
                if (isset($seenKeys[$relationKey])) {
                    throw new InvalidArgumentException('组织关系键不能重复：' . $relationKey);
                }
                $seenKeys[$relationKey] = true;
                $rule['relationKey'] = $relationKey;
            }
            unset($rule);
        }

        if (is_array($node['childNode'] ?? null)) {
            $this->normalizeNode($node['childNode'], $seenKeys);
        }
        if (is_array($node['conditionNodes'] ?? null)) {
            foreach ($node['conditionNodes'] as &$branch) {
                if (is_array($branch)) {
                    $this->normalizeNode($branch, $seenKeys);
                }
            }
            unset($branch);
        }
    }

    private function materializeNode(
        array $node,
        WorkflowStartContext $context,
        array &$groups
    ): void {
        [$rulesKey, $typeKey] = $this->ruleKeys($node);
        if ($rulesKey !== null && is_array($node[$rulesKey] ?? null)) {
            foreach ($node[$rulesKey] as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $ruleType = (int) ($rule[$typeKey] ?? -1);
                if (!$this->isOrganizationRule($ruleType)) {
                    continue;
                }
                $relationKey = trim((string) ($rule['relationKey'] ?? ''));
                $this->assertRelationKey($relationKey);
                if (isset($groups[$relationKey])) {
                    throw new InvalidArgumentException('组织关系键不能重复：' . $relationKey);
                }
                if (array_key_exists($relationKey, $context->participantGroups)) {
                    $groups[$relationKey] = $context->participantGroups[$relationKey];
                    continue;
                }
                $actors = $this->registry->resolve(new OrganizationResolutionRequest(
                    $ruleType,
                    $context->initiator,
                    $context->tenantId,
                    $context->ownerScope,
                    $context->businessType,
                    $context->businessId,
                    (string) ($node['nodeId'] ?? ''),
                    $rule
                ));
                $groups[$relationKey] = array_map(
                    static fn ($actor): array => $actor->toArray(),
                    $actors
                );
            }
        }

        if (is_array($node['childNode'] ?? null)) {
            $this->materializeNode($node['childNode'], $context, $groups);
        }
        foreach ((array) ($node['conditionNodes'] ?? []) as $branch) {
            if (is_array($branch)) {
                $this->materializeNode($branch, $context, $groups);
            }
        }
    }

    /** @return array{0:?string,1:?string} */
    private function ruleKeys(array $node): array
    {
        return match ((int) ($node['type'] ?? -1)) {
            FlowConstant::NODE['APPROVE'] => ['assignees', 'assigneeType'],
            FlowConstant::NODE['TRANSACT'] => ['transactors', 'transactorType'],
            default => [null, null],
        };
    }

    private function isOrganizationRule(int $ruleType): bool
    {
        return in_array($ruleType, [
            FlowConstant::ASSIGNEE['SUPERIOR'],
            FlowConstant::ASSIGNEE['DEPARTMENT_LEADER'],
            FlowConstant::ASSIGNEE['MULTISTEP_LEADER'],
            FlowConstant::ASSIGNEE['MULTISTEP_DEPARTMENT_LEADER'],
        ], true);
    }

    private function assertRelationKey(string $relationKey): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $relationKey)) {
            throw new InvalidArgumentException('组织关系键格式无效');
        }
    }

}
