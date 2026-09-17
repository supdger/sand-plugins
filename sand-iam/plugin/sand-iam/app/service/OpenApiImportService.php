<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\developer\ApplicationBusinessActionCatalog;
use plugin\SandIam\app\developer\OpenApiImportDocument;
use plugin\SandIam\app\model\ApiResource;
use plugin\SandIam\app\model\ApiRouteBinding;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\ApplicationBusinessAction;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\Resource;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

final class OpenApiImportService
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function preview(array $input, bool $lock = false): array
    {
        $normalized = $this->normalize($input);
        [$organization, $application, $environment] = $this->context($normalized, $lock);
        $changes = [];
        $conflicts = [];
        $unmapped = [];
        $current = ['resources' => [], 'actions' => [], 'api_resources' => [], 'route_bindings' => []];

        foreach ($normalized['operations'] as $operation) {
            $key = $operation['operation_key'];
            $mapping = $normalized['mappings'][$key] ?? null;
            if ($mapping === null) {
                $unmapped[] = $key;
                continue;
            }
            $resourceQuery = Resource::where('application_id', (int) $application->id)
                ->where('code', $mapping['resource_code'])->where('status', 1);
            if ($lock) $resourceQuery->lock(true);
            $resource = $resourceQuery->find();
            if ($resource === null) {
                $conflicts[] = ['operation_key' => $key, 'code' => 'SAND_IAM_OPENAPI_IMPORT_RESOURCE_MISSING'];
                continue;
            }
            $current['resources'][$mapping['resource_code']] = [
                'id' => (int) $resource->id,
                'application_id' => (int) $resource->application_id,
                'code' => (string) $resource->code,
                'status' => (int) $resource->status,
            ];
            try {
                $action = $this->publishedAction(
                    (int) $application->id,
                    $mapping['action'],
                    $lock,
                );
                $current['actions'][$mapping['action']] = [
                    'id' => (int) $action->id,
                    'application_id' => (int) $action->application_id,
                    'code' => (string) $action->code,
                    'state' => (string) $action->state,
                    'status' => (int) $action->status,
                ];
            } catch (ApiException $exception) {
                $conflicts[] = ['operation_key' => $key, 'code' => $this->errorCode($exception)];
                continue;
            }

            $apiQuery = ApiResource::where('application_id', (int) $application->id)
                ->where('code', $mapping['api_code'])->where('api_version', $mapping['api_version']);
            if ($lock) $apiQuery->lock(true);
            $api = $apiQuery->find();
            $desiredApi = $this->apiPayload((int) $application->id, (int) $resource->id, $operation, $mapping);
            $apiConflict = $api === null ? null : $this->immutableApiConflict($api, $desiredApi);
            if ($apiConflict !== null) {
                $conflicts[] = ['operation_key' => $key, 'code' => $apiConflict];
                continue;
            }
            $current['api_resources'][$key] = $api?->toArray();
            $changes[] = [
                'object_type' => 'api_resource',
                'object_key' => $mapping['api_code'] . '@' . $mapping['api_version'],
                'operation' => $api === null ? 'create' : ($this->mutableApiChanged($api, $desiredApi) ? 'update' : 'unchanged'),
                'before' => $api?->toArray(),
                'after' => $desiredApi,
            ];

            $bindingQuery = ApiRouteBinding::where('application_id', (int) $application->id)
                ->where('http_method', $operation['method'])
                ->where('route_template', $operation['route_template']);
            if ($lock) $bindingQuery->lock(true);
            $binding = $bindingQuery->find();
            $current['route_bindings'][$key] = $binding?->toArray();
            if ($binding !== null && ($api === null || (int) $binding->api_resource_id !== (int) $api->id)) {
                $conflicts[] = ['operation_key' => $key, 'code' => 'SAND_IAM_ROUTE_BINDING_CONFLICT'];
                continue;
            }
            if ($binding !== null && (string) $binding->source !== 'openapi') {
                $conflicts[] = ['operation_key' => $key, 'code' => 'SAND_IAM_ROUTE_BINDING_OWNERSHIP_CONFLICT'];
                continue;
            }
            $changes[] = [
                'object_type' => 'api_route_binding',
                'object_key' => $key,
                'operation' => $binding === null ? 'create' : ((int) $binding->status === 1 ? 'unchanged' : 'enable'),
                'before' => $binding?->toArray(),
                'after' => [
                    'application_id' => (int) $application->id,
                    'api_code' => $mapping['api_code'],
                    'api_version' => $mapping['api_version'],
                    'http_method' => $operation['method'],
                    'route_template' => $operation['route_template'],
                    'source' => 'openapi',
                    'status' => 1,
                ],
            ];
        }

        if ($normalized['disable_missing']) {
            $bindings = ApiRouteBinding::where('application_id', (int) $application->id)
                ->where('source', 'openapi')->where('status', 1);
            if ($lock) $bindings->lock(true);
            foreach ($bindings->order('id')->select() as $binding) {
                $routeKey = (string) $binding->http_method . ' ' . (string) $binding->route_template;
                if (isset($normalized['operation_keys'][$routeKey])) continue;
                $current['route_bindings']['disable:' . (int) $binding->id] = $binding->toArray();
                $changes[] = [
                    'object_type' => 'api_route_binding',
                    'object_key' => $routeKey,
                    'operation' => 'disable',
                    'before' => $binding->toArray(),
                    'after' => ['status' => 2],
                ];
            }
        }
        ksort($current['resources'], SORT_STRING);
        ksort($current['actions'], SORT_STRING);
        ksort($current['api_resources'], SORT_STRING);
        ksort($current['route_bindings'], SORT_STRING);
        $previewHash = hash('sha256', $this->canonical([
            'organization_id' => (int) $organization->id,
            'application_id' => (int) $application->id,
            'environment_id' => (int) $environment->id,
            'operations' => $normalized['operations'],
            'mappings' => $normalized['mappings'],
            'disable_missing' => $normalized['disable_missing'],
            'current' => $current,
        ]));

        return [
            'dry_run' => true,
            'can_apply' => $unmapped === [] && $conflicts === [],
            'organization_id' => (int) $organization->id,
            'application_id' => (int) $application->id,
            'environment_id' => (int) $environment->id,
            'operation_count' => count($normalized['operations']),
            'operations' => $normalized['operations'],
            'unmapped' => $unmapped,
            'conflicts' => $conflicts,
            'changes' => $changes,
            'preview_hash' => $previewHash,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function apply(array $input, string $expectedPreviewHash, int $adminId, string $requestId): array
    {
        if ($adminId <= 0) throw new ApiException('SAND_IAM_ADMIN_REQUIRED', 401);
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedPreviewHash) !== 1) {
            throw new ApiException('SAND_IAM_OPENAPI_IMPORT_APPLY_REQUIRED: preview_hash 无效', 400);
        }
        $requestId = RequestId::normalize($requestId);
        Db::startTrans();
        try {
            $preview = $this->preview($input, true);
            if (!hash_equals((string) $preview['preview_hash'], $expectedPreviewHash)) {
                throw new ApiException('SAND_IAM_OPENAPI_IMPORT_PREVIEW_STALE: 接口目录已变化，请重新预览', 409);
            }
            if ($preview['can_apply'] !== true) {
                throw new ApiException('SAND_IAM_OPENAPI_IMPORT_APPLY_BLOCKED: 仍有未映射接口或冲突', 409);
            }
            $normalized = $this->normalize($input);
            $applicationId = (int) $preview['application_id'];
            $desiredRoutes = [];
            foreach ($normalized['operations'] as $operation) {
                $key = $operation['operation_key'];
                $mapping = $normalized['mappings'][$key];
                $resource = Resource::where('application_id', $applicationId)
                    ->where('code', $mapping['resource_code'])->where('status', 1)->lock(true)->find();
                if ($resource === null) throw new ApiException('SAND_IAM_OPENAPI_IMPORT_PREVIEW_STALE', 409);
                $this->publishedAction($applicationId, $mapping['action'], true);
                $payload = $this->apiPayload($applicationId, (int) $resource->id, $operation, $mapping);
                $api = ApiResource::where('application_id', $applicationId)
                    ->where('code', $mapping['api_code'])->where('api_version', $mapping['api_version'])
                    ->lock(true)->find();
                if ($api === null) {
                    $api = ApiResource::create($payload + [
                        'created_by' => $adminId,
                        'updated_by' => $adminId,
                    ]);
                } else {
                    if ($this->immutableApiConflict($api, $payload) !== null) {
                        throw new ApiException('SAND_IAM_OPENAPI_IMPORT_PREVIEW_STALE', 409);
                    }
                    $api->save([
                        'name' => $payload['name'],
                        'audience' => $payload['audience'],
                        'required_scope' => $payload['required_scope'],
                        'risk_level' => $payload['risk_level'],
                        'description' => $payload['description'],
                        'status' => 1,
                        'updated_by' => $adminId,
                    ]);
                }
                $fingerprint = hash('sha256', $operation['method'] . "\0" . $operation['route_template']);
                $binding = ApiRouteBinding::where('application_id', $applicationId)
                    ->where('http_method', $operation['method'])
                    ->where('route_template', $operation['route_template'])->lock(true)->find();
                if ($binding === null) {
                    ApiRouteBinding::create([
                        'application_id' => $applicationId,
                        'api_resource_id' => (int) $api->id,
                        'http_method' => $operation['method'],
                        'route_template' => $operation['route_template'],
                        'route_fingerprint' => $fingerprint,
                        'source' => 'openapi',
                        'last_seen_time' => date('Y-m-d H:i:s'),
                        'status' => 1,
                        'created_by' => $adminId,
                        'updated_by' => $adminId,
                    ]);
                } else {
                    if ((int) $binding->api_resource_id !== (int) $api->id || (string) $binding->source !== 'openapi') {
                        throw new ApiException('SAND_IAM_OPENAPI_IMPORT_PREVIEW_STALE', 409);
                    }
                    $binding->save([
                        'route_fingerprint' => $fingerprint,
                        'last_seen_time' => date('Y-m-d H:i:s'),
                        'status' => 1,
                        'updated_by' => $adminId,
                    ]);
                }
                $desiredRoutes[$key] = true;
            }
            $disabled = 0;
            if ($normalized['disable_missing']) {
                foreach (ApiRouteBinding::where('application_id', $applicationId)
                    ->where('source', 'openapi')->where('status', 1)->lock(true)->select() as $binding) {
                    $key = (string) $binding->http_method . ' ' . (string) $binding->route_template;
                    if (isset($desiredRoutes[$key])) continue;
                    $binding->save([
                        'status' => 2,
                        'last_seen_time' => date('Y-m-d H:i:s'),
                        'updated_by' => $adminId,
                    ]);
                    $disabled++;
                }
            }
            (new AuditWriter())->write(
                'admin',
                (string) $adminId,
                (int) $preview['organization_id'],
                $applicationId,
                'api.openapi_import',
                'application',
                $applicationId,
                'succeeded',
                $requestId,
                [
                    'operation_count' => count($normalized['operations']),
                    'disabled_route_count' => $disabled,
                    'disable_missing' => $normalized['disable_missing'],
                ],
            );
            Db::commit();
            return [
                'dry_run' => false,
                'application_id' => $applicationId,
                'environment_id' => (int) $preview['environment_id'],
                'operation_count' => count($normalized['operations']),
                'disabled_route_count' => $disabled,
                'preview_hash' => $expectedPreviewHash,
            ];
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   organization_code:string,
     *   application_code:string,
     *   environment_code:string,
     *   operations:list<array<string,string>>,
     *   operation_keys:array<string,true>,
     *   mappings:array<string,array<string,string>>,
     *   disable_missing:bool
     * }
     */
    private function normalize(array $input): array
    {
        $allowed = ['organization_code', 'application_code', 'environment_code', 'document', 'mappings', 'disable_missing'];
        if (array_diff(array_keys($input), $allowed) !== []) $this->invalid('请求包含未知字段');
        $organizationCode = $this->code($input['organization_code'] ?? null, 'organization_code');
        $applicationCode = $this->code($input['application_code'] ?? null, 'application_code');
        $environmentCode = $this->code($input['environment_code'] ?? null, 'environment_code');
        $document = $input['document'] ?? null;
        if (!is_array($document) || array_is_list($document)) $this->invalid('document 必须是 OpenAPI JSON 对象');
        $operations = OpenApiImportDocument::operations($document);
        $operationKeys = array_fill_keys(array_column($operations, 'operation_key'), true);
        $rawMappings = $input['mappings'] ?? [];
        if (!is_array($rawMappings) || !array_is_list($rawMappings)) $this->invalid('mappings 必须是列表');
        $mappings = [];
        $mappedApis = [];
        foreach ($rawMappings as $index => $mapping) {
            if (!is_array($mapping) || array_is_list($mapping)) $this->invalid("mappings[{$index}] 必须是对象");
            $mappingAllowed = ['operation_key', 'api_code', 'api_version', 'resource_code', 'action', 'audience', 'required_scope'];
            if (array_diff(array_keys($mapping), $mappingAllowed) !== []) $this->invalid("mappings[{$index}] 包含未知字段");
            $key = trim((string) ($mapping['operation_key'] ?? ''));
            if (!isset($operationKeys[$key])) $this->invalid("mappings[{$index}].operation_key 不属于当前文档");
            if (isset($mappings[$key])) $this->invalid("mappings 中的 {$key} 重复");
            $audience = trim((string) ($mapping['audience'] ?? ''));
            if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$#D', $audience) !== 1) {
                $this->invalid("mappings[{$index}].audience 格式不正确");
            }
            $requiredScope = trim((string) ($mapping['required_scope'] ?? ''));
            if ($requiredScope !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/D', $requiredScope) !== 1) {
                $this->invalid("mappings[{$index}].required_scope 格式不正确");
            }
            $apiCode = trim((string) ($mapping['api_code'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_.:-]{1,95}$/D', $apiCode) !== 1) {
                $this->invalid("mappings[{$index}].api_code 格式不正确");
            }
            $version = trim((string) ($mapping['api_version'] ?? 'v1'));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/D', $version) !== 1) {
                $this->invalid("mappings[{$index}].api_version 格式不正确");
            }
            $apiKey = $apiCode . '@' . $version;
            if (isset($mappedApis[$apiKey])) {
                $this->invalid("mappings[{$index}] 与其他 operation 重复映射到 {$apiKey}");
            }
            $mappedApis[$apiKey] = true;
            $mappings[$key] = [
                'operation_key' => $key,
                'api_code' => $apiCode,
                'api_version' => $version,
                'resource_code' => $this->code($mapping['resource_code'] ?? null, "mappings[{$index}].resource_code"),
                'action' => ApplicationBusinessActionCatalog::code((string) ($mapping['action'] ?? '')),
                'audience' => $audience,
                'required_scope' => $requiredScope,
            ];
        }
        ksort($mappings, SORT_STRING);
        $disableMissing = $input['disable_missing'] ?? false;
        if (!is_bool($disableMissing)) $this->invalid('disable_missing 必须是布尔值');
        return [
            'organization_code' => $organizationCode,
            'application_code' => $applicationCode,
            'environment_code' => $environmentCode,
            'operations' => $operations,
            'operation_keys' => $operationKeys,
            'mappings' => $mappings,
            'disable_missing' => $disableMissing,
        ];
    }

    /**
     * @param array<string,mixed> $normalized
     * @return array{0:Organization,1:Application,2:Environment}
     */
    private function context(array $normalized, bool $lock): array
    {
        $organizationQuery = Organization::where('code', $normalized['organization_code'])->where('status', 1);
        if ($lock) $organizationQuery->lock(true);
        $organization = $organizationQuery->find();
        $applicationQuery = $organization === null ? null : Application::where('organization_id', (int) $organization->id)
            ->where('code', $normalized['application_code'])->where('status', 1);
        if ($lock && $applicationQuery !== null) $applicationQuery->lock(true);
        $application = $applicationQuery?->find();
        $environmentQuery = $application === null ? null : Environment::where('application_id', (int) $application->id)
            ->where('code', $normalized['environment_code'])->where('status', 1);
        if ($lock && $environmentQuery !== null) $environmentQuery->lock(true);
        $environment = $environmentQuery?->find();
        if ($organization === null || $application === null || $environment === null) {
            throw new ApiException('SAND_IAM_OPENAPI_IMPORT_CONTEXT_NOT_FOUND: 客户主体、接入应用或应用环境不存在、已停用或归属不一致', 404);
        }
        return [$organization, $application, $environment];
    }

    /** @param array<string,string> $operation @param array<string,string> $mapping @return array<string,mixed> */
    private function apiPayload(int $applicationId, int $resourceId, array $operation, array $mapping): array
    {
        return [
            'application_id' => $applicationId,
            'resource_id' => $resourceId,
            'code' => $mapping['api_code'],
            'name' => $operation['name'],
            'action' => $mapping['action'],
            'operation' => $operation['operation'],
            'api_version' => $mapping['api_version'],
            'audience' => $mapping['audience'],
            'required_scope' => $mapping['required_scope'],
            'risk_level' => $operation['risk_level'],
            'description' => $operation['description'],
            'status' => 1,
        ];
    }

    /** @param array<string,mixed> $desired */
    private function immutableApiConflict(ApiResource $api, array $desired): ?string
    {
        foreach (['resource_id', 'action', 'operation'] as $field) {
            if ((string) $api->{$field} !== (string) $desired[$field]) {
                return 'SAND_IAM_OPENAPI_IMPORT_API_OWNERSHIP_CONFLICT';
            }
        }
        return null;
    }

    /** @param array<string,mixed> $desired */
    private function mutableApiChanged(ApiResource $api, array $desired): bool
    {
        foreach (['name', 'audience', 'required_scope', 'risk_level', 'description', 'status'] as $field) {
            if ((string) $api->{$field} !== (string) $desired[$field]) return true;
        }
        return false;
    }

    private function code(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/D', $value) !== 1) $this->invalid("{$field} 格式不正确");
        return $value;
    }

    private function errorCode(ApiException $exception): string
    {
        return preg_match('/^(SAND_IAM_[A-Z0-9_]+)/', $exception->getMessage(), $matches) === 1
            ? $matches[1]
            : 'SAND_IAM_OPENAPI_IMPORT_ACTION_INVALID';
    }

    private function publishedAction(int $applicationId, string $action, bool $lock): ApplicationBusinessAction
    {
        $query = ApplicationBusinessAction::where('application_id', $applicationId)
            ->where('code', $action);
        if ($lock) $query->lock(true);
        $declaration = $query->find();
        if ($declaration === null) {
            throw new ApiException('SAND_IAM_APPLICATION_ACTION_UNDECLARED: 业务动作未在所属接入应用声明', 409);
        }
        if ((int) $declaration->status !== ApplicationBusinessActionCatalog::STATUS_ENABLED) {
            throw new ApiException('SAND_IAM_APPLICATION_ACTION_DISABLED: 业务动作已停用', 403);
        }
        if ((string) $declaration->state !== ApplicationBusinessActionCatalog::STATE_PUBLISHED) {
            throw new ApiException('SAND_IAM_OPENAPI_IMPORT_ACTION_NOT_PUBLISHED: 业务动作尚未发布', 409);
        }
        return $declaration;
    }

    private function invalid(string $message): never
    {
        throw new ApiException('SAND_IAM_OPENAPI_IMPORT_INVALID: ' . $message, 400);
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
