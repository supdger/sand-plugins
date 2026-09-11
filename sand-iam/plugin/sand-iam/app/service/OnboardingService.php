<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\developer\OnboardingManifest;
use plugin\SandIam\app\model\ApiResource;
use plugin\SandIam\app\model\ApiRouteBinding;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\ApplicationBusinessAction;
use plugin\SandIam\app\model\Credential;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\Policy;
use plugin\SandIam\app\model\Resource;
use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceAction;
use plugin\SandIam\app\model\ServiceGrant;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\runtime\RouteBindingSynchronizer;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/** Coordinates existing initialization, route and credential primitives. */
final class OnboardingService
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function preview(array $input, bool $lock = false): array
    {
        return $this->previewNormalized(OnboardingManifest::normalize($input), $lock);
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function previewNormalized(array $manifest, bool $lock = false): array
    {
        $initialization = (new InitializationService())->preview($manifest['initialization']);
        $changes = $initialization['changes'];
        foreach (['environment', 'api_resources', 'workload_client', 'service_grants'] as $section) {
            $items = $section === 'environment' || $section === 'workload_client' ? [$manifest[$section]] : $manifest[$section];
            foreach ($items as $item) $changes[] = ['object_type' => $section, 'object_key' => (string) ($item['code'] ?? $item['service_action_code'] ?? ''), 'operation' => 'reconcile', 'before' => null, 'after' => $item];
        }
        $state = $this->currentState((int) $initialization['organization_id'], $initialization['application_id'] === null ? null : (int) $initialization['application_id'], $lock);
        $previewHash = hash('sha256', $this->canonical(['manifest' => $manifest, 'state' => $state, 'changes' => $changes]));
        return ['dry_run' => true, 'operation_id' => $manifest['operation_id'], 'organization_id' => $initialization['organization_id'], 'application_id' => $initialization['application_id'], 'changes' => $changes, 'current_state' => $state, 'preview_hash' => $previewHash, 'initialization_preview_hash' => $initialization['preview_hash'], 'handoff' => OnboardingManifest::handoff($manifest), 'verification' => $this->verification(), 'manifest' => $manifest];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function apply(array $input, string $expectedPreviewHash, int $adminId, string $requestId): array
    {
        if ($adminId <= 0) throw new ApiException('SAND_IAM_ADMIN_REQUIRED', 401);
        $normalized = OnboardingManifest::normalize($input);
        $operationId = (string) $normalized['operation_id'];
        $fingerprint = IdempotencyService::fingerprint($normalized);
        $operations = new IdempotencyService();
        $completedReplay = $operations->waitForCompletedReplay('admin', (string) $adminId, 'onboarding.apply', $operationId, $fingerprint);
        if ($completedReplay !== null) return $completedReplay + ['operation_id' => $operationId];
        $preview = $this->previewNormalized($normalized);
        if (!hash_equals((string) $preview['preview_hash'], $expectedPreviewHash)) throw new ApiException('SAND_IAM_ONBOARDING_PREVIEW_STALE: 预检结果已变化，请重新预览后应用', 409);
        $manifest = $preview['manifest'];
        $operationId = (string) $manifest['operation_id'];
        $organizationId = (int) $preview['organization_id'];
        $previousIsolation = $this->beginSerializableTransaction();
        try {
            $this->lockApplication($organizationId, $preview['application_id'] === null ? 0 : (int) $preview['application_id']);
            $result = $operations->execute('admin', (string) $adminId, 'onboarding.apply', $operationId, $fingerprint, 'onboarding', function () use ($manifest, $expectedPreviewHash, $adminId, $requestId, $operationId, $organizationId): array {
            Db::startTrans();
            try {
                $lockedPreview = $this->previewNormalized($manifest, true);
                if (!hash_equals((string) $lockedPreview['preview_hash'], $expectedPreviewHash)) throw new ApiException('SAND_IAM_ONBOARDING_PREVIEW_STALE: 当前配置已变化，请重新预览', 409);
                $initialization = (new InitializationService())->apply($manifest['initialization'], (string) $lockedPreview['initialization_preview_hash'], $adminId, $requestId);
                $applicationId = (int) $initialization['application_id'];
                $environment = $this->upsert(Environment::class, ['application_id' => $applicationId, 'code' => $manifest['environment']['code']], ['application_id' => $applicationId] + $manifest['environment']);
                foreach ($manifest['api_resources'] as $api) {
                    $resource = Resource::where('application_id', $applicationId)->where('code', $api['resource_code'])->where('status', 1)->find();
                    if ($resource === null) throw new ApiException('SAND_IAM_ONBOARDING_RESOURCE_MISSING: 接口目录引用的业务资源不存在', 409);
                    $this->upsert(ApiResource::class, ['application_id' => $applicationId, 'code' => $api['code'], 'api_version' => $api['api_version']], ['application_id' => $applicationId, 'resource_id' => (int) $resource->id] + $api);
                }
                $sync = (new RouteBindingSynchronizer())->synchronizeNormalized($manifest['route_manifest'], true, false, $requestId, $operationId);
                $client = $this->upsert(WorkloadClient::class, ['environment_id' => (int) $environment->id, 'code' => $manifest['workload_client']['code']], ['environment_id' => (int) $environment->id] + $manifest['workload_client']);
                foreach ($manifest['service_grants'] as $grant) {
                    $service = Service::where('code', $grant['service_code'])->where('status', 1)->find();
                    $action = $service === null ? null : ServiceAction::where('service_id', (int) $service->id)->where('code', $grant['action_code'])->where('status', 1)->find();
                    if ($action === null) throw new ApiException('SAND_IAM_ONBOARDING_SERVICE_ACTION_MISSING: 服务授权引用的技术服务动作不存在或已停用', 409);
                    $this->upsert(ServiceGrant::class, ['workload_client_id' => (int) $client->id, 'service_action_id' => (int) $action->id, 'audience' => $grant['audience']], ['workload_client_id' => (int) $client->id, 'service_action_id' => (int) $action->id] + $grant);
                }
                $credential = (new CredentialIssuanceService())->issue((int) $client->id, 'onboarding-' . $manifest['operation_id'], null, $this->childRequestId($operationId, 'credential.issue'), (string) $adminId, $organizationId, $applicationId);
                Db::commit();
                return ['resource_id' => (int) $client->id, 'result' => ['application_id' => $applicationId, 'environment_id' => (int) $environment->id, 'workload_client_id' => (int) $client->id, 'credential_id' => (int) $credential['id'], 'credential' => $credential['credential'], 'key_prefix' => (string) $credential['key_prefix'], 'route_sync' => $sync, 'handoff' => OnboardingManifest::handoff($manifest), 'verification' => $this->verification()]];
            } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
            }, 0, false);
            Db::commit();
        } catch (ApiException $exception) {
            Db::rollback();
            $completedReplay = $operations->waitForCompletedReplay('admin', (string) $adminId, 'onboarding.apply', $operationId, $fingerprint);
            if ($completedReplay !== null) return $completedReplay + ['operation_id' => $operationId];
            if (str_starts_with($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_RETRY_REQUIRED')) {
                $replay = $operations->replay('admin', (string) $adminId, 'onboarding.apply', $operationId, $fingerprint);
                return $replay + ['operation_id' => $operationId];
            }
            throw $exception;
        } catch (\Throwable $exception) {
            Db::rollback();
            $completedReplay = $operations->waitForCompletedReplay('admin', (string) $adminId, 'onboarding.apply', $operationId, $fingerprint);
            if ($completedReplay !== null) return $completedReplay + ['operation_id' => $operationId];
            if ($this->isSerializationFailure($exception)) throw new ApiException('SAND_IAM_ONBOARDING_PREVIEW_STALE: 并发管理变更导致预检失效，请重新预览后重试', 409);
            throw $exception;
        } finally {
            $this->restoreTransactionIsolation($previousIsolation);
        }
        return $result + ['operation_id' => $operationId];
    }

    /** @param class-string $class @param array<string,mixed> $where @param array<string,mixed> $payload */
    private function upsert(string $class, array $where, array $payload): object
    {
        $query = $class::where(array_key_first($where), reset($where));
        foreach (array_slice($where, 1, null, true) as $field => $value) $query->where($field, $value);
        $model = $query->lock(true)->find();
        if ($model === null) return $class::create($payload);
        $mutable = $payload; foreach (array_keys($where) as $field) unset($mutable[$field]);
        $model->save($mutable); return $model;
    }

    /** @return list<string> */
    private function verification(): array { return ['执行 allow 请求并保留 X-Request-Id。', '执行 deny 请求，确认返回 403。', '按同一 X-Request-Id 查询 SandIAM 审计，确认 authorize 与 route/credential 记录。']; }
    private function childRequestId(string $operationId, string $operation): string { return 'onb_' . substr(hash('sha256', $operationId . "\0" . $operation), 0, 48); }
    private function beginSerializableTransaction(): string
    {
        $rows = Db::query('SHOW default_transaction_isolation');
        $previous = strtolower(trim((string) ($rows[0]['default_transaction_isolation'] ?? '')));
        if (!in_array($previous, ['read uncommitted', 'read committed', 'repeatable read', 'serializable'], true)) {
            throw new ApiException('SAND_IAM_ONBOARDING_TRANSACTION_CONFIGURATION_INVALID', 503);
        }
        Db::query('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        try {
            Db::startTrans();
        } catch (\Throwable $exception) {
            $this->restoreTransactionIsolation($previous);
            throw $exception;
        }
        return $previous;
    }
    private function restoreTransactionIsolation(string $level): void
    {
        $keyword = match ($level) {
            'read uncommitted' => 'READ UNCOMMITTED',
            'read committed' => 'READ COMMITTED',
            'repeatable read' => 'REPEATABLE READ',
            'serializable' => 'SERIALIZABLE',
            default => throw new ApiException('SAND_IAM_ONBOARDING_TRANSACTION_CONFIGURATION_INVALID', 503),
        };
        Db::query('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL ' . $keyword);
    }
    private function lockApplication(int $organizationId, int $applicationId): void
    {
        [$left, $right] = self::advisoryKey($organizationId, $applicationId);
        Db::query('SELECT pg_advisory_xact_lock(?, ?)', [$left, $right]);
    }
    /** @return array{0:int,1:int} */
    public static function advisoryKey(int $organizationId, int $applicationId): array
    {
        $parts = unpack('Nleft/Nright', substr(hash('sha256', $organizationId . "\0" . $applicationId, true), 0, 8));
        $signed = static fn (int $value): int => $value > 0x7fffffff ? $value - 0x100000000 : $value;
        return [$signed((int) $parts['left']), $signed((int) $parts['right'])];
    }
    private function isSerializationFailure(\Throwable $exception): bool { return (string) $exception->getCode() === '40001' || str_contains($exception->getMessage(), '40001') || str_contains(strtolower($exception->getMessage()), 'serialization_failure'); }
    /** @return array<string,mixed> */
    private function currentState(int $organizationId, ?int $applicationId, bool $lock = false): array
    {
        $organization = Organization::where('id', $organizationId); if ($lock) $organization->lock(true); $organizationRow = $organization->find();
        if ($applicationId === null) return ['organization' => $organizationRow?->toArray(), 'application' => null, 'credential_intent' => 'issue_new_once'];
        $application = Application::where('id', $applicationId)->where('organization_id', $organizationId); if ($lock) $application->lock(true); $applicationRow = $application->find();
        $environments = Environment::where('application_id', $applicationId); if ($lock) $environments->lock(true); $environmentRows = $environments->order('id')->select()->toArray();
        $apis = ApiResource::where('application_id', $applicationId); if ($lock) $apis->lock(true); $apiRows = $apis->order('id')->select()->toArray();
        $actions = ApplicationBusinessAction::where('application_id', $applicationId); if ($lock) $actions->lock(true); $actionRows = $actions->order('id')->select()->toArray();
        $policies = Policy::where('application_id', $applicationId); if ($lock) $policies->lock(true); $policyRows = $policies->order('id')->select()->toArray();
        $apiIds = array_map(static fn (array $row): int => (int) $row['id'], $apiRows);
        $routes = ApiRouteBinding::whereIn('api_resource_id', $apiIds); if ($lock) $routes->lock(true); $routeRows = $routes->order('http_method')->order('route_template')->order('id')->select()->toArray();
        $environmentIds = array_map(static fn (array $row): int => (int) $row['id'], $environmentRows);
        $clients = WorkloadClient::whereIn('environment_id', $environmentIds); if ($lock) $clients->lock(true);
        $clientRows = $clients->order('id')->select()->toArray();
        $clientIds = array_map(static fn (array $row): int => (int) $row['id'], $clientRows);
        $credentials = Credential::whereIn('workload_client_id', $clientIds); if ($lock) $credentials->lock(true); $credentialRows = $credentials->field('id,workload_client_id,name,key_prefix,expire_time,revoked_time,status,create_time,update_time')->order('id')->select()->toArray();
        $grants = ServiceGrant::whereIn('workload_client_id', $clientIds); if ($lock) $grants->lock(true); $grantRows = $grants->order('service_action_id')->order('audience')->order('id')->select()->toArray();
        return ['organization' => $organizationRow?->toArray(), 'application' => $applicationRow?->toArray(), 'application_business_actions' => $actionRows, 'environments' => $environmentRows, 'api_resources' => $apiRows, 'route_bindings' => $routeRows, 'policies' => $policyRows, 'workload_clients' => $clientRows, 'credentials_metadata' => $credentialRows, 'service_grants' => $grantRows, 'credential_intent' => 'issue_new_once'];
    }
    private function canonical(mixed $value): string { $sort = static function (mixed $item) use (&$sort): mixed { if (!is_array($item)) return $item; if (!array_is_list($item)) ksort($item); foreach ($item as $key => $child) $item[$key] = $sort($child); return $item; }; return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
}
