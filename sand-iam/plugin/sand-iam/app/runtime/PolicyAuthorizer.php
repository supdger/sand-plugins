<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityGroupMember;
use plugin\SandIam\app\model\IdentityRole;
use plugin\SandIam\app\model\Policy;
use plugin\SandIam\app\model\PolicyVersion;
use plugin\SandIam\app\model\Resource;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
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

        $effectiveRoles = $this->effectiveRoles($applicationId, $identityId);
        $roleIds = $effectiveRoles['role_ids'];
        $policies = Policy::where('application_id', $applicationId)
            ->where('status', 1)
            ->where('published_version_id', '>', 0)
            ->order('id', 'asc')
            ->select();

        $matched = [];
        foreach ($policies as $policy) {
            $runtimePolicy = $this->publishedSnapshot($policy);
            if ($runtimePolicy === null || !$this->snapshotTargets($runtimePolicy, $applicationId, (int) $resource->id, $action) || !$this->belongsToSubject($runtimePolicy, $identityId, $roleIds) || !$this->scopeMatcher->matches((array) $runtimePolicy->condition, $attributes)) {
                continue;
            }
            $matched[] = $runtimePolicy;
        }

        $matched = $this->selectLowestPriorityRules($matched);

        if ($matched === []) {
            return $this->deny($application, $identityId, $resourceCode, $operation, $requestId, 'SAND_IAM_POLICY_DENIED', [], ['effective_role_sources' => $effectiveRoles['sources']]);
        }
        foreach ($matched as $policy) {
            if ($policy->effect === 'deny') {
                return $this->deny($application, $identityId, $resourceCode, $operation, $requestId, 'SAND_IAM_POLICY_DENIED', [(int) $policy->id], ['subject_source' => $this->subjectSource($policy, $identityId, $effectiveRoles['sources'])]);
            }
        }

        $policy = $matched[0];
        $scope = (array) $policy->scope;
        if (!$this->validScope($scope)) {
            return $this->deny($application, $identityId, $resourceCode, $operation, $requestId, 'SAND_IAM_POLICY_DENIED', [(int) $policy->id], ['subject_source' => $this->subjectSource($policy, $identityId, $effectiveRoles['sources'])]);
        }
        $result = ['allowed' => true, 'code' => 'allowed', 'policy_ids' => [(int) $policy->id], 'scope' => $scope];
        $this->auditWriter->write('identity', (string) $identityId, (int) $application->organization_id, $applicationId, 'authorize.' . $operation, $resourceCode, (int) $resource->id, 'allowed', $requestId, ['policy_ids' => $result['policy_ids'], 'published_version_ids' => [(int) $policy->published_version_id], 'scope' => $scope, 'subject_source' => $this->subjectSource($policy, $identityId, $effectiveRoles['sources'])]);
        return $result;
    }

    /** Read-only policy simulation. It never calls AuditWriter or changes authorization state. @param array<string,mixed> $attributes @return array<string,mixed> */
    public function simulate(int $applicationId, int $identityId, string $resourceCode, string $action, string $operation, array $attributes, string $requestId): array
    {
        $requestId = $this->requestId($requestId); $this->assertOperation($operation);
        $application = Application::where('id', $applicationId)->where('status', 1)->find();
        $identity = Identity::where('id', $identityId)->where('application_id', $applicationId)->where('status', 1)->find();
        $resource = Resource::where('application_id', $applicationId)->where('code', $resourceCode)->where('status', 1)->find();
        if ($application === null || $identity === null || $resource === null || $action === '') return ['request_id' => $requestId, 'allowed' => false, 'code' => 'SAND_IAM_POLICY_DENIED', 'matched_rules' => [], 'missing_context' => [], 'final_reason' => '应用、身份、资源或动作不可用'];
        $effectiveRoles = $this->effectiveRoles($applicationId, $identityId); $roles = $effectiveRoles['role_ids']; $rules = []; $selected = [];
        foreach (Policy::where('application_id', $applicationId)->where('status', 1)->where('published_version_id', '>', 0)->order('id', 'asc')->select() as $policy) {
            $runtime = $this->publishedSnapshot($policy); if ($runtime === null) continue;
            if (!$this->snapshotTargets($runtime, $applicationId, (int) $resource->id, $action)) continue;
            $subject = $this->belongsToSubject($runtime, $identityId, $roles); $missing = $this->missingContext((array) $runtime->condition, $attributes); $condition = $subject && $this->scopeMatcher->matches((array) $runtime->condition, $attributes);
            $rules[] = ['policy_id' => (int) $runtime->id, 'published_version_id' => (int) $runtime->published_version_id, 'effect' => (string) $runtime->effect, 'priority' => (int) $runtime->priority, 'condition' => $this->redact((array) $runtime->condition), 'condition_matched' => $condition, 'subject_source' => $this->subjectSource($runtime, $identityId, $effectiveRoles['sources']), 'scope_source' => 'published_version_snapshot', 'data_scope' => $this->redact((array) $runtime->scope), 'missing_context' => $missing];
            if ($condition) { $selected[] = $runtime; }
        }
        usort($rules, static fn (array $left, array $right): int => [$left['priority'], $left['policy_id']] <=> [$right['priority'], $right['policy_id']]);
        $selected = $this->selectLowestPriorityRules($selected);
        $deny = array_filter($selected, static fn (object $rule): bool => $rule->effect === 'deny'); $allow = $selected === [] ? null : $selected[0];
        $allowed = $deny === [] && $allow !== null && $this->validScope((array) $allow->scope);
        return ['request_id' => $requestId, 'allowed' => $allowed, 'code' => $allowed ? 'allowed' : 'SAND_IAM_POLICY_DENIED', 'matched_rules' => $rules, 'effective_role_sources' => $effectiveRoles['sources'], 'missing_context' => array_values(array_unique(array_merge(...array_map(static fn (array $rule): array => $rule['missing_context'] ?? [], $rules ?: [['missing_context' => []]])))), 'final_reason' => $allowed ? '命中已发布允许规则' : ($deny !== [] ? '同优先级拒绝规则优先' : '未命中有效允许规则')];
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

    /** @return array{role_ids:list<int>,sources:array<int,list<string>>} */
    private function effectiveRoles(int $applicationId, int $identityId): array
    {
        $sources = [];
        foreach (IdentityRole::alias('identity_role')
            ->join('sand_iam_role iam_role', 'iam_role.id = identity_role.role_id')
            ->where('identity_role.identity_id', $identityId)
            ->where('identity_role.status', 1)
            ->where('iam_role.application_id', $applicationId)
            ->where('iam_role.status', 1)
            ->column('iam_role.id') as $roleId) {
            $this->addRoleSource($sources, (int) $roleId, 'direct_identity_role');
        }
        foreach (IdentityGroupMember::alias('group_member')
            ->join('sand_iam_identity_group iam_group', 'iam_group.id = group_member.identity_group_id')
            ->join('sand_iam_identity_group_role group_role', 'group_role.identity_group_id = iam_group.id')
            ->join('sand_iam_role iam_role', 'iam_role.id = group_role.role_id')
            ->where('group_member.identity_id', $identityId)
            ->where('group_member.application_id', $applicationId)
            ->where('group_member.status', 1)
            ->where('iam_group.application_id', $applicationId)
            ->where('iam_group.status', 1)
            ->where('group_role.application_id', $applicationId)
            ->where('group_role.status', 1)
            ->where('iam_role.application_id', $applicationId)
            ->where('iam_role.status', 1)
            ->field('iam_role.id AS role_id,iam_group.id AS identity_group_id')
            ->select() as $binding) {
            $this->addRoleSource($sources, (int) $binding->role_id, 'identity_group:' . (int) $binding->identity_group_id);
        }
        ksort($sources);
        return ['role_ids' => array_map('intval', array_keys($sources)), 'sources' => $sources];
    }

    /** @param array<int,list<string>> $sources */
    private function addRoleSource(array &$sources, int $roleId, string $source): void
    {
        if ($roleId <= 0) return;
        $sources[$roleId] ??= [];
        if (!in_array($source, $sources[$roleId], true)) $sources[$roleId][] = $source;
    }

    /** @param list<int> $roleIds */
    private function belongsToSubject(object $policy, int $identityId, array $roleIds): bool
    {
        return (int) $policy->identity_id === $identityId || ((int) $policy->role_id > 0 && in_array((int) $policy->role_id, $roleIds, true));
    }

    /** @param array<int,list<string>> $sources @return list<string> */
    private function subjectSource(object $policy, int $identityId, array $sources): array
    {
        if ((int) $policy->identity_id === $identityId) return ['direct_identity'];
        return $sources[(int) $policy->role_id] ?? [];
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

    /** @param list<int> $policyIds @param array<string,mixed> $context @return array{allowed:false,code:string,policy_ids:list<int>,scope:array<string,mixed>} */
    private function deny(?Application $application, int $identityId, string $resourceCode, string $operation, string $requestId, string $code, array $policyIds = [], array $context = []): array
    {
        $this->auditWriter->write('identity', (string) $identityId, $application ? (int) $application->organization_id : null, $application ? (int) $application->id : null, 'authorize.' . $operation, $resourceCode ?: 'unknown', null, 'denied', $requestId, array_merge(['code' => $code, 'policy_ids' => $policyIds], $context));
        return ['allowed' => false, 'code' => $code, 'policy_ids' => $policyIds, 'scope' => []];
    }

    private function requestId(string $requestId): string
    {
        return RequestId::normalize($requestId);
    }

    private function publishedSnapshot(object $policy): ?object
    {
        $version = PolicyVersion::where('id', (int) $policy->published_version_id)->where('policy_id', (int) $policy->id)->find();
        if ($version === null) return null;
        $snapshot = (array) $version->snapshot;
        return (object) array_merge($snapshot, ['id' => (int) $policy->id, 'published_version_id' => (int) $version->id]);
    }

    private function snapshotTargets(object $snapshot, int $applicationId, int $resourceId, string $action): bool
    {
        return (int) $snapshot->application_id === $applicationId && (int) $snapshot->resource_id === $resourceId && hash_equals((string) $snapshot->action, $action);
    }

    /** @param list<object> $rules @return list<object> */
    private function selectLowestPriorityRules(array $rules): array
    {
        usort($rules, static fn (object $left, object $right): int => [(int) $left->priority, (int) $left->id] <=> [(int) $right->priority, (int) $right->id]);
        if ($rules === []) return [];
        $priority = (int) $rules[0]->priority;
        return array_values(array_filter($rules, static fn (object $rule): bool => (int) $rule->priority === $priority));
    }

    /** @param array<string,mixed> $condition @param array<string,mixed> $attributes @return list<string> */
    private function missingContext(array $condition, array $attributes): array
    {
        $keys = array_merge(array_keys((array) ($condition['equals'] ?? [])), array_keys((array) ($condition['in'] ?? [])));
        return array_values(array_filter($keys, static fn (string $key): bool => !array_key_exists($key, $attributes)));
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function redact(array $value): array
    {
        foreach ($value as $key => $item) { if (preg_match('/(?:secret|token|password|credential|key)/i', (string) $key) === 1) { $value[$key] = '[已脱敏]'; } elseif (is_array($item)) { $value[$key] = $this->redact($item); } }
        return $value;
    }
}
