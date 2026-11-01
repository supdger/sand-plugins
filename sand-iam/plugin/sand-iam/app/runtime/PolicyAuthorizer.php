<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\model\Application;
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
        private readonly PolicySnapshotLoader $snapshotLoader = new PostgresPolicySnapshotLoader(),
    ) {}

    /** @return array{allowed:bool,code:string,policy_ids:list<int>,scope:array<string,mixed>} */
    public function authorize(int $applicationId, int $identityId, string $resourceCode, string $action, string $operation, array $attributes, string $requestId, ?array $apiContext = null): array
    {
        $requestId = $this->requestId($requestId); $this->assertOperation($operation);
        [$facts, $decision] = $this->evaluate($applicationId, $identityId, $resourceCode, $action, $attributes, false, $apiContext);
        $context = $decision;
        unset($context['matched_rules'], $context['missing_context'], $context['final_reason'], $context['allowed']);
        $this->auditWriter->write('identity', (string) $identityId, isset($facts['application']['organization_id']) ? (int) $facts['application']['organization_id'] : null, isset($facts['application']['organization_id']) ? $applicationId : null, 'authorize.' . $operation, $resourceCode ?: 'unknown', isset($facts['resource']['id']) ? (int) $facts['resource']['id'] : null, $decision['allowed'] ? 'allowed' : 'denied', $requestId, $context);
        return array_intersect_key($decision, array_flip(['allowed', 'code', 'policy_ids', 'scope']));
    }

    /** Read-only simulation: same Casbin model and facts, with no audit write. */
    public function simulate(int $applicationId, int $identityId, string $resourceCode, string $action, string $operation, array $attributes, string $requestId): array
    {
        $requestId = $this->requestId($requestId); $this->assertOperation($operation);
        [, $decision] = $this->evaluate($applicationId, $identityId, $resourceCode, $action, $attributes, true);
        return ['request_id' => $requestId] + $decision;
    }

    private function evaluate(int $applicationId, int $identityId, string $resourceCode, string $action, array $attributes, bool $diagnose, ?array $apiContext = null): array
    {
        $facts = [];
        $denied = ['allowed' => false, 'code' => 'SAND_IAM_POLICY_DENIED', 'policy_ids' => [], 'scope' => [], 'published_version_ids' => [], 'effective_role_sources' => [], 'matched_rules' => [], 'missing_context' => [], 'final_reason' => '应用、身份、资源或动作不可用', 'engine' => 'casbin', 'engine_version' => CasbinPolicyEngine::VERSION];
        try {
            $facts = $this->snapshotLoader->load($applicationId, $identityId, $resourceCode, $action, $apiContext);
            if (!$facts['available']) return [$facts, $denied];
            $adapter = new PublishedPolicyAdapter($facts);
            return [$facts, (new CasbinPolicyEngine($this->scopeMatcher))->decide($adapter, $applicationId, $identityId, (int) $facts['resource']['id'], $action, $attributes, $diagnose)];
        } catch (\Throwable $exception) {
            // Never fall back to draft facts or a second policy engine.
            $denied['final_reason'] = '授权快照或策略引擎不可用';
            preg_match('/^(SAND_IAM_[A-Z_]+)/', $exception->getMessage(), $matches);
            $denied['engine_error'] = $matches[1] ?? 'SAND_IAM_CASBIN_FAILED';
            if (preg_match('/policy=(\d+) version=(\d+)/', $exception->getMessage(), $ids) === 1) {
                $denied['engine_error_policy_id'] = (int) $ids[1];
                $denied['engine_error_version_id'] = (int) $ids[2];
            }
            return [$facts, $denied];
        }
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
        $allowed = $application !== null && $resource !== null && $this->scopeMatcher->matches($scope, $attributes);
        $organizationId = $application ? (int) $application->organization_id : null;
        $resourceId = $resource ? (int) $resource->id : null;
        // Audit identity never becomes an authorization cache. Live state and
        // the trusted attributes are evaluated again, including on every replay.
        $eventKey = hash('sha256', json_encode($this->canonicalAuditValue([
            'version' => 'sand-iam/scope-audit/v1',
            'actor_type' => 'identity', 'identity_id' => $identityId,
            'organization_id' => $organizationId, 'application_id' => $applicationId,
            'resource_code' => $resourceCode, 'resource_id' => $resourceId,
            'operation' => $operation, 'outcome' => $allowed ? 'allowed' : 'denied',
            'application_available' => $application !== null, 'resource_available' => $resource !== null,
            'scope' => $scope, 'attributes' => $attributes,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $this->auditWriter->write(
            'identity', (string) $identityId, $organizationId, $application !== null ? $applicationId : null,
            'scope.' . $operation, $resourceCode, $resourceId, $allowed ? 'allowed' : 'denied', $requestId,
            ['scope' => $scope, 'scope_check_fingerprint' => $eventKey],
            $eventKey,
        );
        if (!$allowed) throw new ApiException('SAND_IAM_RESOURCE_SCOPE_DENIED', 403);
    }

    private function canonicalAuditValue(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalAuditValue($item);
        return $value;
    }

    private function assertOperation(string $operation): void
    {
        if (!in_array($operation, self::OPERATIONS, true)) throw new ApiException('SAND_IAM_POLICY_DENIED: unsupported operation', 403);
    }
    private function requestId(string $requestId): string { return RequestId::normalize($requestId); }
}
