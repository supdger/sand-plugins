<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\security\ServiceGrantConstraintNormalizer;
use plugin\sandadmin\exception\ApiException;

/** Controlled fact token produced only through the host-owned resolver registry. */
final readonly class ResolvedInvocationFacts
{
    private function __construct(
        public int $organizationId,
        public int $applicationId,
        public int $environmentId,
        public int $workloadClientId,
        public ?string $dataClass,
        public string $resourceType,
        public string $resourceRef,
    ) {}

    public static function resolve(
        ServiceInvocationFactResolverRegistry $registry,
        string $resolverCode,
        string $serviceCode,
        string $actionCode,
        string $resourceKey,
    ): self
    {
        $resolved = $registry->resolve($resolverCode, $serviceCode, $actionCode, $resourceKey);
        $dataClass = $resolved['data_class'];
        if (!is_string($resolved['resource_type']) || !is_string($resolved['resource_ref'])) {
            throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: 服务端资源事实不完整', 403);
        }
        $resourceType = trim($resolved['resource_type']);
        $resourceRef = trim($resolved['resource_ref']);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $resourceType) !== 1 || $resourceRef === '' || strlen($resourceRef) > 128) {
            throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: 服务端资源事实不完整', 403);
        }
        $scope = [];
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
            $value = $resolved[$field] ?? null;
            if (!is_int($value) || $value <= 0) {
                throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: 服务端资源所属范围无效', 403);
            }
            $scope[$field] = $value;
        }
        try {
            $dataClass = ServiceGrantConstraintNormalizer::dataClass($dataClass, true);
        } catch (ApiException) {
            throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: 服务端资源数据分级无效', 403);
        }
        return new self(
            $scope['organization_id'],
            $scope['application_id'],
            $scope['environment_id'],
            $scope['workload_client_id'],
            $dataClass,
            $resourceType,
            $resourceRef,
        );
    }
}
