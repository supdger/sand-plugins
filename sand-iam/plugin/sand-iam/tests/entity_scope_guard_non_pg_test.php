<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace {
    require_once dirname(__DIR__) . '/app/runtime/EntityScopeGuard.php';

    use plugin\SandIam\app\runtime\EntityScopeGuard;
    use plugin\sandadmin\exception\ApiException;

    final class MatterRecord
    {
        public function __construct(public int $organizationId, public int $ownerIdentityId) {}
    }
    final class SandAiRunRecord
    {
        public function __construct(public int $organizationId, public int $requesterIdentityId) {}
    }
    final class MatterCreateParent
    {
        public function __construct(public int $organizationId, public int $ownerIdentityId) {}
    }
    function entityScopeAssert(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    }
    /** @param array<string,mixed> $attributes */
    function entityScopeEquals(array $attributes, int $organizationId, int $identityId): void
    {
        if (($attributes['organization_id'] ?? null) !== $organizationId || ($attributes['owner_identity_id'] ?? null) !== $identityId) {
            throw new ApiException('SAND_IAM_RESOURCE_SCOPE_DENIED', 403);
        }
    }

    // 非 AI 资源：请求体伪造 owner 不会传入守卫，只有已加载 MatterRecord 的字段可参与判定。
    $matterGuard = new EntityScopeGuard(
        'entity',
        static fn (object $matter): array => ['organization_id' => $matter->organizationId, 'owner_identity_id' => $matter->ownerIdentityId],
        static function (array $attributes): void { entityScopeEquals($attributes, 42, 101); },
    );
    $matterGuard->assertEntity(new MatterRecord(42, 101));
    $matterGuard->assertCompleted();
    $forgedRequestAttributes = ['organization_id' => 42, 'owner_identity_id' => 101];
    try {
        $matterGuard = new EntityScopeGuard(
            'entity',
            static fn (object $matter): array => ['organization_id' => $matter->organizationId, 'owner_identity_id' => $matter->ownerIdentityId],
            static function (array $attributes): void { entityScopeEquals($attributes, 42, 101); },
        );
        $matterGuard->assertEntity(new MatterRecord(43, 999));
        entityScopeAssert(false, 'cross-organization Matter was accepted through forged request attributes');
    } catch (ApiException $exception) {
        entityScopeAssert($exception->getMessage() === 'SAND_IAM_RESOURCE_SCOPE_DENIED' && $forgedRequestAttributes['owner_identity_id'] === 101, 'cross-organization Matter did not fail closed');
    }

    // 批量修改：每个真实对象都必须通过，部分越权时不会完成守卫。
    $batchGuard = new EntityScopeGuard(
        'collection',
        static fn (object $matter): array => ['organization_id' => $matter->organizationId, 'owner_identity_id' => $matter->ownerIdentityId],
        static function (array $attributes): void { entityScopeEquals($attributes, 42, 101); },
    );
    try {
        $batchGuard->assertCollection([new MatterRecord(42, 101), new MatterRecord(42, 202)]);
        entityScopeAssert(false, 'partially unauthorized batch was accepted');
    } catch (ApiException $exception) {
        entityScopeAssert($exception->getMessage() === 'SAND_IAM_RESOURCE_SCOPE_DENIED', 'partially unauthorized batch used the wrong error');
    }
    try {
        $batchGuard->assertCompleted();
        entityScopeAssert(false, 'failed batch was marked as completed');
    } catch (ApiException $exception) {
        entityScopeAssert(str_starts_with($exception->getMessage(), 'SAND_IAM_ENTITY_SCOPE_CHECK_REQUIRED'), 'failed batch did not retain the missing-check state');
    }

    // 新建记录尚不存在时，必须先从可信父资源取得归属；父资源越权时 handler 不得被调用。
    $createHandlerCalls = 0;
    $createGuard = new EntityScopeGuard(
        'entity',
        static fn (object $parent): array => ['organization_id' => $parent->organizationId, 'owner_identity_id' => $parent->ownerIdentityId],
        static function (array $attributes): void { entityScopeEquals($attributes, 42, 101); },
    );
    $createGuard->assertEntity(new MatterCreateParent(42, 101));
    $createHandlerCalls++;
    try {
        $createGuard = new EntityScopeGuard(
            'entity',
            static fn (object $parent): array => ['organization_id' => $parent->organizationId, 'owner_identity_id' => $parent->ownerIdentityId],
            static function (array $attributes): void { entityScopeEquals($attributes, 42, 101); },
        );
        $forgedCreateBody = ['organization_id' => 42, 'owner_identity_id' => 101];
        $createGuard->assertEntity(new MatterCreateParent(43, 999));
        $createHandlerCalls++;
        entityScopeAssert(false, 'cross-organization create parent was accepted');
    } catch (ApiException $exception) {
        entityScopeAssert($exception->getMessage() === 'SAND_IAM_RESOURCE_SCOPE_DENIED' && $createHandlerCalls === 1 && $forgedCreateBody['owner_identity_id'] === 101, 'create scope failure reached the handler or trusted the body');
    }

    // SandAI 运行接口同样只接受运行记录的真实归属，不能信任调用方提交的 owner 字段。
    $sandAiGuard = new EntityScopeGuard(
        'entity',
        static fn (object $run): array => ['organization_id' => $run->organizationId, 'owner_identity_id' => $run->requesterIdentityId],
        static function (array $attributes): void { entityScopeEquals($attributes, 42, 101); },
    );
    $sandAiGuard->assertEntity(new SandAiRunRecord(42, 101));
    try {
        $sandAiGuard = new EntityScopeGuard(
            'entity',
            static fn (object $run): array => ['organization_id' => $run->organizationId, 'owner_identity_id' => $run->requesterIdentityId],
            static function (array $attributes): void { entityScopeEquals($attributes, 42, 101); },
        );
        $sandAiGuard->assertEntity(new SandAiRunRecord(42, 999));
        entityScopeAssert(false, 'SandAI run with another requester was accepted');
    } catch (ApiException $exception) {
        entityScopeAssert($exception->getMessage() === 'SAND_IAM_RESOURCE_SCOPE_DENIED', 'SandAI run scope denial is not stable');
    }

    echo "entity scope guard non-PG checks passed\n";
}
