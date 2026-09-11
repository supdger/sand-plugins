<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\sandadmin\exception\ApiException;

/**
 * Enforces the second authorization stage against attributes extracted from a
 * loaded business entity. Request-body attributes never enter this guard.
 */
final class EntityScopeGuard
{
    private bool $completed = false;

    /**
     * @param \Closure(object):array<string,mixed> $attributeResolver
     * @param \Closure(array<string,mixed>):void $scopeAssertion
     */
    public function __construct(
        private readonly string $mode,
        private readonly \Closure $attributeResolver,
        private readonly \Closure $scopeAssertion,
    ) {}

    public function assertEntity(object $entity): void
    {
        if ($this->mode !== 'entity') {
            throw new ApiException('SAND_IAM_ENTITY_SCOPE_MODE_INVALID: 当前路由必须逐对象校验集合', 500);
        }
        $this->assertOne($entity);
        $this->completed = true;
    }

    /** @param iterable<object> $entities */
    public function assertCollection(iterable $entities): void
    {
        if ($this->mode !== 'collection') {
            throw new ApiException('SAND_IAM_ENTITY_SCOPE_MODE_INVALID: 当前路由只能校验单个实体', 500);
        }
        foreach ($entities as $entity) {
            if (!is_object($entity)) {
                throw new ApiException('SAND_IAM_ENTITY_SCOPE_ENTITY_INVALID: 集合必须由已加载的实体对象组成', 500);
            }
            $this->assertOne($entity);
        }
        $this->completed = true;
    }

    public function assertCompleted(): void
    {
        if (!$this->completed) {
            throw new ApiException('SAND_IAM_ENTITY_SCOPE_CHECK_REQUIRED: 已声明数据范围的路由必须在加载真实业务对象后复核', 500);
        }
    }

    private function assertOne(object $entity): void
    {
        $attributes = ($this->attributeResolver)($entity);
        if (!is_array($attributes) || array_is_list($attributes)) {
            throw new ApiException('SAND_IAM_ENTITY_SCOPE_ATTRIBUTES_INVALID: 实体属性解析器必须返回键值对象', 500);
        }
        ($this->scopeAssertion)($attributes);
    }
}
