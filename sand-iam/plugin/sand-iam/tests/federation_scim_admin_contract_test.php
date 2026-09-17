<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/**
 * T04 static floor.  The companion federation_ab_integration_test.php is the
 * real ThinkORM/PostgreSQL script and must be run only by the disposable-PG
 * lifecycle runner.  This test deliberately does not create a database.
 */
$package = dirname(__DIR__);
$checks = [
    $package . '/app/admin/controller/IdentityProviderController.php' => [
        'IdentityProviderApplication::create',
        "'organization_id' => (int) \$organization->id",
        "'provider_type' => 'local'",
        'public_code))',
        'secret_configured',
        'safeProvider',
        'Db::startTrans()',
    ],
    $package . '/app/admin/controller/IdentityBindingController.php' => [
        'mountedProvider',
        'IdentityProviderApplication::where',
        '兼容身份源不存在；请先在身份源管理中创建并挂载到当前接入应用',
    ],
    $package . '/app/service/ScimService.php' => [
        'listTokens',
        'identityCode',
        'cleanDisplayName',
        'assertUserNameAvailable',
        "lower(source_attributes->>'userName') = lower(?)",
        "private const SOURCE_EXTENSION = 'urn:sand:params:scim:schemas:extension:source:1.0'",
        'SAND_IAM_SCIM_IMMUTABLE_SOURCE_KEY',
        "\$attribute('externalId', 'string', false, false, 'readWrite', ['caseExact' => true])",
        "\$attribute('sourceKey', 'string', false, true, 'immutable', ['caseExact' => true])",
        "'schemaExtensions' => \$extension",
        'members\\\\[value eq',
        'source_state',
        "'scim.token_issue'",
    ],
    $package . '/app/api/controller/ScimController.php' => [
        'listResponse',
        "'meta']['location'",
        "withHeader('ETag'",
        "withHeader('Location'",
    ],
    $package . '/app/admin/controller/FederationAdminController.php' => [
        'listScimTokens',
        'issueScimToken',
        'revokeScimToken',
    ],
    $package . '/app/service/FederationService.php' => [
        'liveLinkSession',
        'access_expire_time',
        'hasUsablePasskey',
        'auditCallbackFailure',
        "in_array(strtolower(\$key), ['code', 'state'], true)",
        'SAND_IAM_FEDERATION_LAST_LOGIN_METHOD',
    ],
];
foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if ($content === false) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); }
    foreach ($fragments as $fragment) {
        if (!str_contains($content, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); }
    }
}

foreach (['006_federation_directory_scim.pgsql', '006_federation_integrity.pgsql', '007_federation_handoff.pgsql'] as $migration) {
    $source = dirname(__DIR__, 3) . '/migrations/' . $migration;
    $copy = $package . '/migrations/' . $migration;
    if (!is_file($copy) || hash_file('sha256', $source) !== hash_file('sha256', $copy)) {
        fwrite(STDERR, "root/plugin migration differs: {$migration}\n");
        exit(1);
    }
}
echo "federation SCIM/admin contract checks passed\n";
