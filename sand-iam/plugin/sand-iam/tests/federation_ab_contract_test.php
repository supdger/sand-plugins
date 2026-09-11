<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/**
 * A/B regression floor. The lifecycle runner supplies the disposable PG
 * install/upgrade/uninstall proof; this contract prevents its core safety
 * primitives from being removed between ORM integration runs.
 */
$root = dirname(__DIR__, 3);
$checks = [
    $root . '/migrations/006_federation_integrity.pgsql' => [
        'config_version bigint NOT NULL DEFAULT 1',
        'provider_config_version bigint NOT NULL DEFAULT 1',
        'source_state varchar(16) NOT NULL DEFAULT',
        'missing_since timestamp NULL',
    ],
    $root . '/migrations/007_federation_handoff.pgsql' => [
        'handoff_return_uri varchar(2048)',
        'handoff_code_challenge varchar(64)',
        'link_identity_id bigint',
        'step_up_time timestamp(0)',
        'sand_iam_federation_handoff',
        'code_hash char(64) NOT NULL',
        'uk_sand_iam_federation_handoff_code_hash',
    ],
    dirname(__DIR__) . '/app/service/FederationService.php' => [
        "lock(true)->find()",
        'provider_config_version',
        'lockedProvider',
        'directoryObservedDropExceedsThreshold',
        "ldap-bytes:v1",
        "disable_grace_seconds",
        'SAND_IAM_DIRECTORY_DUPLICATE_SUBJECT',
        'revokeBindingSessions',
        'exchangeHandoff',
        'handoffStartContext',
        'createHandoff',
        'linkStartContext',
        'linkCallbackIdentity',
        'liveLinkSession',
        'access_expire_time',
        'auth_pepper_version',
        'validHandoffUri',
        'SAND_IAM_FEDERATION_STEP_UP_REQUIRED',
        'SAND_IAM_FEDERATION_LAST_LOGIN_METHOD',
        "SAND_IAM_FEDERATION_HANDOFF_INVALID",
    ],
    dirname(__DIR__) . '/app/api/controller/FederationController.php' => [
        'handoffRedirect',
        "response('', 303",
        "'Cache-Control' => 'no-store'",
        "http_build_query(['code' =>",
    ],
    dirname(__DIR__) . '/config/route.php' => [
        "'/api/sand-iam/v1/federation/handoff/exchange'",
        'FederationProtocolMiddleware::class',
        "'/step-up/password'",
        "'/step-up/mfa/start'",
        "'/federation/unlink'",
    ],
    dirname(__DIR__) . '/app/federation/NativeLdapDirectoryAdapter.php' => [
        'LDAP_OPT_X_TLS_DEMAND',
        'LDAP_OPT_NETWORK_TIMEOUT',
        'LDAP_CONTROL_PAGEDRESULTS',
        'seenCookies',
    ],
];
foreach ($checks as $file => $fragments) {
    $text = file_get_contents($file);
    if ($text === false) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); }
    foreach ($fragments as $fragment) if (!str_contains($text, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); }
}
foreach (['006_federation_directory_scim.pgsql', '006_federation_integrity.pgsql', '007_federation_handoff.pgsql'] as $migration) {
    $source = $root . '/migrations/' . $migration;
    $package = dirname(__DIR__) . '/migrations/' . $migration;
    if (!is_file($package) || hash_file('sha256', $source) !== hash_file('sha256', $package)) {
        fwrite(STDERR, "root/plugin migration differs: {$migration}\n");
        exit(1);
    }
}
echo "federation A/B contract checks passed\n";
