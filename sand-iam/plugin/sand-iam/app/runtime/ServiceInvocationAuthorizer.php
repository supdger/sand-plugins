<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Credential;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceAction;
use plugin\SandIam\app\model\ServiceGrant;
use plugin\SandIam\app\model\ServiceInvocationOperation;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\security\NetworkPolicy;
use plugin\SandIam\app\security\ServiceGrantConstraintNormalizer;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/** Host-local invocation authorization. It is deliberately not exposed as an HTTP controller. */
final class ServiceInvocationAuthorizer
{
    public function __construct(
        private readonly IdentityContextProvider $contexts = new IdentityContextProvider(),
        private readonly AuditWriter $auditWriter = new AuditWriter(),
        private readonly ServiceInvocationFactResolverRegistry $factResolvers = new ServiceInvocationFactResolverRegistry(),
    ) {}

    /** @return array<string,mixed> */
    public function authorizeInvocation(
        string $context,
        string $expectedServiceCode,
        string $expectedAudience,
        string $requiredAction,
        string $resolverCode,
        string $resourceKey,
        string $trustedSourceIp,
        string $operationId,
        string $requestId,
    ): array {
        $operationId = $this->operationId($operationId);
        $requestId = RequestId::normalize($requestId);
        $claims = $this->contexts->verifyForService($context, $expectedServiceCode, $expectedAudience, $requiredAction, $trustedSourceIp, $requestId);
        $grant = $claims['action_grants'][$requiredAction] ?? null;
        if (!is_array($grant)) throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: 当前上下文没有目标服务动作授权', 403);
        $facts = ResolvedInvocationFacts::resolve($this->factResolvers, $resolverCode, $expectedServiceCode, $requiredAction, $resourceKey);
        try {
            $this->assertFactsScope($claims, $facts);
        } catch (ApiException $exception) {
            $this->auditWriter->write(
                'workload_client',
                (string) ($claims['workload_client_id'] ?? 0),
                (int) ($claims['organization_id'] ?? 0),
                (int) ($claims['application_id'] ?? 0),
                'service.invoke.authorize',
                $facts->resourceType,
                null,
                'denied',
                $requestId,
                ['code' => 'SAND_IAM_INVOCATION_SCOPE_FORBIDDEN', 'service_code' => $expectedServiceCode, 'action_code' => $requiredAction],
            );
            throw $exception;
        }
        $fingerprint = $this->fingerprint([
            'context_id' => (string) ($claims['context_id'] ?? ''),
            'credential_id' => (int) ($claims['credential_id'] ?? 0),
            'organization_id' => (int) ($claims['organization_id'] ?? 0),
            'application_id' => (int) ($claims['application_id'] ?? 0),
            'environment_id' => (int) ($claims['environment_id'] ?? 0),
            'workload_client_id' => (int) ($claims['workload_client_id'] ?? 0),
            'grant_ids' => $this->grantSet($claims),
            'grant_id' => (int) ($grant['grant_id'] ?? 0),
            'service_code' => $expectedServiceCode,
            'audience' => $expectedAudience,
            'action_code' => $requiredAction,
            'resolver_code' => $resolverCode,
            'resource_key' => $resourceKey,
            'data_class' => $facts->dataClass,
            'resource_type' => $facts->resourceType,
            'resource_ref' => $facts->resourceRef,
            'fact_organization_id' => $facts->organizationId,
            'fact_application_id' => $facts->applicationId,
            'fact_environment_id' => $facts->environmentId,
            'fact_workload_client_id' => $facts->workloadClientId,
        ]);
        return $this->persistAuthorization($claims, $grant, $facts, $requiredAction, $trustedSourceIp, $operationId, $fingerprint, $requestId, 0);
    }

    /** @return array<string,mixed> */
    public function revalidateInvocation(
        int $authorizationId,
        string $context,
        string $expectedServiceCode,
        string $expectedAudience,
        string $requiredAction,
        string $resolverCode,
        string $resourceKey,
        string $trustedSourceIp,
        string $requestId,
    ): array
    {
        if ($authorizationId <= 0) throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: 调用授权记录无效', 403);
        $requestId = RequestId::normalize($requestId);
        $claims = $this->contexts->verifyForService($context, $expectedServiceCode, $expectedAudience, $requiredAction, $trustedSourceIp, $requestId);
        $grant = $claims['action_grants'][$requiredAction] ?? null;
        if (!is_array($grant)) throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: 当前上下文没有目标服务动作授权', 403);
        Db::startTrans();
        $committed = false;
        try {
            $operation = ServiceInvocationOperation::where('id', $authorizationId)->lock(true)->find();
            if ($operation === null || (string) $operation->outcome !== 'allowed'
                || !hash_equals((string) $operation->service_code, $expectedServiceCode)
                || !hash_equals((string) $operation->service_code, (string) ($grant['service_code'] ?? ''))
                || !hash_equals((string) $operation->audience, $expectedAudience)
                || !hash_equals((string) $operation->action_code, $requiredAction)
                || !hash_equals((string) $operation->context_id, (string) ($claims['context_id'] ?? ''))
                || (int) $operation->organization_id !== (int) ($claims['organization_id'] ?? 0)
                || (int) $operation->application_id !== (int) ($claims['application_id'] ?? 0)
                || (int) $operation->environment_id !== (int) ($claims['environment_id'] ?? 0)
                || (int) $operation->workload_client_id !== (int) ($claims['workload_client_id'] ?? 0)
                || (int) $operation->credential_id !== (int) ($claims['credential_id'] ?? 0)
                || (int) $operation->grant_id !== (int) ($grant['grant_id'] ?? 0)) {
                throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: 调用授权记录不可用', 403);
            }
            $facts = ResolvedInvocationFacts::resolve($this->factResolvers, $resolverCode, $expectedServiceCode, $requiredAction, $resourceKey);
            $this->assertFactsScope($claims, $facts);
            if (!hash_equals((string) $operation->resource_type, $facts->resourceType) || !hash_equals((string) $operation->resource_ref, $facts->resourceRef)) {
                throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: 当前资源与原授权记录不一致', 403);
            }
            $live = $this->liveGrant($claims, $grant, $expectedAudience, $requiredAction, $facts, $trustedSourceIp);
            $this->auditWriter->write('workload_client', (string) $operation->workload_client_id, (int) $operation->organization_id, (int) $operation->application_id, 'service.invoke.revalidate', 'service_invocation_operation', (int) $operation->id, 'allowed', $requestId, $this->auditContext($operation, $live['data_class']));
            Db::commit();
            $committed = true;
            return $this->result($operation, true, $live['data_class'], true);
        } catch (ApiException $exception) {
            if (!$committed) {
                if (isset($operation) && $operation instanceof ServiceInvocationOperation) {
                    try {
                        $this->auditWriter->write('workload_client', (string) $operation->workload_client_id, (int) $operation->organization_id, (int) $operation->application_id, 'service.invoke.revalidate', 'service_invocation_operation', (int) $operation->id, 'denied', $requestId, ['code' => $this->errorCode($exception), 'service_code' => $expectedServiceCode, 'action_code' => $requiredAction]);
                        Db::commit();
                    } catch (\Throwable $auditException) {
                        Db::rollback();
                        throw $auditException;
                    }
                } else {
                    Db::rollback();
                }
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if (!$committed) Db::rollback();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $claims @param array<string,mixed> $grant @return array<string,mixed> */
    private function persistAuthorization(array $claims, array $grant, ResolvedInvocationFacts $facts, string $requiredAction, string $trustedSourceIp, string $operationId, string $fingerprint, string $requestId, int $retry): array
    {
        Db::startTrans();
        $committed = false;
        $operation = null;
        try {
            $clientId = (int) ($claims['workload_client_id'] ?? 0);
            $existing = ServiceInvocationOperation::where('workload_client_id', $clientId)->where('operation_id', $operationId)->lock(true)->find();
            if ($existing !== null) {
                if (
                    !hash_equals((string) $existing->request_fingerprint, $fingerprint)
                    || !hash_equals((string) $existing->context_id, (string) ($claims['context_id'] ?? ''))
                    || (int) $existing->credential_id !== (int) ($claims['credential_id'] ?? 0)
                ) {
                    throw new ApiException('SAND_IAM_IDEMPOTENCY_CONFLICT: operation_id 已用于不同调用语义', 409);
                }
                $this->liveGrant($claims, $grant, (string) $existing->audience, (string) $existing->action_code, $facts, $trustedSourceIp);
                $this->auditWriter->write('workload_client', (string) $clientId, (int) $existing->organization_id, (int) $existing->application_id, 'service.invoke.authorize', 'service_invocation_operation', (int) $existing->id, (string) $existing->outcome, $requestId, $this->auditContext($existing, $existing->data_class === null ? null : (string) $existing->data_class));
                Db::commit();
                $committed = true;
                if ((string) $existing->outcome !== 'allowed') throw $this->storedDenial((string) $existing->error_code);
                return $this->result($existing, true);
            }

            // Lock every parent in one order before inserting the row with
            // foreign keys.  Inserting first takes PostgreSQL KEY SHARE locks;
            // two callers then trying to upgrade them to FOR UPDATE can deadlock.
            $live = $this->liveGrant($claims, $grant, (string) ($claims['audience'] ?? ''), $requiredAction, $facts, $trustedSourceIp);
            $operation = ServiceInvocationOperation::create([
                'workload_client_id' => $clientId,
                'credential_id' => (int) ($claims['credential_id'] ?? 0),
                'organization_id' => (int) ($claims['organization_id'] ?? 0),
                'application_id' => (int) ($claims['application_id'] ?? 0),
                'environment_id' => (int) ($claims['environment_id'] ?? 0),
                'grant_id' => (int) ($grant['grant_id'] ?? 0),
                'operation_id' => $operationId,
                'request_fingerprint' => $fingerprint,
                'context_id' => (string) ($claims['context_id'] ?? ''),
                'service_code' => (string) ($grant['service_code'] ?? ''),
                'action_code' => $requiredAction,
                'audience' => (string) ($claims['audience'] ?? ''),
                'data_class' => $facts->dataClass,
                'resource_type' => $facts->resourceType,
                'resource_ref' => $facts->resourceRef,
                'outcome' => 'pending',
                'request_id' => $requestId,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            try {
                $quota = $this->consumeQuota((int) $operation->grant_id, $live['quota_policy']);
                if ($quota['allowed'] !== true) throw new ApiException('SAND_IAM_SERVICE_QUOTA_EXCEEDED: 当前额度窗口的调用尝试次数已用完', 429);
                $operation->save(['outcome' => 'allowed', 'quota_window_start' => $quota['window_start'], 'quota_used' => $quota['used'], 'update_time' => date('Y-m-d H:i:s')]);
                $this->auditWriter->write('workload_client', (string) $clientId, (int) $operation->organization_id, (int) $operation->application_id, 'service.invoke.authorize', 'service_invocation_operation', (int) $operation->id, 'allowed', $requestId, $this->auditContext($operation, $live['data_class']));
                Db::commit();
                $committed = true;
                return $this->result($operation, false);
            } catch (ApiException $decision) {
                $operation->save(['outcome' => 'denied', 'error_code' => $this->errorCode($decision), 'update_time' => date('Y-m-d H:i:s')]);
                $this->auditWriter->write('workload_client', (string) $clientId, (int) $operation->organization_id, (int) $operation->application_id, 'service.invoke.authorize', 'service_invocation_operation', (int) $operation->id, 'denied', $requestId, ['code' => $this->errorCode($decision), 'service_code' => (string) $operation->service_code, 'action_code' => (string) $operation->action_code, 'data_class' => $facts->dataClass]);
                Db::commit();
                $committed = true;
                throw $decision;
            }
        } catch (ApiException $decision) {
            if ($operation !== null || $committed) {
                if (!$committed) Db::rollback();
                throw $decision;
            }
            try {
                $this->auditWriter->write(
                    'workload_client',
                    (string) ($claims['workload_client_id'] ?? 0),
                    (int) ($claims['organization_id'] ?? 0),
                    (int) ($claims['application_id'] ?? 0),
                    'service.invoke.authorize',
                    'service_invocation_operation',
                    null,
                    'denied',
                    $requestId,
                    ['code' => $this->errorCode($decision), 'service_code' => (string) ($grant['service_code'] ?? ''), 'action_code' => $requiredAction, 'data_class' => $facts->dataClass],
                );
                Db::commit();
                $committed = true;
            } catch (\Throwable $auditException) {
                Db::rollback();
                throw $auditException;
            }
            throw $decision;
        } catch (\Throwable $exception) {
            if (!$committed) Db::rollback();
            if (!$committed && $retry === 0 && $this->isUniqueViolation($exception)) return $this->persistAuthorization($claims, $grant, $facts, $requiredAction, $trustedSourceIp, $operationId, $fingerprint, $requestId, 1);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $claims @param array<string,mixed> $grant @return array{quota_policy:array,data_class:?string} */
    private function liveGrant(array $claims, array $grant, string $audience, string $actionCode, ResolvedInvocationFacts $facts, string $trustedSourceIp): array
    {
        $this->assertFactsScope($claims, $facts);
        $credential = Credential::where('id', (int) ($claims['credential_id'] ?? 0))->where('status', 1)->lock(true)->find();
        $client = WorkloadClient::where('id', (int) ($claims['workload_client_id'] ?? 0))->where('status', 1)->lock(true)->find();
        if ($credential === null || $client === null || (int) $credential->workload_client_id !== (int) $client->id || $credential->revoked_time !== null || $this->expired($credential->expire_time) || !hash_equals((string) $client->audience, $audience)) {
            throw new ApiException('SAND_IAM_CREDENTIAL_REVOKED: 调用凭证或服务调用身份已不可用', 401);
        }
        $environment = Environment::where('id', (int) ($claims['environment_id'] ?? 0))->where('application_id', (int) ($claims['application_id'] ?? 0))->where('status', 1)->lock(true)->find();
        $application = Application::where('id', (int) ($claims['application_id'] ?? 0))->where('organization_id', (int) ($claims['organization_id'] ?? 0))->where('status', 1)->lock(true)->find();
        $organization = Organization::where('id', (int) ($claims['organization_id'] ?? 0))->where('status', 1)->lock(true)->find();
        if ($environment === null || $application === null || $organization === null || (int) $client->environment_id !== (int) $environment->id) throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: 调用所属层级已不可用', 403);
        $grantRecord = ServiceGrant::where('id', (int) ($grant['grant_id'] ?? 0))->where('workload_client_id', (int) $client->id)->where('audience', $audience)->where('status', 1)->whereNull('revoked_time')->lock(true)->find();
        if ($grantRecord === null || $this->expired($grantRecord->expire_time)) throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: 服务授权已撤销或过期', 403);
        try {
            if (inet_pton($trustedSourceIp) === false || !NetworkPolicy::allows($grantRecord->network_policy, $trustedSourceIp)) throw new ApiException('SAND_IAM_SERVICE_NETWORK_FORBIDDEN', 403);
        } catch (ApiException) {
            throw new ApiException('SAND_IAM_SERVICE_NETWORK_FORBIDDEN', 403);
        }
        $action = ServiceAction::where('id', (int) $grantRecord->service_action_id)->where('code', $actionCode)->where('status', 1)->lock(true)->find();
        $service = $action === null ? null : Service::where('id', (int) $action->service_id)->where('code', (string) ($grant['service_code'] ?? ''))->where('status', 1)->lock(true)->find();
        if ($action === null || $service === null) throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: 服务或动作已停用', 403);
        $dataClass = ServiceGrantConstraintNormalizer::dataClass($grantRecord->data_class, true);
        ServiceGrantConstraintNormalizer::assertExactDataClass($dataClass, $facts->dataClass);
        return ['quota_policy' => ServiceGrantConstraintNormalizer::quota($grantRecord->quota_policy, true), 'data_class' => $dataClass];
    }

    /** @param array{}|array{max_invocation_attempts:int,window_seconds:int} $policy @return array{allowed:bool,window_start:?string,used:?int} */
    private function consumeQuota(int $grantId, array $policy): array
    {
        if ($policy === []) return ['allowed' => true, 'window_start' => null, 'used' => null];
        $window = $policy['window_seconds'];
        $limit = $policy['max_invocation_attempts'];
        $clock = Db::query("SELECT to_timestamp(floor(extract(epoch FROM clock_timestamp()) / ?) * ?) AT TIME ZONE 'UTC' AS window_start", [$window, $window]);
        $windowStart = (string) ($clock[0]['window_start'] ?? '');
        if ($windowStart === '') throw new ApiException('SAND_IAM_SERVICE_GRANT_CONSTRAINT_INVALID: 无法计算额度窗口', 403);
        $rows = Db::query(
            'INSERT INTO sand_iam_service_quota_bucket (grant_id,window_seconds,window_start,used,create_time,update_time) VALUES (?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT (grant_id,window_seconds,window_start) DO UPDATE SET used=sand_iam_service_quota_bucket.used+1,update_time=CURRENT_TIMESTAMP WHERE sand_iam_service_quota_bucket.used < ? RETURNING used',
            [$grantId, $window, $windowStart, 1, $limit],
        );
        if ($rows === []) return ['allowed' => false, 'window_start' => $windowStart, 'used' => null];
        return ['allowed' => true, 'window_start' => $windowStart, 'used' => (int) $rows[0]['used']];
    }

    /** @return array<string,mixed> */
    private function result(ServiceInvocationOperation $operation, bool $replayed, ?string $currentDataClass = null, bool $useCurrentDataClass = false): array
    {
        return ['authorization_id' => (int) $operation->id, 'operation_id' => (string) $operation->operation_id, 'context_id' => (string) $operation->context_id, 'organization_id' => (int) $operation->organization_id, 'application_id' => (int) $operation->application_id, 'environment_id' => (int) $operation->environment_id, 'workload_client_id' => (int) $operation->workload_client_id, 'grant_id' => (int) $operation->grant_id, 'service_code' => (string) $operation->service_code, 'action_code' => (string) $operation->action_code, 'audience' => (string) $operation->audience, 'data_class' => $useCurrentDataClass ? $currentDataClass : ($operation->data_class === null ? null : (string) $operation->data_class), 'replayed' => $replayed];
    }

    /** @return array<string,mixed> */
    private function auditContext(ServiceInvocationOperation $operation, ?string $dataClass): array { return ['operation_id' => (string) $operation->operation_id, 'context_id' => (string) $operation->context_id, 'grant_id' => (int) $operation->grant_id, 'service_code' => (string) $operation->service_code, 'action_code' => (string) $operation->action_code, 'audience' => (string) $operation->audience, 'data_class' => $dataClass]; }
    /** @param array<string,mixed> $claims */
    private function assertFactsScope(array $claims, ResolvedInvocationFacts $facts): void
    {
        if (
            $facts->organizationId !== (int) ($claims['organization_id'] ?? 0)
            || $facts->applicationId !== (int) ($claims['application_id'] ?? 0)
            || $facts->environmentId !== (int) ($claims['environment_id'] ?? 0)
            || $facts->workloadClientId !== (int) ($claims['workload_client_id'] ?? 0)
        ) {
            throw new ApiException('SAND_IAM_INVOCATION_SCOPE_FORBIDDEN: 服务端资源不属于当前调用上下文', 403);
        }
    }
    /** @param array<string,mixed> $claims @return list<int> */
    private function grantSet(array $claims): array
    {
        $grantIds = $claims['grant_ids'] ?? null;
        if (!is_array($grantIds) || !array_is_list($grantIds) || $grantIds === []) {
            throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: 当前上下文的授权集合无效', 403);
        }
        $normalized = [];
        foreach ($grantIds as $grantId) {
            if (!is_int($grantId) || $grantId <= 0 || isset($normalized[$grantId])) {
                throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: 当前上下文的授权集合无效', 403);
            }
            $normalized[$grantId] = true;
        }
        $result = array_keys($normalized);
        sort($result, SORT_NUMERIC);
        return $result;
    }
    private function operationId(string $value): string { $value = trim($value); if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $value) !== 1) throw new ApiException('SAND_IAM_INVOCATION_FACTS_UNVERIFIED: operation_id 格式不正确', 400); return $value; }
    /** @param array<string,mixed> $payload */
    private function fingerprint(array $payload): string { ksort($payload); return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }
    private function expired(mixed $value): bool { return $value !== null && $value !== '' && strtotime((string) $value) <= time(); }
    private function errorCode(ApiException $exception): string { return preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $exception->getMessage(), $match) === 1 ? $match[1] : 'SAND_IAM_SERVICE_ACTION_FORBIDDEN'; }
    private function storedDenial(string $code): ApiException { $code = $code !== '' ? $code : 'SAND_IAM_SERVICE_ACTION_FORBIDDEN'; return new ApiException($code, $code === 'SAND_IAM_SERVICE_QUOTA_EXCEEDED' ? 429 : 403); }
    private function isUniqueViolation(\Throwable $exception): bool { return str_contains(strtolower($exception->getMessage()), '23505') || str_contains(strtolower($exception->getMessage()), 'unique constraint'); }
}
