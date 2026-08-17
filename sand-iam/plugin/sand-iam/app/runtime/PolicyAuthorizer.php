<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityRole;
use plugin\SandIam\app\model\Policy;
use plugin\SandIam\app\model\Resource;
use plugin\SandIam\app\service\AuditWriter;
use plugin\sandadmin\exception\ApiException;

final class PolicyAuthorizer
{
    private const OPERATIONS = ['list', 'read', 'create', 'update', 'delete', 'export', 'batch'];

    public function __construct(
        private readonly AuditWriter $auditWriter = new AuditWriter(),
        private readonly ScopeMatcher $scopeMatcher = new ScopeMatcher(),
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{allowed:bool,code:string,policy_ids:list<int>,scope:array<string,mixed>}
     */
    public function authorize(
        int $applicationId,
        int $identityId,
        string $resourceCode,
        string $action,
        string $operation,
        array $attributes,
        string $requestId,
    ): array {
        $requestId = $this->requestId($requestId);
        $this->assertOperation($operation);
        $identity = Identity::where('id', $identityId)->where('application_id', $applicationId)->where('status', 1)->find();
        $resource = Resource::where('application_id', $applicationId)->where('code', $resourceCode)->where('status', 1)->find();
        $application = Application::where('id', $applicationId)->where('status', 1)->find();
        if ($identity === null || $resource === null || $application === null || $action === '') {
            return $this->deny($application, $identityId, $resourceCode, $operation, $requestId, 'SAND_IAM_POLICY_DENIED');
        }

        $roleIds = $this->activeRoleIds($applicationId, $identityId);
        $policies = Policy::where('application_id', $applicationId)
            ->where('resource_id', (int) $resource->id)
            ->where('action', $action)
            ->where('state', 'published')
            ->where('status', 1)
            ->order('priority', 'asc')
            ->order('id', 'asc')
            ->select();

        $matched = [];
        $selectedPriority = null;
        foreach ($policies as $policy) {
            if (!$this->belongsToSubject($policy, $identityId, $roleIds) || !$this->scopeMatcher->matches((array) $policy->condition, $attributes)) {
                continue;
            }
            $priority = (int) $policy->priority;
            if ($selectedPriority !== null && $priority > $selectedPriority) {
                break;
            }
            $selectedPriority ??= $priority;
            $matched[] = $policy;
        }

        if ($matched === []) {
            return $this->deny($application, $identityId, $resourceCode, $operation, $requestId, 'SAND_IAM_POLICY_DENIED');
        }
        foreach ($matched as $policy) {
            if ($policy->effect === 'deny') {
                return $this->deny($application, $identityId, $resourceCode, $operation, $requestId, 'SAND_IAM_POLICY_DENIED', [(int) $policy->id]);
            }
        }

        $policy = $matched[0];
        $scope = (array) $policy->scope;
        if (!$this->validScope($scope)) {
            return $this->deny($application, $identityId, $resourceCode, $operation, $requestId, 'SAND_IAM_POLICY_DENIED', [(int) $policy->id]);
        }
        $result = ['allowed' => true, 'code' => 'allowed', 'policy_ids' => [(int) $policy->id], 'scope' => $scope];
        $this->auditWriter->write('identity', (string) $identityId, (int) $application->organization_id, $applicationId, 'authorize.' . $operation, $resourceCode, (int) $resource->id, 'allowed', $requestId, ['policy_ids' => $result['policy_ids'], 'scope' => $scope]);
        return $result;
    }

    /** @param array<string, mixed> $scope @param array<string, mixed> $attributes */
    public function assertScope(
        int $applicationId,
        int $identityId,
        string $resourceCode,
        string $operation,
        array $scope,
        array $attributes,
        string $requestId,
    ): void {
        $requestId = $this->requestId($requestId);
        $this->assertOperation($operation);
        $application = Application::where('id', $applicationId)->where('status', 1)->find();
        $resource = Resource::where('application_id', $applicationId)->where('code', $resourceCode)->where('status', 1)->find();
        if ($application === null || $resource === null || !$this->scopeMatcher->matches($scope, $attributes)) {
            $this->auditWriter->write('identity', (string) $identityId, $application ? (int) $application->organization_id : null, $applicationId ?: null, 'scope.' . $operation, $resourceCode, $resource ? (int) $resource->id : null, 'denied', $requestId, ['scope' => $scope]);
            throw new ApiException('SAND_IAM_RESOURCE_SCOPE_DENIED', 403);
        }
        $this->auditWriter->write('identity', (string) $identityId, (int) $application->organization_id, $applicationId, 'scope.' . $operation, $resourceCode, (int) $resource->id, 'allowed', $requestId, ['scope' => $scope]);
    }

    /** @return list<int> */
    private function activeRoleIds(int $applicationId, int $identityId): array
    {
        $roleIds = IdentityRole::alias('identity_role')
            ->join('sand_iam_role iam_role', 'iam_role.id = identity_role.role_id')
            ->where('identity_role.identity_id', $identityId)
            ->where('identity_role.status', 1)
            ->where('iam_role.application_id', $applicationId)
            ->where('iam_role.status', 1)
            ->column('iam_role.id');
        return array_values(array_map(static fn (mixed $roleId): int => (int) $roleId, $roleIds));
    }

    /** @param list<int> $roleIds */
    private function belongsToSubject(object $policy, int $identityId, array $roleIds): bool
    {
        return (int) $policy->identity_id === $identityId || ((int) $policy->role_id > 0 && in_array((int) $policy->role_id, $roleIds, true));
    }

    /** @param array<string, mixed> $scope */
    private function validScope(array $scope): bool
    {
        try {
            $this->scopeMatcher->assertValid($scope, 'policy scope');
            return true;
        } catch (ApiException) {
            return false;
        }
    }

    private function assertOperation(string $operation): void
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new ApiException('SAND_IAM_POLICY_DENIED: unsupported operation', 403);
        }
    }

    /** @param list<int> $policyIds @return array{allowed:false,code:string,policy_ids:list<int>,scope:array<string,mixed>} */
    private function deny(?Application $application, int $identityId, string $resourceCode, string $operation, string $requestId, string $code, array $policyIds = []): array
    {
        $this->auditWriter->write('identity', (string) $identityId, $application ? (int) $application->organization_id : null, $application ? (int) $application->id : null, 'authorize.' . $operation, $resourceCode ?: 'unknown', null, 'denied', $requestId, ['code' => $code, 'policy_ids' => $policyIds]);
        return ['allowed' => false, 'code' => $code, 'policy_ids' => $policyIds, 'scope' => []];
    }

    private function requestId(string $requestId): string
    {
        return $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16));
    }
}
