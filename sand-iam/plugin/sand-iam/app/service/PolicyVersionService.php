<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Policy;
use plugin\SandIam\app\model\PolicyVersion;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class PolicyVersionService
{
    /**
     * @param null|callable():void $audit Runs before commit for a new version only.
     * @return array{policy:Policy,version:PolicyVersion,replayed:bool}
     */
    public function publish(int $policyId, string $requestId, ?int $rollbackVersionId = null, ?callable $audit = null): array
    {
        $requestId = RequestId::normalize($requestId);
        Db::startTrans();
        try {
            $policy = Policy::where('id', $policyId)->lock(true)->find();
            if ($policy === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 未找到策略', 400);
            $source = $rollbackVersionId === null ? null : PolicyVersion::where('id', $rollbackVersionId)->where('policy_id', $policyId)->find();
            if ($rollbackVersionId !== null && $source === null) throw new ApiException('SAND_IAM_POLICY_VERSION_NOT_FOUND: 要回滚的策略版本不存在或不属于当前策略', 400);
            $snapshot = $source === null ? $this->snapshot($policy) : (array) $source->snapshot;
            $operation = $source === null ? 'publish' : 'rollback';
            $fingerprint = hash('sha256', json_encode(['operation' => $operation, 'rollback_version_id' => $rollbackVersionId, 'snapshot' => $snapshot], JSON_THROW_ON_ERROR));
            $existing = PolicyVersion::where('policy_id', $policyId)->where('request_id', $requestId)->lock(true)->find();
            if ($existing !== null) {
                if ((string) $existing->operation !== $operation || !hash_equals((string) $existing->request_fingerprint, $fingerprint)) throw new ApiException('SAND_IAM_IDEMPOTENCY_CONFLICT: request_id 已用于不同策略发布语义', 409);
                Db::commit(); return ['policy' => $policy, 'version' => $existing, 'replayed' => true];
            }
            $versionNo = (int) PolicyVersion::where('policy_id', $policyId)->max('version_no') + 1;
            $version = PolicyVersion::create(['policy_id' => $policyId, 'application_id' => (int) $policy->application_id, 'version_no' => $versionNo, 'snapshot' => $snapshot, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'rollback_of_version_id' => $source?->id, 'operation' => $operation, 'request_id' => $requestId, 'request_fingerprint' => $fingerprint, 'create_time' => date('Y-m-d H:i:s')]);
            $policy->save(['state' => 'published', 'status' => 1, 'published_version_id' => (int) $version->id, 'update_time' => date('Y-m-d H:i:s')]);
            if ($audit !== null) $audit();
            Db::commit();
            return ['policy' => $policy, 'version' => $version, 'replayed' => false];
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
    }

    /** @return array<string,mixed> */
    private function snapshot(Policy $policy): array
    {
        return ['application_id' => (int) $policy->application_id, 'resource_id' => (int) $policy->resource_id, 'role_id' => (int) $policy->role_id, 'identity_id' => (int) $policy->identity_id, 'action' => (string) $policy->action, 'effect' => (string) $policy->effect, 'condition' => (array) $policy->condition, 'scope' => (array) $policy->scope, 'priority' => (int) $policy->priority];
    }
}
