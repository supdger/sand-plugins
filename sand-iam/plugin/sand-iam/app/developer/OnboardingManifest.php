<?php

declare(strict_types=1);

namespace plugin\SandIam\app\developer;

use plugin\SandIam\app\initialization\InitializationPackage;
use plugin\sandadmin\exception\ApiException;

/**
 * Versioned developer handoff manifest. It deliberately references an
 * existing organization: onboarding is never an implicit tenant-creation API.
 */
final class OnboardingManifest
{
    public const FORMAT = 'sand-iam.onboarding/v1';

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function normalize(array $input): array
    {
        self::keys($input, ['format', 'operation_id', 'organization', 'initialization', 'environment', 'api_resources', 'routes', 'workload_client', 'service_grants']);
        if (($input['format'] ?? null) !== self::FORMAT) self::invalid('format 必须是 ' . self::FORMAT);
        $organization = $input['organization'] ?? null;
        if (!is_array($organization) || array_keys($organization) !== ['code']) self::invalid('organization 只能引用已存在客户主体：填写 code，不支持自动创建');
        $organizationCode = self::code($organization['code'] ?? null, 'organization.code');
        $initialization = $input['initialization'] ?? null;
        if (!is_array($initialization)) self::invalid('initialization 必须是初始化包对象');
        $initialization['organization_code'] = $organizationCode;
        $initialization = InitializationPackage::normalize($initialization);
        $environment = self::named($input['environment'] ?? null, 'environment');
        $apiResources = self::apiResources($input['api_resources'] ?? []);
        $routes = $input['routes'] ?? [];
        if (!is_array($routes) || !array_is_list($routes)) self::invalid('routes 必须是列表');
        $routeManifest = RouteSyncManifest::normalize([
            'format' => RouteSyncManifest::FORMAT,
            'organization_code' => $organizationCode,
            'application_code' => $initialization['application']['code'],
            'environment_code' => $environment['code'],
            'routes' => $routes,
        ]);
        $client = self::client($input['workload_client'] ?? null);
        $grants = self::grants($input['service_grants'] ?? [], (string) $client['audience']);
        $apiCodes = array_flip(array_map(static fn (array $api): string => $api['code'] . "\0" . $api['api_version'], $apiResources));
        foreach ($routeManifest['routes'] as $route) {
            if (!isset($apiCodes[$route['api_code'] . "\0" . $route['api_version']])) self::invalid('routes 引用了本清单未声明的 api_resources 接口：' . $route['api_code'], 'SAND_IAM_ONBOARDING_API_REFERENCE_INVALID');
        }
        return [
            'format' => self::FORMAT,
            'operation_id' => self::operationId($input['operation_id'] ?? ''),
            'organization' => ['code' => $organizationCode],
            'initialization' => $initialization,
            'environment' => $environment,
            'api_resources' => $apiResources,
            'route_manifest' => $routeManifest,
            'workload_client' => $client,
            'service_grants' => $grants,
        ];
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public static function handoff(array $manifest): array
    {
        $application = $manifest['initialization']['application']['code'];
        $organization = $manifest['organization']['code'];
        $client = $manifest['workload_client'];
        $actions = ApplicationBusinessActionCatalog::sdkConstants($manifest['initialization']['business_actions']);
        return [
            'php_constants' => "<?php\n\nreturn " . var_export(['organization_code' => $organization, 'application_code' => $application, 'audience' => $client['audience'], 'actions' => $actions], true) . ";\n",
            'typescript_constants' => 'export const sandIam = ' . json_encode(['organizationCode' => $organization, 'applicationCode' => $application, 'audience' => $client['audience'], 'actions' => $actions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . " as const;\n",
            'dart_constants' => "const sandIamOrganizationCode = '" . $organization . "';\nconst sandIamApplicationCode = '" . $application . "';\nconst sandIamAudience = '" . $client['audience'] . "';\nconst sandIamActions = <String, String>" . json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n",
            'env_template' => "SAND_IAM_ORGANIZATION_CODE={$organization}\nSAND_IAM_APPLICATION_CODE={$application}\nSAND_IAM_AUDIENCE={$client['audience']}\nSAND_IAM_WORKLOAD_CREDENTIAL=\n",
            'next_steps' => ['将一次性返回的 workload credential 写入密钥管理系统，不要提交到 .env 示例。', '执行路由同步预览并确认新增/更新/冲突为零后 apply。', '用一个允许、一个拒绝请求和同一 X-Request-Id 核对 SandIAM 审计。'],
        ];
    }

    /** @return array{code:string,name:string,status:int} */
    private static function named(mixed $value, string $field): array
    {
        if (!is_array($value)) self::invalid("{$field} 必须是对象");
        self::keys($value, ['code', 'name', 'status']);
        return ['code' => self::code($value['code'] ?? null, "{$field}.code"), 'name' => self::name($value['name'] ?? null, "{$field}.name"), 'status' => self::status($value['status'] ?? 1, "{$field}.status")];
    }

    /** @return list<array<string,mixed>> */
    private static function apiResources(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) self::invalid('api_resources 必须是非空列表');
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item)) self::invalid('api_resources 项必须是对象');
            self::keys($item, ['code', 'name', 'resource_code', 'action', 'operation', 'api_version', 'audience', 'required_scope', 'risk_level', 'description', 'status']);
            $action = ApplicationBusinessActionCatalog::code((string) ($item['action'] ?? ''));
            $result[] = ['code' => ApplicationBusinessActionCatalog::code((string) ($item['code'] ?? '')), 'name' => self::name($item['name'] ?? null, 'api_resources.name'), 'resource_code' => self::code($item['resource_code'] ?? null, 'api_resources.resource_code'), 'action' => $action, 'operation' => self::operation($item['operation'] ?? null), 'api_version' => self::version($item['api_version'] ?? 'v1'), 'audience' => trim((string) ($item['audience'] ?? '')), 'required_scope' => trim((string) ($item['required_scope'] ?? '')), 'risk_level' => self::risk($item['risk_level'] ?? null), 'description' => trim((string) ($item['description'] ?? '')), 'status' => self::status($item['status'] ?? 1, 'api_resources.status')];
        }
        $keys = array_map(static fn (array $item): string => $item['code'] . "\0" . $item['api_version'], $result);
        if (count($keys) !== count(array_unique($keys))) self::invalid('api_resources 的 code + api_version 不能重复');
        return $result;
    }

    /** @return array{code:string,name:string,audience:string,status:int} */
    private static function client(mixed $value): array
    {
        if (!is_array($value)) self::invalid('workload_client 必须是对象');
        self::keys($value, ['code', 'name', 'audience', 'status']);
        $audience = trim((string) ($value['audience'] ?? ''));
        if ($audience === '' || strlen($audience) > 128) self::invalid('workload_client.audience 必填且不能超过 128 字符');
        return ['code' => self::code($value['code'] ?? null, 'workload_client.code'), 'name' => self::name($value['name'] ?? null, 'workload_client.name'), 'audience' => $audience, 'status' => self::status($value['status'] ?? 1, 'workload_client.status')];
    }

    /** @return list<array{service_code:string,action_code:string,audience:string,status:int}> */
    private static function grants(mixed $value, string $defaultAudience): array
    {
        if (!is_array($value) || !array_is_list($value)) self::invalid('service_grants 必须是列表');
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item)) self::invalid('service_grants 项必须是对象');
            self::keys($item, ['service_code', 'action_code', 'audience', 'status']);
            $result[] = ['service_code' => self::code($item['service_code'] ?? null, 'service_grants.service_code'), 'action_code' => self::actionCode($item['action_code'] ?? null, 'service_grants.action_code'), 'audience' => trim((string) ($item['audience'] ?? $defaultAudience)), 'status' => self::status($item['status'] ?? 1, 'service_grants.status')];
        }
        return $result;
    }

    private static function operationId(mixed $value): string { $value = trim((string) $value); return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/', $value) ? $value : 'onb_' . substr(hash('sha256', json_encode($value)), 0, 32); }
    private static function code(mixed $value, string $field): string { $value = trim((string) $value); if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $value)) self::invalid("{$field} 格式不正确"); return $value; }
    private static function actionCode(mixed $value, string $field): string { $value = trim((string) $value); if (!preg_match('/^[a-z][a-z0-9_.:-]{1,95}$/', $value)) self::invalid("{$field} 格式不正确"); return $value; }
    private static function name(mixed $value, string $field): string { $value = trim((string) $value); if ($value === '' || mb_strlen($value) > 128) self::invalid("{$field} 必填且不能超过 128 字符"); return $value; }
    private static function status(mixed $value, string $field): int { $value = (int) $value; if (!in_array($value, [1, 2], true)) self::invalid("{$field} 只能为 1 或 2"); return $value; }
    private static function version(mixed $value): string { $value = trim((string) $value); if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $value)) self::invalid('api_resources.api_version 格式不正确'); return $value; }
    private static function operation(mixed $value): string { $value = (string) $value; if (!in_array($value, ['list', 'read', 'create', 'update', 'delete', 'export', 'batch'], true)) self::invalid('api_resources.operation 不支持'); return $value; }
    private static function risk(mixed $value): string { $value = (string) $value; if (!in_array($value, ['low', 'medium', 'high', 'critical'], true)) self::invalid('api_resources.risk_level 不支持'); return $value; }
    /** @param array<string,mixed> $value @param list<string> $allowed */
    private static function keys(array $value, array $allowed): void { if (array_diff(array_keys($value), $allowed) !== []) self::invalid('清单包含当前版本不认识的字段'); }
    private static function invalid(string $message, string $code = 'SAND_IAM_ONBOARDING_MANIFEST_INVALID'): never { throw new ApiException($code . ': ' . $message, 400); }
}
