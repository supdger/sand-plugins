<?php

declare(strict_types=1);

namespace plugin\SandIam\app\developer;

use plugin\sandadmin\exception\ApiException;

/**
 * Stable, application-owned declaration for SandIAM-governed Webman routes.
 *
 * It deliberately does not inspect every route registered by the host. A route
 * participates only when its `sand_iam` block explicitly names an existing API
 * catalog entry. This keeps SandAdmin management routes and unrelated plugins
 * out of an application's authorization catalog.
 */
final class RouteSyncManifest
{
    public const FORMAT = 'sand-iam.route-sync/v1';

    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param array<string,mixed> $manifest
     * @return array{format:string,organization_code:string,application_code:string,environment_code:string,routes:list<array{method:string,route_template:string,api_code:string,api_version:string}>,ignored_count:int}
     */
    public static function normalize(array $manifest): array
    {
        if (($manifest['format'] ?? null) !== self::FORMAT) {
            self::invalid('格式必须是 ' . self::FORMAT);
        }
        $organizationCode = self::code($manifest['organization_code'] ?? null, '客户主体代码');
        $applicationCode = self::code($manifest['application_code'] ?? null, '接入应用代码');
        $environmentCode = self::code($manifest['environment_code'] ?? null, '应用环境代码');
        $declaredRoutes = $manifest['routes'] ?? null;
        if (!is_array($declaredRoutes) || array_is_list($declaredRoutes) === false) {
            self::invalid('routes 必须是路由列表');
        }

        $routes = [];
        $ignoredCount = 0;
        foreach ($declaredRoutes as $index => $route) {
            if (!is_array($route)) {
                self::invalid("routes[{$index}] 必须是对象");
            }
            if (!array_key_exists('sand_iam', $route) || $route['sand_iam'] === false || $route['sand_iam'] === null) {
                $ignoredCount++;
                continue;
            }
            if (!is_array($route['sand_iam'])) {
                self::invalid("routes[{$index}].sand_iam 必须是对象；未治理路由请省略该字段");
            }
            $method = strtoupper(trim((string) ($route['method'] ?? '')));
            if (!in_array($method, self::METHODS, true)) {
                self::invalid("routes[{$index}].method 不支持");
            }
            $template = self::routeTemplate($route['path'] ?? $route['route_template'] ?? null, $index);
            $apiCode = self::apiCode($route['sand_iam']['api_code'] ?? null, "routes[{$index}].sand_iam.api_code");
            $apiVersion = self::version($route['sand_iam']['api_version'] ?? 'v1', "routes[{$index}].sand_iam.api_version");
            $key = $method . "\0" . $template;
            if (isset($routes[$key])) {
                self::invalid("routes[{$index}] 与另一条已治理路由重复：{$method} {$template}", 'SAND_IAM_ROUTE_SYNC_DUPLICATE_ROUTE');
            }
            $routes[$key] = [
                'method' => $method,
                'route_template' => $template,
                'api_code' => $apiCode,
                'api_version' => $apiVersion,
            ];
        }
        if ($routes === []) {
            self::invalid('没有显式标记 sand_iam 的业务路由；不会扫描或登记所有 Webman 路由', 'SAND_IAM_ROUTE_SYNC_EMPTY');
        }
        ksort($routes, SORT_STRING);

        return [
            'format' => self::FORMAT,
            'organization_code' => $organizationCode,
            'application_code' => $applicationCode,
            'environment_code' => $environmentCode,
            'routes' => array_values($routes),
            'ignored_count' => $ignoredCount,
        ];
    }

    /**
     * Validates the internal shape returned by normalize().  This intentionally
     * does not inspect raw Webman `sand_iam` declarations: callers that already
     * hold a normalized manifest must not feed it through normalize() again.
     *
     * @param array<string,mixed> $manifest
     * @return array{format:string,organization_code:string,application_code:string,environment_code:string,routes:list<array{method:string,route_template:string,api_code:string,api_version:string}>,ignored_count:int}
     */
    public static function requireNormalized(array $manifest): array
    {
        self::exactKeys($manifest, ['format', 'organization_code', 'application_code', 'environment_code', 'routes', 'ignored_count'], '已规范化路由清单');
        if (($manifest['format'] ?? null) !== self::FORMAT) {
            self::invalid('已规范化路由清单格式不正确');
        }
        $organizationCode = self::code($manifest['organization_code'] ?? null, '客户主体代码');
        $applicationCode = self::code($manifest['application_code'] ?? null, '接入应用代码');
        $environmentCode = self::code($manifest['environment_code'] ?? null, '应用环境代码');
        $ignoredCount = $manifest['ignored_count'] ?? null;
        if (!is_int($ignoredCount) || $ignoredCount < 0) {
            self::invalid('已规范化路由清单的 ignored_count 不正确');
        }
        $declaredRoutes = $manifest['routes'] ?? null;
        if (!is_array($declaredRoutes) || !array_is_list($declaredRoutes) || $declaredRoutes === []) {
            self::invalid('已规范化路由清单 routes 必须是非空列表', 'SAND_IAM_ROUTE_SYNC_EMPTY');
        }

        $routes = [];
        foreach ($declaredRoutes as $index => $route) {
            if (!is_array($route)) {
                self::invalid("已规范化路由清单 routes[{$index}] 必须是对象");
            }
            self::exactKeys($route, ['method', 'route_template', 'api_code', 'api_version'], "已规范化路由清单 routes[{$index}]");
            $method = strtoupper(trim((string) ($route['method'] ?? '')));
            $template = self::routeTemplate($route['route_template'] ?? null, $index);
            $apiCode = self::apiCode($route['api_code'] ?? null, "已规范化路由清单 routes[{$index}].api_code");
            $apiVersion = self::version($route['api_version'] ?? null, "已规范化路由清单 routes[{$index}].api_version");
            if (!in_array($method, self::METHODS, true)
                || !is_string($route['method']) || $route['method'] !== $method
                || !is_string($route['route_template']) || $route['route_template'] !== $template
                || !is_string($route['api_code']) || $route['api_code'] !== $apiCode
                || !is_string($route['api_version']) || $route['api_version'] !== $apiVersion) {
                self::invalid("已规范化路由清单 routes[{$index}] 不是规范化格式");
            }
            $key = $method . "\0" . $template;
            if (isset($routes[$key])) {
                self::invalid("已规范化路由清单 routes[{$index}] 与另一条路由重复：{$method} {$template}", 'SAND_IAM_ROUTE_SYNC_DUPLICATE_ROUTE');
            }
            $routes[$key] = ['method' => $method, 'route_template' => $template, 'api_code' => $apiCode, 'api_version' => $apiVersion];
        }

        return [
            'format' => self::FORMAT,
            'organization_code' => $organizationCode,
            'application_code' => $applicationCode,
            'environment_code' => $environmentCode,
            'routes' => array_values($routes),
            'ignored_count' => $ignoredCount,
        ];
    }

    private static function code(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $value) !== 1) {
            self::invalid("{$field} 格式不正确");
        }
        return $value;
    }

    private static function apiCode(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (preg_match('/^[a-z][a-z0-9_.:-]{1,95}$/', $value) !== 1) {
            self::invalid("{$field} 格式不正确");
        }
        return $value;
    }

    private static function version(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $value) !== 1) {
            self::invalid("{$field} 格式不正确");
        }
        return $value;
    }

    private static function routeTemplate(mixed $value, int|string $index): string
    {
        $route = trim((string) $value);
        if (
            $route === ''
            || strlen($route) > 255
            || $route[0] !== '/'
            || str_contains($route, '?')
            || str_contains($route, '#')
            || str_contains($route, '//')
            || preg_match('~^/[A-Za-z0-9._\\x7E!$&\'()*+,;=:@%/{\}\[\]-]*$~', $route) !== 1
        ) {
            self::invalid("routes[{$index}].path 必须是 Webman 路由模板");
        }
        return $route !== '/' ? rtrim($route, '/') : $route;
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function exactKeys(array $value, array $keys, string $field): void
    {
        if (array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== []) {
            self::invalid("{$field} 字段不正确");
        }
    }

    private static function invalid(string $message, string $code = 'SAND_IAM_ROUTE_SYNC_MANIFEST_INVALID'): never
    {
        throw new ApiException($code . ': ' . $message, 400);
    }
}
