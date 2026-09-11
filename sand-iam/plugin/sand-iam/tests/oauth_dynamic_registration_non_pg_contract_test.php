<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** IAM-T11 RFC 7591 source contract. No database is used. */
$package = dirname(__DIR__);
$root = dirname(__DIR__, 3);

$checks = [
    $package . '/app/service/OAuthDynamicRegistrationService.php' => [
        "'siam_dcr_'",
        "'dcr-token:' . \$token",
        "where('token_hash', \$this->tokenHash(\$initialAccessToken))",
        "->where('status', 1)->lock(true)",
        "'registration_source' => 'dynamic'",
        "'dynamic_registration_token_id' => (int) \$token->id",
        "'client_id_issued_at' => time()",
        "'client_secret_expires_at' => 0",
        "['none', 'client_secret_basic', 'client_secret_post']",
        "['authorization_code', 'refresh_token']",
        "in_array(\$host, ['127.0.0.1', '::1'], true)",
        'SAND_IAM_DCR_INVALID_REDIRECT_URI',
        'SAND_IAM_DCR_ACCESS_DENIED',
    ],
    $package . '/app/admin/controller/OAuthRegistrationTokenController.php' => [
        '动态注册令牌仅此一次展示',
        "'remaining_uses' => max(0, \$maxUses - \$usedCount)",
    ],
    $package . '/app/middleware/OAuthRegistrationSensitiveMiddleware.php' => [
        'one-time dynamic registration access tokens',
        "'Cache-Control', 'no-store'",
    ],
    $package . '/app/service/OAuthOidcService.php' => [
        "\$metadata['registration_endpoint'] = \$issuer . '/oauth/register'",
        "\$metadata['frontchannel_logout_supported'] = true",
        "'iss' => \$this->issuer()",
        "\$parameters['sid'] = \$sessionId",
        "OAuthGrant::where('application_id'",
        "\$metadata['backchannel_logout_supported'] = true",
        "\$this->jwt(\$claims, 'logout+jwt')",
        "'encrypted_logout_token' => (new OidcLogoutTokenCipher())->encrypt(json_encode([",
        "'target_uri' => \$uri",
        "'logout_token' => \$logoutToken",
    ],
    $package . '/app/api/controller/OAuthOidcController.php' => [
        'function register(Request $request)',
        'registrationBearer',
        "preg_match('/^siam_dcr_[A-Za-z0-9_-]{48}$/', \$token)",
        'dynamicRegistrationError',
        "'invalid_token'",
        "'invalid_redirect_uri'",
        "'invalid_client_metadata'",
        "'access_denied'",
        'withStatus(201)',
        'frontchannelLogoutPage',
        "frame-src https:",
    ],
    $package . '/config/route.php' => [
        '/oauth-registration-token/issue',
        '/oauth-registration-token/revoke',
        '/oauth/register',
        'OAuthRegistrationSensitiveMiddleware::class',
    ],
    $package . '/config/app.php' => [
        "SAND_IAM_OAUTH_DYNAMIC_REGISTRATION_ENABLED', 0",
        "SAND_IAM_OIDC_FRONTCHANNEL_LOGOUT_ENABLED', 0",
        "SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_ENABLED', 0",
        'SAND_IAM_OIDC_LOGOUT_ENCRYPTION_KEY',
    ],
    $package . '/app/service/OidcBackchannelLogoutService.php' => [
        "lock('FOR UPDATE SKIP LOCKED')",
        "http_build_query(['logout_token' => \$token]",
        "\$payload['target_uri']",
        "state' => 'delivered'",
        "state' => \$dead ? 'dead' : 'pending'",
    ],
    $package . '/app/oidc/NativeOidcBackchannelHttpAdapter.php' => [
        'application/x-www-form-urlencoded',
        'CURLOPT_SSL_VERIFYPEER => true',
        "CURLOPT_FOLLOWLOCATION => false",
        'PublicDnsResolver::curlResolveEntry',
    ],
    $package . '/app/security/PublicDnsResolver.php' => [
        'DNS_A',
        'DNS_AAAA',
        'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE',
    ],
    $package . '/config/process.php' => ['SAND_IAM_OIDC_LOGOUT_WORKER_ENABLED', 'OidcLogoutWorker::class'],
];

foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if (!is_string($content)) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); }
    foreach ($fragments as $fragment) {
        if (!str_contains($content, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); }
    }
}

$controller = (string) file_get_contents($package . '/app/admin/controller/OAuthRegistrationTokenController.php');
if (str_contains($controller, "'token_hash' =>") || str_contains($controller, "'last_used_ip_hash' =>")) {
    fwrite(STDERR, "DCR token DTO exposes stored secret material\n");
    exit(1);
}

$name = '016_oauth_dynamic_registration_logout.pgsql';
$source = $root . '/migrations/' . $name;
$copy = $package . '/migrations/' . $name;
if (!is_file($source) || !is_file($copy) || hash_file('sha256', $source) !== hash_file('sha256', $copy)) {
    fwrite(STDERR, "016 root/plugin copies differ\n");
    exit(1);
}
$sql = (string) file_get_contents($source);
foreach (['sand_iam_oauth_registration_token', 'registration_source', 'dynamic_registration_token_id', 'frontchannel_logout_uri', 'backchannel_logout_uri', 'sand_iam_oidc_logout_delivery', 'encrypted_logout_token'] as $fragment) {
    if (!str_contains($sql, $fragment)) { fwrite(STDERR, "016 missing {$fragment}\n"); exit(1); }
}
foreach (['ENGINE=', 'AUTO_INCREMENT', '`'] as $mysqlFragment) {
    if (str_contains($sql, $mysqlFragment)) { fwrite(STDERR, "MySQL syntax {$mysqlFragment} found in 016\n"); exit(1); }
}

echo "OAuth dynamic registration non-PG contract checks passed\n";
