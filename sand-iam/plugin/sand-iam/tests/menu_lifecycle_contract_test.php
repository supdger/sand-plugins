<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$sandIamRoot = dirname(__DIR__, 3);
$lifecycleFiles = [
    $sandIamRoot . '/install.sql',
    $sandIamRoot . '/plugin/sand-iam/install.sql',
];

/**
 * Expand psql \ir directives so lifecycle contracts validate the SQL that a
 * package installation actually executes, rather than requiring migrations
 * to be copied into every entry file.
 *
 * @param array<string, true> $stack
 */
function expandedLifecycleSql(string $file, array $stack = []): string
{
    $resolved = realpath($file);
    if ($resolved === false || isset($stack[$resolved])) {
        throw new RuntimeException('Cannot resolve lifecycle include: ' . $file);
    }
    $stack[$resolved] = true;
    $sql = file_get_contents($resolved);
    if ($sql === false) {
        throw new RuntimeException('Cannot read lifecycle file: ' . $resolved);
    }

    return preg_replace_callback(
        '/^\s*\\\\ir\s+([^\r\n]+)\s*$/m',
        static function (array $matches) use ($resolved, $stack): string {
            $relative = trim($matches[1], " \t'\"");
            return expandedLifecycleSql(dirname($resolved) . '/' . $relative, $stack);
        },
        $sql
    ) ?? throw new RuntimeException('Cannot expand lifecycle includes: ' . $resolved);
}

$contents = [];
foreach ($lifecycleFiles as $file) {
    $sql = expandedLifecycleSql($file);
    foreach ([
        "WHERE \"code\" = 'SandIAM'",
        "'SandIAMOrganization','客户主体（组织）'",
        "'SandIAMApplication','接入应用'",
        "'SandIAMEnvironment','应用环境'",
        "'SandIAMWorkload','服务调用身份'",
        "'SandIAMIdentityProvider','身份源'",
        "'SandIAMConnection','客户与应用接入'",
        "'SandIAMPeopleAccess','应用用户与权限'",
        "'SandIAMAuditTroubleshooting','审计与排错'",
        "'SandIAMApiGovernance','接口与访问控制'",
        "'SandIAMApplicationBusinessAction','应用业务动作'",
        "'SandIAMApiResource','接口目录'",
        "'SandIAMApiRouteBinding','路由绑定'",
        "'SandIAMRouteManifest','路由清单'",
        "'SandIAMPolicySimulate','策略模拟'",
        '"is_hidden"',
        "WHERE NOT EXISTS (",
        'path_entity("path_code","entity_code")',
        'INSERT INTO "sand_system_role_menu" ("role_id","menu_id")',
        "('SandIAMAuditTroubleshooting','SandIAMAudit')",
        "('SandIAMApiGovernance','SandIAMApiResource')",
        "('SandIAMApiGovernance','SandIAMApiRouteBinding')",
        "('SandIAMApiGovernance','SandIAMPolicy')",
        "('SandIAMApiGovernance','SandIAMConnection')",
        "('SandIAMApplicationBusinessAction','SandIAMApiResource')",
        "('SandIAMApiResource','SandIAMApiResource')",
        "('SandIAMApiRouteBinding','SandIAMApiRouteBinding')",
        "('SandIAMRouteManifest','SandIAMConnection')",
        "('SandIAMPolicySimulate','SandIAMPolicy')",
        'route_menu_anchor("anchor_code","route_code") AS (',
        "('SandIAMConnection','SandIAMApiGovernance')",
        "('SandIAMConnection','SandIAMRouteManifest')",
        "('SandIAMPolicy','SandIAMApiGovernance')",
        "('SandIAMPolicy','SandIAMPolicySimulate')",
        'route_permission_anchor("anchor_slug","route_code") AS (',
        "('sand_iam:api_resource:index','SandIAMApiGovernance')",
        "('sand_iam:api_resource:index','SandIAMApplicationBusinessAction')",
        "('sand_iam:api_resource:index','SandIAMApiResource')",
        "('sand_iam:api_route_binding:index','SandIAMApiGovernance')",
        "('sand_iam:api_route_binding:index','SandIAMApiRouteBinding')",
        'route_permission_anchor."anchor_slug" = permission."slug"',
        'route_target("code","menu_id") AS (',
        'JOIN sandiam_root ON menu."parent_id" = sandiam_root."id"',
        'WHERE menu."type" = 2',
        'AND duplicate_route."type" = 2',
        'LEFT JOIN route_menu ON route_menu."code" = path_entity."path_code"',
        "'SandIAMApiGovernance'",
        "'SandIAMApplicationBusinessAction'",
        "'SandIAMApiResource'",
        "'SandIAMApiRouteBinding'",
        "'SandIAMRouteManifest'",
        "'SandIAMPolicySimulate'",
        'canonical_route("id","code") AS (',
        'DELETE FROM "sand_system_role_menu" duplicate',
        'USING "sand_system_role_menu" retained, "sand_system_menu" menu',
        "menu.\"code\" LIKE 'SandIAM%'",
        "menu.\"code\" LIKE 'sand\\_iam:%'",
        "ESCAPE '\\'",
        'route_menu("code") AS (',
        '"parent_id" = CASE WHEN route_menu."code" IS NULL THEN menu."parent_id" ELSE root."id" END',
        '"type" = CASE WHEN route_menu."code" IS NULL THEN menu."type" ELSE 2 END',
        "'/plugin/sand-iam/admin-scope/index'",
    ] as $required) {
        if (!str_contains($sql, $required)) {
            throw new RuntimeException($file . ' missing menu lifecycle contract: ' . $required);
        }
    }
    $contents[$file] = $sql;
}

foreach ($contents as $file => $sql) {
    foreach ([
        '/plugin/sand-iam/connection/index',
        '/plugin/sand-iam/people-access/index',
        '/plugin/sand-iam/audit-troubleshooting/index',
        '/plugin/sand-iam/api-governance/index',
        '/plugin/sand-iam/application-business-action/index',
        '/plugin/sand-iam/api-resource/index',
        '/plugin/sand-iam/api-route-binding/index',
        '/plugin/sand-iam/route-manifest/index',
        '/plugin/sand-iam/policy-simulate/index',
    ] as $visiblePathComponent) {
        if (!str_contains($sql, "'" . $visiblePathComponent . "'")) {
            throw new RuntimeException($file . ' missing visible task-path component: ' . $visiblePathComponent);
        }
    }

    if (!str_contains($sql, 'ELSE 1') && !str_contains($sql, "'SandIAMOrganization','客户主体（组织）','organization','/plugin/sand-iam/organization/index',89,1")) {
        throw new RuntimeException($file . ' does not hide entity pages from the flat navigation');
    }
}

$controllerPermissions = [];
foreach (glob($sandIamRoot . '/plugin/sand-iam/app/admin/controller/*.php') ?: [] as $controller) {
    $source = file_get_contents($controller);
    if ($source === false) {
        throw new RuntimeException('Cannot read controller: ' . $controller);
    }
    preg_match_all("/'(sand_iam:[a-z_]+:[a-zA-Z]+)'/", $source, $matches);
    foreach ($matches[1] as $permission) {
        $controllerPermissions[$permission] = true;
    }
}

foreach (array_keys($controllerPermissions) as $permission) {
    foreach ($contents as $file => $sql) {
        if (!str_contains($sql, "'" . $permission . "'")) {
            throw new RuntimeException($file . ' missing existing permission: ' . $permission);
        }
    }
}

foreach ($contents as $file => $sql) {
    foreach ([
        "'sand_iam:onboarding:preview'",
        "'sand_iam:onboarding:apply'",
        "'开发者接入预检'",
        "'确认应用开发者接入清单'",
        'permission.code NOT IN (',
        'permission_def.permission_code AS slug',
        'UPDATE sand_system_menu existing',
        'existing.slug IS DISTINCT FROM desired.permission_code',
    ] as $required) {
        if (!str_contains($sql, $required)) {
            throw new RuntimeException($file . ' missing onboarding permission boundary: ' . $required);
        }
    }
}

// Every task-path management page must have an idempotent hidden menu route.
// Runtime pages intentionally use an application-user Bearer token and are
// therefore excluded from the SandAdmin menu lifecycle.
$taskPathSource = file_get_contents($sandIamRoot . '/sandadmin-artd/src/views/plugin/sand-iam/api/taskPaths.ts');
if ($taskPathSource === false) {
    throw new RuntimeException('Cannot read SandIAM task path registry');
}

$managementPageContracts = [
    'SandIAMConnection' => ['connection', 'sand_iam:organization:index'],
    'SandIAMOrganization' => ['organization', 'sand_iam:organization:index'],
    'SandIAMApplication' => ['application', 'sand_iam:application:index'],
    'SandIAMEnvironment' => ['environment', 'sand_iam:environment:index'],
    'SandIAMWorkload' => ['workload-client', 'sand_iam:client:index'],
    'SandIAMGrant' => ['service-grant', 'sand_iam:grant:index'],
    'SandIAMCredential' => ['credential', 'sand_iam:credential:index'],
    'SandIAMPeopleAccess' => ['people-access', 'sand_iam:identity:index'],
    'SandIAMIdentityProvider' => ['identity-provider', 'sand_iam:identity_provider:index'],
    'SandIAMIdentity' => ['identity', 'sand_iam:identity:index'],
    'SandIAMRole' => ['role', 'sand_iam:role:index'],
    'SandIAMResource' => ['resource', 'sand_iam:resource:index'],
    'SandIAMPolicy' => ['policy', 'sand_iam:policy:index'],
    'SandIAMAuthSession' => ['auth-session', 'sand_iam:auth_policy:index'],
    'SandIAMApiGovernance' => ['api-governance', 'sand_iam:api_resource:index'],
    'SandIAMAdminScope' => ['admin-scope', 'sand_iam:admin_organization_grant:index', 'sand_iam:admin_application_grant:index'],
    'SandIAMAdminOrganizationGrant' => ['admin-organization-grant', 'sand_iam:admin_organization_grant:index'],
    'SandIAMEventNotification' => ['event-notification', 'sand_iam:webhook:index'],
    'SandIAMAuditTroubleshooting' => ['audit-troubleshooting', 'sand_iam:audit:index'],
    'SandIAMAudit' => ['audit', 'sand_iam:audit:index'],
    'SandIAMApplicationExperience' => ['application-experience', 'sand_iam:application_experience:index'],
    'SandIAMApplicationNetworkPolicy' => ['application-network-policy', 'sand_iam:application_network_policy:index'],
    'SandIAMAuthPolicy' => ['auth-policy', 'sand_iam:auth_policy:index'],
    'SandIAMIdentityGroup' => ['identity-group', 'sand_iam:identity_group:index'],
    'SandIAMIdentityInvitation' => ['identity-invitation', 'sand_iam:identity_invitation:index'],
    'SandIAMIdentityImport' => ['identity-import', 'sand_iam:identity_import:index'],
    'SandIAMSyncConnector' => ['sync-connector', 'sand_iam:sync_connector:index'],
    'SandIAMMessageProvider' => ['message-provider', 'sand_iam:message_provider:index'],
    'SandIAMOAuthClient' => ['oauth-client', 'sand_iam:oauth_client:index'],
    'SandIAMOAuthRegistrationToken' => ['oauth-registration-token', 'sand_iam:oauth_registration_token:index'],
    'SandIAMCasService' => ['cas-service', 'sand_iam:cas_service:index'],
    'SandIAMFederationConfig' => ['federation-config', 'sand_iam:federation:configure'],
    'SandIAMScimTokens' => ['scim-tokens', 'sand_iam:scim:token_index'],
    'SandIAMRadiusNas' => ['radius-nas', 'sand_iam:radius_nas:index'],
    'SandIAMSecurityAlert' => ['security-alert', 'sand_iam:security_alert:index'],
    'SandIAMAuditRetentionPolicy' => ['audit-retention-policy', 'sand_iam:audit_retention_policy:index'],
    'SandIAMInitialization' => ['initialization', 'sand_iam:initialization:index'],
    'SandIAMWebhook' => ['webhook', 'sand_iam:webhook:index'],
    'SandIAMWebhookDelivery' => ['webhook-delivery', 'sand_iam:webhook_delivery:index'],
    'SandIAMAdminApplicationGrant' => ['admin-application-grant', 'sand_iam:admin_application_grant:index'],
    'SandIAMApplicationBusinessAction' => ['application-business-action', 'sand_iam:api_resource:index'],
    'SandIAMApiResource' => ['api-resource', 'sand_iam:api_resource:index'],
    'SandIAMApiRouteBinding' => ['api-route-binding', 'sand_iam:api_route_binding:index'],
    'SandIAMRouteManifest' => ['route-manifest', 'sand_iam:onboarding:preview'],
    'SandIAMPolicySimulate' => ['policy-simulate', 'sand_iam:policy:index'],
    'SandIAMDeveloperDocs' => ['developer-docs', 'sand_iam:developer:openapi'],
];

foreach ($managementPageContracts as $menuCode => $contract) {
    $path = $contract[0];
    $permissions = array_slice($contract, 1);
    $component = '/plugin/sand-iam/' . $path . '/index';
    if (preg_match("/['\"]\\/sand-iam\\/" . preg_quote($path, '/') . "['\"]/", $taskPathSource) !== 1) {
        throw new RuntimeException('task path registry omits management route: ' . $path);
    }
    if (!is_file($sandIamRoot . '/sandadmin-artd/src/views/plugin/sand-iam/' . $path . '/index.vue')) {
        throw new RuntimeException('management route component is missing: ' . $path);
    }
    foreach ($contents as $file => $sql) {
        foreach (array_merge(["'{$menuCode}'", "'{$component}'"], array_map(
            static fn (string $permission): string => "'{$permission}'",
            $permissions
        )) as $required) {
            if (!str_contains($sql, $required)) {
                throw new RuntimeException($file . ' missing task-path lifecycle contract: ' . $required);
            }
        }
    }
}

if (preg_match('/entryPermissions\s*:\s*\[/', $taskPathSource) !== 1
    || preg_match("/['\"]sand_iam:admin_organization_grant:index['\"]/", $taskPathSource) !== 1
    || preg_match("/['\"]sand_iam:admin_application_grant:index['\"]/", $taskPathSource) !== 1) {
    throw new RuntimeException('admin-scope must support either delegation permission');
}

$gettingStartedComponent = '/plugin/sand-iam/getting-started/index';
if (!is_file($sandIamRoot . '/sandadmin-artd/src/views/plugin/sand-iam/getting-started/index.vue')) {
    throw new RuntimeException('getting-started route component is missing');
}
foreach ($contents as $file => $sql) {
    foreach ([
        "'SandIAMGettingStarted'",
        "'{$gettingStartedComponent}'",
        'overview_access_role("role_id") AS (',
        "menu.\"code\" = 'SandIAMGettingStarted'",
    ] as $required) {
        if (!str_contains($sql, $required)) {
            throw new RuntimeException($file . ' missing getting-started lifecycle contract: ' . $required);
        }
    }
}

foreach (['auth-sessions', 'mfa-factors', 'waiting-codex'] as $excludedPath) {
    foreach ($contents as $file => $sql) {
        if (str_contains($sql, '/plugin/sand-iam/' . $excludedPath . '/index')) {
            throw new RuntimeException($file . ' must not register application runtime route: ' . $excludedPath);
        }
        if ($excludedPath === 'waiting-codex' && str_contains($sql, $excludedPath)) {
            throw new RuntimeException($file . ' must not register an internal waiting route');
        }
    }
}

if (str_contains($taskPathSource, 'waiting-codex')) {
    throw new RuntimeException('task path registry must not expose an internal waiting route');
}

// These API-governance pages reuse existing permissions by design. Keep their
// lifecycle route registration in the same source-package contract.
foreach ([
    '/plugin/sand-iam/api-governance/index',
    '/plugin/sand-iam/application-business-action/index',
    '/plugin/sand-iam/api-resource/index',
    '/plugin/sand-iam/api-route-binding/index',
    '/plugin/sand-iam/route-manifest/index',
    '/plugin/sand-iam/policy-simulate/index',
] as $component) {
    foreach ($contents as $file => $sql) {
        if (!str_contains($sql, "'" . $component . "'")) {
            throw new RuntimeException($file . ' missing page component: ' . $component);
        }
    }
}

$base = file_get_contents($sandIamRoot . '/lifecycle/base.pgsql');
$catalog = file_get_contents($sandIamRoot . '/migrations/021_admin_permission_catalog.pgsql');
foreach (['sand_iam:api_resource:index', 'sand_iam:api_route_binding:index'] as $apiIndexPermission) {
    if ($catalog === false || !str_contains($catalog, "'{$apiIndexPermission}'")) {
        throw new RuntimeException('permission catalog must exclude API index leaf from broad parent inheritance: ' . $apiIndexPermission);
    }
}
foreach (['base OAuth role bindings' => $base, 'permission catalog role bindings' => $catalog] as $name => $sql) {
    if ($sql === false
        || str_contains($sql, 'ON CONFLICT DO NOTHING')
        || !str_contains($sql, 'WHERE existing.role_id = inherited.role_id')
        || !str_contains($sql, 'AND existing.menu_id = permission.id')) {
        throw new RuntimeException($name . ' must use an explicit pair guard');
    }
}

echo "SandIAM menu lifecycle contract checks passed\n";
