<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/**
 * Non-PG T04 regression floor. It checks protocol and management contracts
 * without creating, dropping or mutating any database.
 */
$package = dirname(__DIR__);
$checks = [
    $package . '/app/admin/controller/IdentityProviderController.php' => [
        'IdentityProviderApplication::create',
        "'provider_type' => 'local'",
        'safeProvider',
        'secret_configured',
        'Db::startTrans()',
    ],
    $package . '/app/admin/controller/IdentityBindingController.php' => [
        'mountedProvider',
        'IdentityProviderApplication::where',
        '兼容身份源不存在；请先在身份源管理中创建并挂载到当前接入应用',
        "strlen(\$subject) > 191",
        "preg_match('/^\\s|\\s$/u', \$subject)",
        'revokeBindingSessions',
        'AuthRefreshToken::whereIn',
        'lockedMountedProvider',
        'provider → application → organization → mount',
    ],
    $package . '/app/admin/controller/FederationAdminController.php' => [
        'issueScimToken',
        'listScimTokens',
        'revokeScimToken',
        "post('expire_time')",
        "'scim.token_issue'",
        "'scim.token_revoke'",
        'IdempotencyService::fingerprint($payload)',
        'RequestId::fromRequestCached($request)',
    ],
    $package . '/app/service/ScimService.php' => [
        'listTokens',
        'tokenExpireTime',
        'bool $manageTransaction = true',
        'if ($manageTransaction) Db::startTrans()',
        'SAND_IAM_SCIM_TOKEN_EXPIRE_INVALID',
        'time() + 90 * 86400',
        'identityCode',
        'cleanDisplayName',
        'SAND_IAM_SCIM_IMMUTABLE_SOURCE_KEY',
        'SAND_IAM_SCIM_SOURCE_KEY_INVALID',
        'SOURCE_EXTENSION',
        "'externalId', 'mutability' => 'readWrite'",
        'members\\\\[value eq',
        "\$path === '' && is_array(\$value)",
        'array_is_list',
        "is_bool(\$resource['active'])",
        "'paths' => array_values(array_unique(\$paths))",
        "'application_id' => \$applicationId",
        'lockedProvider',
        "'mutability' => 'immutable'",
        "ScimGroupMember::withTrashed()->where('group_id'",
        "'application_id' => \$applicationId, 'identity_id' => \$identityId",
        "'status' => 2",
        "'delete_time' => null",
        "\$record->expire_time === null",
    ],
    $package . '/app/api/controller/ScimController.php' => [
        'listResponse',
        'users(Request $request, string $provider)',
        'user(Request $request, string $provider, string $id)',
        'groups(Request $request, string $provider)',
        'group(Request $request,string $provider,string $id)',
        'private function guard(callable $callback): Response',
        "catch (ApiException \$exception)",
        "'WWW-Authenticate', 'Bearer'",
        "withHeader('ETag'",
        "withHeader('Location'",
        "'meta']['location'",
        'RequestId::fromRequestCached($request)',
    ],
    $package . '/app/middleware/ScimProtocolMiddleware.php' => [
        "'application/scim+json'",
        "withHeader('Cache-Control', 'no-store')",
        "'Pragma', 'no-cache'",
        "'WWW-Authenticate','Bearer'",
        "[400,401,404,409,412,428]",
    ],
    $package . '/config/route.php' => [
        "'/scim/token/issue'",
        "'/scim/token/index'",
        "'/app/sand-iam/admin/scim/token/revoke'",
        'ScimProtocolMiddleware::class',
    ],
];
foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if ($content === false) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); }
    foreach ($fragments as $fragment) {
        if (!str_contains($content, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); }
    }
}

$dto = file_get_contents($package . '/app/admin/controller/IdentityProviderController.php');
if ($dto === false || str_contains($dto, "'encrypted_config' =>")) {
    fwrite(STDERR, "provider management DTO may expose encrypted_config\n");
    exit(1);
}

$scimController = file_get_contents($package . '/app/api/controller/ScimController.php');
if ($scimController === false || str_contains($scimController, "->param('provider'")) {
    fwrite(STDERR, "SCIM controller must receive Webman route parameters through action arguments\n");
    exit(1);
}

$integration = file_get_contents($package . '/tests/federation_ab_integration_test.php');
foreach ([
    't04-scim-nochange',
    't04-scim-pathless-single',
    't04-scim-pathless-array',
    't04-scim-add',
    't04-scim-remove',
    't04-scim-restore',
    '$sourceExtension',
    '->delete_time',
    't04-scim-cross-app',
    't04-handoff-provider-drift',
    't04-handoff-mount-drift',
    't04-link-expired-callback',
    't04-link-revoked-callback',
    't04-link-conflict-callback',
    'FederationController::class',
] as $fragment) {
    if ($integration === false || !str_contains($integration, $fragment)) { fwrite(STDERR, "missing integration skeleton {$fragment}\n"); exit(1); }
}

echo "SCIM/admin non-PG contract checks passed\n";
