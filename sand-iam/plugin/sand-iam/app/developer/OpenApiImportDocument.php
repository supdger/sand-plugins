<?php

declare(strict_types=1);

namespace plugin\SandIam\app\developer;

use plugin\sandadmin\exception\ApiException;

/**
 * Reads the small, non-secret operation catalog needed by SandIAM.
 *
 * The original OpenAPI document is never persisted or returned. Import callers
 * must explicitly map every discovered operation to an existing business
 * resource and published semantic action before anything can be applied.
 */
final class OpenApiImportDocument
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];
    private const RISKS = ['low', 'medium', 'high', 'critical'];
    private const MAX_OPERATIONS = 200;

    /**
     * @param array<string,mixed> $document
     * @return list<array{operation_key:string,method:string,route_template:string,name:string,description:string,risk_level:string,operation:string}>
     */
    public static function operations(array $document): array
    {
        $version = trim((string) ($document['openapi'] ?? ''));
        if (preg_match('/^3\.(?:0|1)\.\d+(?:[-+][A-Za-z0-9.-]+)?$/D', $version) !== 1) {
            self::invalid('只支持 OpenAPI 3.0 或 3.1 JSON 文档');
        }
        $paths = $document['paths'] ?? null;
        if (!is_array($paths) || array_is_list($paths)) {
            self::invalid('paths 必须是以路由模板为键的对象');
        }

        $operations = [];
        foreach ($paths as $path => $pathItem) {
            $route = self::routeTemplate($path);
            if (!is_array($pathItem) || array_is_list($pathItem)) {
                self::invalid("paths.{$route} 必须是对象");
            }
            foreach (self::METHODS as $method) {
                if (!array_key_exists($method, $pathItem)) continue;
                $operation = $pathItem[$method];
                if (!is_array($operation) || array_is_list($operation)) {
                    self::invalid(strtoupper($method) . " {$route} 必须是对象");
                }
                $name = trim((string) ($operation['summary'] ?? ''));
                if ($name === '' || mb_strlen($name) > 128) {
                    self::invalid(strtoupper($method) . " {$route} 的 summary 必填且不能超过 128 字符");
                }
                $description = trim((string) ($operation['description'] ?? ''));
                if (mb_strlen($description) > 500) {
                    self::invalid(strtoupper($method) . " {$route} 的 description 不能超过 500 字符");
                }
                $risk = self::risk($operation, strtoupper($method) . " {$route}");
                $httpMethod = strtoupper($method);
                $key = $httpMethod . ' ' . $route;
                if (isset($operations[$key])) {
                    self::invalid("{$key} 在规范化后重复");
                }
                $operations[$key] = [
                    'operation_key' => $key,
                    'method' => $httpMethod,
                    'route_template' => $route,
                    'name' => $name,
                    'description' => $description,
                    'risk_level' => $risk,
                    'operation' => self::semanticOperation($httpMethod, $route),
                ];
                if (count($operations) > self::MAX_OPERATIONS) {
                    self::invalid('单次最多导入 ' . self::MAX_OPERATIONS . ' 个接口');
                }
            }
        }
        if ($operations === []) self::invalid('文档中没有可导入的 GET、POST、PUT、PATCH 或 DELETE 接口');
        ksort($operations, SORT_STRING);
        return array_values($operations);
    }

    /** @param array<string,mixed> $operation */
    private static function risk(array $operation, string $key): string
    {
        $extension = $operation['x-sand-iam'] ?? null;
        $risk = is_array($extension)
            ? ($extension['riskLevel'] ?? $extension['risk_level'] ?? null)
            : null;
        if ($risk === null) $risk = $operation['x-sand-iam-risk-level'] ?? null;
        $risk = trim((string) $risk);
        if (!in_array($risk, self::RISKS, true)) {
            self::invalid("{$key} 必须用 x-sand-iam.riskLevel 标注 low、medium、high 或 critical");
        }
        return $risk;
    }

    private static function semanticOperation(string $method, string $route): string
    {
        return match ($method) {
            'GET' => preg_match('#/\{[^/{}]+\}$#', $route) === 1 ? 'read' : 'list',
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => throw new \LogicException('Unsupported OpenAPI method'),
        };
    }

    private static function routeTemplate(mixed $value): string
    {
        $route = trim((string) $value);
        if (
            $route === ''
            || strlen($route) > 255
            || $route[0] !== '/'
            || str_contains($route, '?')
            || str_contains($route, '#')
            || str_contains($route, '//')
            || preg_match('#^/[A-Za-z0-9._\\x7E!$&\'()*+,;=:@%/{\}\[\]-]*$#', $route) !== 1
        ) {
            self::invalid('paths 包含不支持的路由模板');
        }
        $withoutParameters = preg_replace('/\{[A-Za-z_][A-Za-z0-9_.-]*\}/', '', $route);
        if (!is_string($withoutParameters) || str_contains($withoutParameters, '{') || str_contains($withoutParameters, '}')) {
            self::invalid('paths 包含无效的路径参数');
        }
        return $route !== '/' ? rtrim($route, '/') : $route;
    }

    private static function invalid(string $message): never
    {
        throw new ApiException('SAND_IAM_OPENAPI_IMPORT_INVALID: ' . $message, 400);
    }
}
