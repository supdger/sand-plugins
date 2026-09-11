<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$package = dirname(__DIR__);
$root = dirname(__DIR__, 3);

$checks = [
    $package . '/app/runtime/ApiGovernanceService.php' => [
        'resolveRoute',
        'observeRoute',
        'openApiOperation',
        'SAND_IAM_ROUTE_BINDING_CONFLICT',
        'SAND_IAM_ROUTE_BINDING_OWNERSHIP_CONFLICT',
        'RequestId::normalize',
        "hash('sha256', \$method . \"\\0\" . \$routeTemplate)",
    ],
    $package . '/app/developer/RouteSyncManifest.php' => [
        "sand-iam.route-sync/v1",
        "array_key_exists('sand_iam', \$route)",
        'SAND_IAM_ROUTE_SYNC_DUPLICATE_ROUTE',
    ],
    $package . '/app/runtime/RouteBindingSynchronizer.php' => [
        'RouteSyncManifest::normalize',
        'observeRoute',
        'SAND_IAM_ROUTE_SYNC_APPLY_BLOCKED',
        "'route_scan'",
        "'忽略未标记路由'",
        'SAND_IAM_ROUTE_SYNC_EXTERNAL_BINDING_UNCHANGED',
        'childRequestId',
    ],
    $package . '/app/runtime/ApplicationAuthorizationService.php' => [
        'authenticatedPrincipal',
        'verifyAccessTokenForAudience',
        'PolicyAuthorizer',
        "(string) \$api->audience",
        "(string) \$api->required_scope",
        'entityScopeGuard',
        "'request_id' => \$requestId",
    ],
    $package . '/app/middleware/ApplicationAuthorizationMiddleware.php' => [
        "param('sand_iam')",
        'getPath()',
        'sandIamAuthorization',
        'SAND_IAM_ROUTE_CONFIGURATION_REQUIRED',
        'SAND_IAM_ENTITY_SCOPE_CONFIGURATION_REQUIRED',
        'SAND_IAM_ENTITY_SCOPE_RESOLVER_REQUIRED',
        '$resolved = $resolver($request, $decision)',
        'return $handler($request)',
    ],
    $package . '/app/runtime/EntityScopeGuard.php' => [
        'assertEntity',
        'assertCollection',
        'SAND_IAM_ENTITY_SCOPE_CHECK_REQUIRED',
        'Request-body attributes never enter this guard',
    ],
    $package . '/app/api/controller/AuthorizationController.php' => [
        "post('organization_code'",
        "post('api_code'",
        "post('api_version'",
        "header('Authorization'",
        'RequestId::fromRequest',
    ],
    $package . '/app/admin/controller/ApiResourceController.php' => [
        "'resource_id'",
        "'action'",
        "'operation'",
        "'audience'",
        "'required_scope'",
        "'risk_level'",
    ],
    $package . '/config/route.php' => [
        "'api-resource' => ApiResourceController::class",
        "'api-route-binding' => ApiRouteBindingController::class",
        "'/api/sand-iam/v1/authorization/decide'",
        'disableDefaultRoute(AuthorizationController::class)',
    ],
    $root . '/docs/development/sand-iam-api-governance-v0.1.md' => [
        '权限判断使用“业务资源 + 语义动作”，不使用 HTTP 地址',
        '浏览器判断只能控制按钮、入口和提示，不能替代业务后端鉴权',
        '真实业务属性复核数据范围',
    ],
];

foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if ($content === false) {
        fwrite(STDERR, "unreadable {$file}\n");
        exit(1);
    }
    foreach ($fragments as $fragment) {
        if (!str_contains($content, $fragment)) {
            fwrite(STDERR, "missing {$fragment} in {$file}\n");
            exit(1);
        }
    }
}

$middleware = file_get_contents($package . '/app/middleware/ApplicationAuthorizationMiddleware.php');
if (!is_string($middleware)
    || strrpos($middleware, '$guard->assertEntity($resolved)') === false
    || strrpos($middleware, '$guard->assertCollection($resolved)') === false
    || strrpos($middleware, 'return $handler($request)') === false
    || max((int) strrpos($middleware, '$guard->assertEntity($resolved)'), (int) strrpos($middleware, '$guard->assertCollection($resolved)')) > (int) strrpos($middleware, 'return $handler($request)')) {
    fwrite(STDERR, "entity scope preflight can reach handler before its resolver check\n");
    exit(1);
}

$sourceMigration = $root . '/migrations/008_api_governance.pgsql';
$packageMigration = $package . '/migrations/008_api_governance.pgsql';
if (!is_file($sourceMigration) || !is_file($packageMigration) || hash_file('sha256', $sourceMigration) !== hash_file('sha256', $packageMigration)) {
    fwrite(STDERR, "root/plugin migration differs: 008_api_governance.pgsql\n");
    exit(1);
}
$migration = file_get_contents($sourceMigration);
foreach (['sand_iam_api_resource', 'sand_iam_api_route_binding', 'UNIQUE (application_id, code, api_version)', 'UNIQUE (application_id, http_method, route_template)', 'ON DELETE RESTRICT'] as $fragment) {
    if (!is_string($migration) || !str_contains($migration, $fragment)) {
        fwrite(STDERR, "migration missing {$fragment}\n");
        exit(1);
    }
}
if (preg_match('/\bAUTO_INCREMENT\b|\bUNSIGNED\b|\bENGINE\s*=/i', (string) $migration)) {
    fwrite(STDERR, "migration contains non-PostgreSQL syntax\n");
    exit(1);
}

$typescript = file_get_contents($root . '/sdk/typescript/src/index.ts');
if (!is_string($typescript) || preg_match('/\bas\s+any\b|:\s*any\b|@ts-ignore|@ts-nocheck/', $typescript)) {
    fwrite(STDERR, "TypeScript SDK contains a forbidden type escape\n");
    exit(1);
}

foreach ([$root . '/install.sql', $package . '/install.sql'] as $lifecycle) {
    $content = file_get_contents($lifecycle);
    if (!is_string($content) || !str_contains($content, 'sand_iam_api_resource') || !str_contains($content, 'sand_iam_api_route_binding')) {
        fwrite(STDERR, "lifecycle is not integrated: {$lifecycle}\n");
        exit(1);
    }
}
foreach ([$root . '/uninstall.sql', $package . '/uninstall.sql'] as $lifecycle) {
    $content = file_get_contents($lifecycle);
    if (!is_string($content) || !str_contains($content, 'DROP TABLE IF EXISTS sand_iam_api_route_binding') || !str_contains($content, 'DROP TABLE IF EXISTS sand_iam_api_resource')) {
        fwrite(STDERR, "uninstall is not integrated: {$lifecycle}\n");
        exit(1);
    }
}

echo "API governance contract checks passed\n";
