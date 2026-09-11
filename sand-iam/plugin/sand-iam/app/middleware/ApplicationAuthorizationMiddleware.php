<?php

declare(strict_types=1);

namespace plugin\SandIam\app\middleware;

use plugin\SandIam\app\runtime\ApplicationAuthorizationService;
use plugin\sandadmin\exception\ApiException;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * Consumer routes opt in with ->setParams(['sand_iam' => [...]]) and this
 * middleware.  The route path is only resolved to a registered semantic API;
 * it never becomes a policy key.
 */
final class ApplicationAuthorizationMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $route = $request->route;
        $config = $route?->param('sand_iam');
        if (!is_array($config)) {
            throw new ApiException('SAND_IAM_ROUTE_CONFIGURATION_REQUIRED', 500);
        }
        $attributes = $config['attributes'] ?? [];
        if (is_callable($attributes)) {
            $attributes = $attributes($request);
        }
        if (!is_array($attributes)) {
            throw new ApiException('SAND_IAM_ROUTE_CONFIGURATION_INVALID', 500);
        }
        $authorization = trim((string) $request->header('Authorization', ''));
        if (!str_starts_with($authorization, 'Bearer ')) {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        $authorizationService = new ApplicationAuthorizationService();
        $decision = $authorizationService->decideRoute(
            trim(substr($authorization, 7)),
            trim((string) ($config['organization_code'] ?? '')),
            trim((string) ($config['application_code'] ?? '')),
            strtoupper((string) $request->method()),
            (string) ($route?->getPath() ?? ''),
            $attributes,
            (string) $request->header('X-Request-Id', ''),
        );
        if (($decision['allowed'] ?? false) !== true) {
            throw new ApiException((string) ($decision['code'] ?? 'SAND_IAM_POLICY_DENIED'), 403);
        }
        $request->sandIamAuthorization = $decision;
        $request->sandIamRequestId = (string) $decision['request_id'];

        $scopeConfig = $config['entity_scope'] ?? null;
        $sideEffectOperation = in_array((string) ($decision['operation'] ?? ''), ['create', 'update', 'delete', 'export', 'batch'], true);
        if ($scopeConfig === null && ((array) ($decision['scope'] ?? []) !== [] || $sideEffectOperation)) {
            $code = $sideEffectOperation ? 'SAND_IAM_ENTITY_SCOPE_RESOLVER_REQUIRED' : 'SAND_IAM_ENTITY_SCOPE_CONFIGURATION_REQUIRED';
            throw new ApiException($code . ': 带数据范围或可能产生副作用的业务路由必须在 handler 前加载真实对象并复核', 500);
        }
        if ($scopeConfig === null) {
            return $handler($request);
        }
        if (!is_array($scopeConfig) || !in_array($scopeConfig['mode'] ?? null, ['entity', 'collection'], true) || !is_callable($scopeConfig['attributes'] ?? null)) {
            throw new ApiException('SAND_IAM_ENTITY_SCOPE_CONFIGURATION_INVALID: entity_scope 必须声明 mode 和实体属性解析器', 500);
        }
        $guard = $authorizationService->entityScopeGuard(
            $decision,
            (string) $scopeConfig['mode'],
            $scopeConfig['attributes'],
        );
        $resolver = $scopeConfig['resolver'] ?? null;
        if (!is_callable($resolver)) {
            throw new ApiException('SAND_IAM_ENTITY_SCOPE_RESOLVER_REQUIRED: 业务路由必须在 handler 前加载真实对象并完成数据范围校验', 500);
        }
        $resolved = $resolver($request, $decision);
        if ($scopeConfig['mode'] === 'entity') {
            if (!is_object($resolved)) {
                throw new ApiException('SAND_IAM_ENTITY_SCOPE_ENTITY_INVALID: 实体解析器必须返回已加载的业务对象', 500);
            }
            $guard->assertEntity($resolved);
        } else {
            if (!is_iterable($resolved)) {
                throw new ApiException('SAND_IAM_ENTITY_SCOPE_ENTITY_INVALID: 集合解析器必须返回已加载的业务对象集合', 500);
            }
            $guard->assertCollection($resolved);
        }
        return $handler($request);
    }
}
