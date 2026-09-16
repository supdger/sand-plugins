<?php

declare(strict_types=1);

namespace plugin\SandIam\app\runtime;

use plugin\SandIam\app\developer\RouteSyncManifest;
use plugin\SandIam\app\developer\ApplicationBusinessActionCatalog;
use plugin\SandIam\app\model\ApiRouteBinding;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/**
 * Synchronizes an application's explicitly declared business routes.
 *
 * The manifest is the boundary: this service never enumerates the host route
 * collection and never creates API resources, business actions, policies, or
 * service actions. `apply` is deliberately opt-in; preview is the default.
 */
final class RouteBindingSynchronizer
{
    public function __construct(
        private readonly ApiGovernanceService $governance = new ApiGovernanceService(),
        private readonly AuditWriter $auditWriter = new AuditWriter(),
    ) {}

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public function synchronize(
        array $manifest,
        bool $apply = false,
        bool $disableMissing = false,
        string $requestId = '',
        string $operationId = '',
        ?string $expectedPreviewHash = null,
    ): array
    {
        return $this->synchronizeNormalized(
            RouteSyncManifest::normalize($manifest),
            $apply,
            $disableMissing,
            $requestId,
            $operationId,
            $expectedPreviewHash,
        );
    }

    /**
     * Applies an internal manifest returned by RouteSyncManifest::normalize().
     * This is for composed workflows such as onboarding; raw route declarations
     * must enter through synchronize().
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public function synchronizeNormalized(
        array $manifest,
        bool $apply = false,
        bool $disableMissing = false,
        string $requestId = '',
        string $operationId = '',
        ?string $expectedPreviewHash = null,
    ): array
    {
        $plan = $this->planNormalized(RouteSyncManifest::requireNormalized($manifest), $disableMissing);
        $plan['preview_hash'] = hash('sha256', $this->canonical($plan));
        $requestedOperationId = trim($operationId !== '' ? $operationId : $requestId);
        $plan['operation_id'] = $requestedOperationId !== '' ? substr($requestedOperationId, 0, 96) : RequestId::normalize('');
        $plan['dry_run'] = !$apply;
        if (!$apply) {
            return $plan;
        }
        if ($expectedPreviewHash !== null
            && (preg_match('/^[a-f0-9]{64}$/D', $expectedPreviewHash) !== 1
                || !hash_equals($plan['preview_hash'], $expectedPreviewHash))) {
            throw new ApiException('SAND_IAM_ROUTE_SYNC_PREVIEW_STALE: 路由或接口目录已变化，请重新预检', 409);
        }
        if (($plan['valid'] ?? false) !== true) {
            throw new ApiException('SAND_IAM_ROUTE_SYNC_APPLY_BLOCKED: 存在冲突或未登记接口；请先修正清单或接口目录', 409);
        }

        Db::startTrans();
        try {
            foreach ($plan['changes'] as $index => $change) {
                if (!in_array($change['operation'], ['create', 'refresh'], true)) {
                    continue;
                }
                $auditRequestId = $this->childRequestId(
                    (string) $plan['operation_id'],
                    (string) $change['operation'],
                    (int) ($change['binding_id'] ?? 0),
                    (string) $change['method'] . "\0" . (string) $change['route_template'],
                );
                $this->governance->observeRoute(
                    (int) $plan['application_id'],
                    (string) $change['api_code'],
                    (string) $change['api_version'],
                    (string) $change['method'],
                    (string) $change['route_template'],
                    'route_scan',
                    $auditRequestId,
                    true,
                );
                $binding = ApiRouteBinding::where('application_id', (int) $plan['application_id'])
                    ->where('http_method', (string) $change['method'])
                    ->where('route_template', (string) $change['route_template'])
                    ->where('source', 'route_scan')
                    ->find();
                if ($binding === null) {
                    throw new ApiException('SAND_IAM_ROUTE_SYNC_STALE: 路由绑定写入后无法读取', 409);
                }
                $plan['changes'][$index]['binding_id'] = (int) $binding->id;
                $plan['changes'][$index]['audit_request_id'] = $auditRequestId;
            }
            foreach ($plan['changes'] as $index => $change) {
                if ($change['operation'] !== 'disable') {
                    continue;
                }
                $binding = ApiRouteBinding::where('id', (int) $change['binding_id'])
                    ->where('application_id', (int) $plan['application_id'])
                    ->where('source', 'route_scan')
                    ->where('status', 1)
                    ->lock(true)
                    ->find();
                if ($binding === null) {
                    throw new ApiException('SAND_IAM_ROUTE_SYNC_STALE: 路由绑定已变化，请重新预览', 409);
                }
                $binding->save(['status' => 2]);
                $auditRequestId = $this->childRequestId(
                    (string) $plan['operation_id'],
                    'disable',
                    (int) $binding->id,
                    (string) $binding->http_method . "\0" . (string) $binding->route_template,
                );
                $this->auditWriter->write(
                    'system',
                    'api_route_catalog',
                    (int) $plan['organization_id'],
                    (int) $plan['application_id'],
                    'api_route.disable',
                    'api_route_binding',
                    (int) $binding->id,
                    'succeeded',
                    $auditRequestId,
                    ['source' => 'route_scan', 'reason' => 'manifest_disable_diff'],
                );
                $plan['changes'][$index]['audit_request_id'] = $auditRequestId;
            }
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }

        $plan['dry_run'] = false;
        return $plan;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function planNormalized(array $manifest, bool $disableMissing): array
    {
        $application = $this->governance->applicationByCode($manifest['organization_code'], $manifest['application_code']);
        $environment = Environment::where('application_id', (int) $application->id)
            ->where('code', $manifest['environment_code'])
            ->where('status', 1)
            ->find();
        if ($environment === null) {
            throw new ApiException('SAND_IAM_ROUTE_SYNC_ENVIRONMENT_MISMATCH: 应用环境不存在、已停用或不属于清单中的接入应用', 409);
        }

        $existing = [];
        foreach (ApiRouteBinding::where('application_id', (int) $application->id)->select()->all() as $binding) {
            $existing[(string) $binding->http_method . "\0" . (string) $binding->route_template] = $binding;
        }
        $changes = [];
        $errors = [];
        $desired = [];
        foreach ($manifest['routes'] as $route) {
            $key = $route['method'] . "\0" . $route['route_template'];
            $desired[$key] = true;
            try {
                $api = $this->governance->apiByCode((int) $application->id, $route['api_code'], $route['api_version']);
            } catch (ApiException $exception) {
                $errors[] = $this->change('unbound', 'SAND_IAM_ROUTE_SYNC_API_UNDECLARED', '未绑定', $route, null, $exception->getMessage());
                continue;
            }
            try {
                (new ApplicationBusinessActionCatalog())->assertEnabled((int) $application->id, (string) $api->action, true);
            } catch (ApiException $exception) {
                $code = str_starts_with($exception->getMessage(), 'SAND_IAM_APPLICATION_ACTION_DISABLED')
                    ? 'SAND_IAM_ROUTE_SYNC_ACTION_DISABLED'
                    : 'SAND_IAM_ROUTE_SYNC_ACTION_UNDECLARED';
                $errors[] = $this->change('unbound', $code, '未绑定', $route, null, $exception->getMessage());
                continue;
            }
            $binding = $existing[$key] ?? null;
            if ($binding !== null && (int) $binding->api_resource_id !== (int) $api->id) {
                $errors[] = $this->change('conflict', 'SAND_IAM_ROUTE_BINDING_CONFLICT', '冲突', $route, (int) $binding->id, '同一请求方法和路由模板已绑定到其他接口目录');
                continue;
            }
            if ($binding !== null && (string) $binding->source !== 'route_scan') {
                $changes[] = $this->change('unchanged', 'SAND_IAM_ROUTE_SYNC_EXTERNAL_BINDING_UNCHANGED', '保留外部绑定', $route, (int) $binding->id, '同一语义接口由 ' . (string) $binding->source . ' 来源维护；route_scan 不接管、不刷新');
                continue;
            }
            $operation = $binding === null ? 'create' : 'refresh';
            $code = $operation === 'create' ? 'SAND_IAM_ROUTE_SYNC_CREATE' : 'SAND_IAM_ROUTE_SYNC_REFRESH';
            $label = $operation === 'create' ? '新增' : '更新';
            $changes[] = $this->change($operation, $code, $label, $route, $binding === null ? null : (int) $binding->id);
        }
        if ($disableMissing) {
            foreach ($existing as $key => $binding) {
                if (isset($desired[$key]) || (string) $binding->source !== 'route_scan' || (int) $binding->status !== 1) {
                    continue;
                }
                $changes[] = [
                    'operation' => 'disable',
                    'code' => 'SAND_IAM_ROUTE_SYNC_DISABLE',
                    'label' => '停用',
                    'method' => (string) $binding->http_method,
                    'route_template' => (string) $binding->route_template,
                    'api_code' => null,
                    'api_version' => null,
                    'binding_id' => (int) $binding->id,
                    'reason' => '清单中已不存在；仅停用 route_scan 来源的启用绑定',
                ];
            }
        }
        usort($changes, static fn (array $left, array $right): int => [$left['route_template'], $left['method'], $left['operation']] <=> [$right['route_template'], $right['method'], $right['operation']]);
        $all = [...$changes, ...$errors];
        $counts = ['create' => 0, 'refresh' => 0, 'disable' => 0, 'unchanged' => 0, 'conflict' => 0, 'unbound' => 0];
        foreach ($all as $item) {
            $counts[$item['operation']]++;
        }

        return [
            'application_id' => (int) $application->id,
            'organization_id' => (int) $application->organization_id,
            'environment_id' => (int) $environment->id,
            'manifest' => $manifest,
            'disable_missing' => $disableMissing,
            'valid' => $errors === [],
            'summary' => [
                '新增' => $counts['create'],
                '更新' => $counts['refresh'],
                '停用' => $counts['disable'],
                '保留外部绑定' => $counts['unchanged'],
                '冲突' => $counts['conflict'],
                '未绑定' => $counts['unbound'],
                '忽略未标记路由' => $manifest['ignored_count'],
            ],
            'counts' => $counts,
            'changes' => $changes,
            'problems' => $errors,
        ];
    }

    /**
     * @param array{method:string,route_template:string,api_code:string,api_version:string} $route
     * @return array<string,mixed>
     */
    private function change(string $operation, string $code, string $label, array $route, ?int $bindingId, ?string $reason = null): array
    {
        return [
            'operation' => $operation,
            'code' => $code,
            'label' => $label,
            'method' => $route['method'],
            'route_template' => $route['route_template'],
            'api_code' => $route['api_code'],
            'api_version' => $route['api_version'],
            'binding_id' => $bindingId,
            'reason' => $reason,
        ];
    }

    private function childRequestId(string $operationId, string $operation, int $bindingId, string $route): string
    {
        $suffix = substr(hash('sha256', $operationId . "\0" . $operation . "\0" . $bindingId . "\0" . $route), 0, 16);
        $traceable = substr($operationId, 0, 79) . '.' . $suffix;
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/D', $traceable) === 1) {
            return $traceable;
        }
        return 'rs_' . substr(hash('sha256', $operationId . "\0" . $operation . "\0" . $bindingId . "\0" . $route), 0, 48);
    }

    private function canonical(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item);
            foreach ($item as $key => $child) $item[$key] = $sort($child);
            return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
