<?php

declare(strict_types=1);

namespace plugin\SandIam\app\initialization;

use plugin\sandadmin\exception\ApiException;
use plugin\SandIam\app\developer\ApplicationBusinessActionCatalog;
use plugin\SandIam\app\runtime\ScopeMatcher;

final class InitializationPackage
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function normalize(array $input): array
    {
        self::assertSafe($input, 0);
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 1_048_576) throw new ApiException('SAND_IAM_INITIALIZATION_TOO_LARGE: 初始化包不能超过 1 MiB', 413);
        self::keys($input, ['format', 'package_code', 'organization_code', 'application', 'roles', 'user_types', 'resources', 'business_actions', 'identity_providers', 'policies']);
        if (($input['format'] ?? '') !== 'sand-iam.initialization/v1') throw new ApiException('SAND_IAM_INITIALIZATION_FORMAT_UNSUPPORTED', 400);
        $application = self::named($input['application'] ?? null, '接入应用');
        $normalized = [
            'format' => 'sand-iam.initialization/v1',
            'package_code' => self::code($input['package_code'] ?? null, '初始化包代码'),
            'organization_code' => self::code($input['organization_code'] ?? null, '客户主体代码'),
            'application' => $application,
            'roles' => self::namedList($input['roles'] ?? [], '角色'),
            'user_types' => self::namedList($input['user_types'] ?? [], '用户类型'),
            'resources' => self::resources($input['resources'] ?? []),
            'business_actions' => self::businessActions($input['business_actions'] ?? []),
            'identity_providers' => self::providers($input['identity_providers'] ?? []),
            'policies' => self::policies($input['policies'] ?? []),
        ];
        self::assertReferences($normalized);
        return $normalized;
    }

    /** @param array<string,mixed> $normalized */
    public static function hash(array $normalized): string
    {
        return hash('sha256', json_encode(self::canonical($normalized), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{code:string,name:string,status:int} */
    private static function named(mixed $value, string $label): array
    {
        if (!is_array($value)) throw new ApiException("SAND_IAM_INITIALIZATION_INVALID: {$label}必须是对象", 400);
        self::keys($value, ['code', 'name', 'status']);
        $name = trim((string) ($value['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 128 || preg_match('/[\p{Cc}]/u', $name)) throw new ApiException("SAND_IAM_INITIALIZATION_INVALID: {$label}名称无效", 400);
        $status = (int) ($value['status'] ?? 1);
        if (!in_array($status, [1, 2], true)) throw new ApiException("SAND_IAM_INITIALIZATION_INVALID: {$label}状态无效", 400);
        return ['code' => self::code($value['code'] ?? null, "{$label}代码"), 'name' => $name, 'status' => $status];
    }

    /** @return list<array{code:string,name:string,status:int}> */
    private static function namedList(mixed $value, string $label): array
    {
        $items = self::list($value, $label);
        $result = [];
        foreach ($items as $item) $result[] = self::named($item, $label);
        self::unique($result, 'code', $label);
        usort($result, static fn (array $left, array $right): int => $left['code'] <=> $right['code']);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function resources(mixed $value): array
    {
        $result = [];
        foreach (self::list($value, '业务资源') as $item) {
            if (!is_array($item)) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 业务资源必须是对象', 400);
            self::keys($item, ['code', 'name', 'owner_field', 'organization_field', 'status']);
            $named = self::named(['code' => $item['code'] ?? null, 'name' => $item['name'] ?? null, 'status' => $item['status'] ?? 1], '业务资源');
            $result[] = $named + ['owner_field' => self::field($item['owner_field'] ?? ''), 'organization_field' => self::field($item['organization_field'] ?? '')];
        }
        self::unique($result, 'code', '业务资源');
        usort($result, static fn (array $left, array $right): int => $left['code'] <=> $right['code']);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function providers(mixed $value): array
    {
        $result = [];
        foreach (self::list($value, '身份源') as $item) {
            if (!is_array($item)) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 身份源必须是对象', 400);
            self::keys($item, ['code', 'name', 'provider_type', 'status']);
            $named = self::named(['code' => $item['code'] ?? null, 'name' => $item['name'] ?? null, 'status' => $item['status'] ?? 1], '身份源');
            $type = (string) ($item['provider_type'] ?? 'local');
            if ($type !== 'local') throw new ApiException('SAND_IAM_INITIALIZATION_SECRET_REQUIRED: 初始化包只创建本地身份源；外部身份源须在管理台单独配置密钥', 400);
            $result[] = $named + ['provider_type' => $type];
        }
        self::unique($result, 'code', '身份源');
        usort($result, static fn (array $left, array $right): int => $left['code'] <=> $right['code']);
        return $result;
    }

    /** @return list<array{code:string,name:string,description:string,state:string,status:int}> */
    private static function businessActions(mixed $value): array
    {
        $result = [];
        foreach (self::list($value, '应用业务动作') as $item) {
            if (!is_array($item)) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 应用业务动作必须是对象', 400);
            self::keys($item, ['code', 'name', 'description', 'state', 'status']);
            try {
                $result[] = ApplicationBusinessActionCatalog::declaration($item);
            } catch (ApiException $exception) {
                throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: ' . $exception->getMessage(), 400);
            }
        }
        self::unique($result, 'code', '应用业务动作');
        usort($result, static fn (array $left, array $right): int => $left['code'] <=> $right['code']);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function policies(mixed $value): array
    {
        $result = [];
        foreach (self::list($value, '授权策略') as $item) {
            if (!is_array($item)) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 授权策略必须是对象', 400);
            self::keys($item, ['key', 'resource_code', 'role_code', 'action', 'effect', 'condition', 'scope', 'priority', 'state', 'status']);
            $key = self::code($item['key'] ?? null, '策略键');
            $action = trim((string) ($item['action'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $action)) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 策略动作代码无效', 400);
            $effect = (string) ($item['effect'] ?? '');
            $state = (string) ($item['state'] ?? 'draft');
            $status = (int) ($item['status'] ?? 1);
            if (!in_array($effect, ['allow', 'deny'], true) || !in_array($state, ['draft', 'published', 'revoked'], true) || !in_array($status, [1, 2], true)) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 策略效果、发布状态或启用状态无效', 400);
            if (($state === 'published' && $status !== 1) || ($state === 'revoked' && $status !== 2)) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 已发布策略必须启用，已撤销策略必须停用', 400);
            $condition = $item['condition'] ?? [];
            $scope = $item['scope'] ?? [];
            if (!is_array($condition) || !is_array($scope)) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 策略条件和数据范围必须是对象', 400);
            try {
                $matcher = new ScopeMatcher();
                $matcher->assertValid($condition, '策略生效条件');
                $matcher->assertValid($scope, '策略数据范围');
            } catch (ApiException) {
                throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 策略条件或数据范围不符合结构化规则', 400);
            }
            $result[] = [
                'key' => $key,
                'resource_code' => self::code($item['resource_code'] ?? null, '策略资源代码'),
                'role_code' => self::code($item['role_code'] ?? null, '策略角色代码'),
                'action' => $action,
                'effect' => $effect,
                'condition' => $condition,
                'scope' => $scope,
                'priority' => min(100000, max(-100000, (int) ($item['priority'] ?? 0))),
                'state' => $state,
                'status' => $status,
            ];
        }
        self::unique($result, 'key', '授权策略');
        usort($result, static fn (array $left, array $right): int => $left['key'] <=> $right['key']);
        return $result;
    }

    /** @param array<string,mixed> $package */
    private static function assertReferences(array $package): void
    {
        $roles = array_column($package['roles'], 'code');
        $resources = array_column($package['resources'], 'code');
        $actions = array_column($package['business_actions'], 'code');
        foreach ($package['policies'] as $policy) {
            if (!in_array($policy['role_code'], $roles, true) || !in_array($policy['resource_code'], $resources, true)) {
                throw new ApiException('SAND_IAM_INITIALIZATION_REFERENCE_INVALID: 策略引用的角色或业务资源不在同一初始化包中', 400);
            }
            if (!in_array($policy['action'], $actions, true)) {
                throw new ApiException('SAND_IAM_INITIALIZATION_ACTION_UNDECLARED: 策略动作必须引用同一初始化包中已声明的应用业务动作', 400);
            }
        }
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 500) throw new ApiException("SAND_IAM_INITIALIZATION_INVALID: {$label}必须是最多 500 项的列表", 400);
        return $value;
    }

    /** @param list<array<string,mixed>> $items */
    private static function unique(array $items, string $field, string $label): void
    {
        $values = array_column($items, $field);
        if (count($values) !== count(array_unique($values))) throw new ApiException("SAND_IAM_INITIALIZATION_INVALID: {$label}{$field}重复", 400);
    }

    private static function code(mixed $value, string $label): string
    {
        $code = trim((string) $value);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code)) throw new ApiException("SAND_IAM_INITIALIZATION_INVALID: {$label}须为 2–64 位小写字母、数字、短横线或下划线", 400);
        return $code;
    }

    private static function field(mixed $value): string
    {
        $field = trim((string) $value);
        if ($field !== '' && !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field)) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 业务字段名无效', 400);
        return $field;
    }

    /** @param array<string,mixed> $value @param list<string> $allowed */
    private static function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) throw new ApiException('SAND_IAM_INITIALIZATION_UNKNOWN_FIELD: 初始化包包含当前版本不认识的字段', 400);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(self::canonical(...), $value);
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = self::canonical($item);
        return $value;
    }

    /** @param array<array-key,mixed> $value */
    private static function assertSafe(array $value, int $depth): void
    {
        if ($depth > 12) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 初始化包嵌套过深', 400);
        $blocked = ['password', 'secret', 'client_secret', 'access_token', 'refresh_token', 'private_key', 'shared_secret', 'encrypted_config', 'credential'];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), $blocked, true)) throw new ApiException('SAND_IAM_INITIALIZATION_SENSITIVE_FIELD: 初始化包不能包含密码、令牌、密钥或凭证', 400);
            if (is_array($item)) self::assertSafe($item, $depth + 1);
            elseif (!is_scalar($item) && $item !== null) throw new ApiException('SAND_IAM_INITIALIZATION_INVALID: 初始化包包含无法序列化的值', 400);
        }
    }
}
