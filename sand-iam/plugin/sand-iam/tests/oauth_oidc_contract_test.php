<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
$files = [
    $root . '/migrations/005_oauth_oidc.pgsql' => ['sand_iam_oauth_client', 'uk_sand_iam_oauth_client_code', 'sand_iam_oauth_authorization_request', 'ck_sand_iam_oauth_authorization_request_binding', 'fk_sand_iam_oauth_token_grant_scope', 'encrypted_nonce'],
    $root . '/plugin/sand-iam/app/service/OAuthOidcService.php' => ['beginAuthorization', 'bindInteraction', 'approveAuthorization', "'S256'", "'at+jwt'", 'verifyAccessTokenForAudience', 'clientCredentialsAudience', 'offline_access', 'organization_code', 'application_code', 'SAND_IAM_OAUTH_REFRESH_REPLAY_DETECTED'],
    $root . '/plugin/sand-iam/app/api/controller/OAuthOidcController.php' => ['interactionSession', 'interactionConfirm', 'MULTIPLE_CLIENT_AUTH_METHODS', 'DUPLICATE_AUTHORIZE_PARAMETER', 'authorizationEndpointError', "'invalid_request'", "'Pragma' => 'no-cache'", 'unauthorized_client', 'WWW-Authenticate'],
    $root . '/plugin/sand-iam/config/route.php' => ['/oauth/interaction/session', '/oauth/interaction/confirm', "Route::post('/api/sand-iam/v1/oauth/authorize'", "Route::post('/api/sand-iam/v1/oauth/userinfo'", "Route::get('/api/sand-iam/v1/oauth/logout'", "Route::post('/api/sand-iam/v1/oauth/logout'", '/.well-known/openid-configuration'],
    $root . '/docs/development/sand-iam-oauth-oidc-api-v0.1.md' => ['PKCE', 'CSRF', 'offline_access', 'RFC 9700'],
];
foreach ($files as $file => $fragments) {
    $source = file_get_contents($file);
    if ($source === false) throw new RuntimeException('missing ' . $file);
    foreach ($fragments as $fragment) if (!str_contains($source, $fragment)) throw new RuntimeException($file . ' missing ' . $fragment);
}
foreach ([$root . '/install.sql', $root . '/update.sql', $root . '/plugin/sand-iam/update.sql'] as $file) {
    $source = file_get_contents($file);
    if ($source === false || !str_contains($source, '005_oauth_oidc.pgsql')) throw new RuntimeException('lifecycle mirror missing ' . $file);
}
echo "OAuth/OIDC contract test passed.\n";
