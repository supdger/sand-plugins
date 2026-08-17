<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\contract;

use InvalidArgumentException;
use plugin\sandworkflow\common\FlowConstant;

final class OrganizationResolutionRequest
{
    public function __construct(
        public readonly int $ruleType,
        public readonly ActorRef $initiator,
        public readonly string $tenantId,
        public readonly string $ownerScope,
        public readonly string $businessType,
        public readonly string $businessId,
        public readonly string $nodeId,
        public readonly array $rule,
    ) {
        if (!in_array($ruleType, [
            FlowConstant::ASSIGNEE['SUPERIOR'],
            FlowConstant::ASSIGNEE['DEPARTMENT_LEADER'],
            FlowConstant::ASSIGNEE['MULTISTEP_LEADER'],
            FlowConstant::ASSIGNEE['MULTISTEP_DEPARTMENT_LEADER'],
        ], true)) {
            throw new InvalidArgumentException('组织关系规则类型无效');
        }
        foreach ([$tenantId, $ownerScope, $businessType, $businessId] as $value) {
            if ($value === '' || strlen($value) > 64) {
                throw new InvalidArgumentException('组织关系解析作用域无效');
            }
        }
        if ($nodeId === '' || strlen($nodeId) > 128) {
            throw new InvalidArgumentException('组织关系解析节点无效');
        }
        self::assertRule($rule);
    }

    public static function assertRule(array $rule): void
    {
        $layerType = $rule['layerType'] ?? 0;
        $layer = $rule['layer'] ?? 0;
        if ((!is_int($layerType) && !is_string($layerType))
            || !ctype_digit((string) $layerType)
            || !in_array((int) $layerType, [0, 1], true)) {
            throw new InvalidArgumentException('组织关系层级方向无效');
        }
        if ((!is_int($layer) && !is_string($layer))
            || !ctype_digit((string) $layer)
            || (int) $layer > 20) {
            throw new InvalidArgumentException('组织关系层级范围无效');
        }
    }

    public function layerType(): int
    {
        return (int) ($this->rule['layerType'] ?? 0);
    }

    public function layer(): int
    {
        return (int) ($this->rule['layer'] ?? 0);
    }
}
