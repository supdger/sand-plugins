<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** Portal delivery checks are intentionally static: no database, host, or browser is required. */
$root = dirname(__DIR__, 3);
$package = $root . '/plugin/sand-iam';
$portal = $root . '/portal';

$checks = [
    $portal . '/package.json' => ['"typecheck"', '"build"', '"test:contract"', 'esbuild', 'typescript'],
    $portal . '/scripts/build.mjs' => ['public/account', 'account.js', 'index.html', 'rm(outputDirectory'],
    $portal . '/src/app.ts' => ['submitOAuthDecision', 'submitCasReject', 'startTotp', 'registerPasskey', 'submitPasskeyLogin', 'startExternalLogin', 'completeFederationCallback', 'defaultPasswordExperience', 'isInteractionExperienceUnavailable', 'http === 404', 'http === 503', 'usingDefaultExperience', 'brand-logo', '服务协议', '隐私政策', 'void loadOAuth();', '登录凭据只留在内存'],
    $portal . '/src/runtime.ts' => ['SAND_IAM_PORTAL_OAUTH_BIND', 'SAND_IAM_PORTAL_OAUTH_CONFIRM', 'SAND_IAM_PORTAL_CAS_REJECT', 'startPortalPasskeyLogin', 'startPortalFederationLogin', 'exchangePortalFederationHandoff', 'credentials: "omit"'],
    $package . '/app/api/controller/AccountPortalController.php' => ["'index.html'", "'account.js'", "img-src 'self' data: https:", "Content-Security-Policy", "X-Content-Type-Options"],
    $package . '/app/api/controller/OAuthOidcController.php' => ['accountInteractionLocation', "'oauth_request'", '/app/sand-iam/account/'],
    $package . '/app/api/controller/CasController.php' => ['function reject(Request $request)', 'cas_request=', 'accountInteractionLocation'],
    $package . '/app/service/CasProtocolService.php' => ['public function reject(', "'cas.login.reject'", "'reason' => 'user_denied'", "'consumed_time' => date('Y-m-d H:i:s')"],
    $package . '/app/service/OAuthOidcService.php' => ["'organization_code'", "'application_code'", "'oauth.interaction_bind', 'succeeded'", "'oauth.authorize_consent', 'denied'", "'oauth.authorize_consent', 'succeeded'", 'Db::commit();'],
    $package . '/config/route.php' => ["'/app/sand-iam/account/'", "'/app/sand-iam/account/account.js'", '/cas/interaction/reject'],
    $package . '/public/account/index.html' => ['src="./account.js"'],
    $package . '/public/account/account.js' => ['submitOAuthDecision', 'registerPasskey', 'submitPasskeyLogin', 'startExternalLogin', 'completeFederationCallback', 'defaultPasswordExperience', 'brand-logo'],
];

foreach ($checks as $file => $needles) {
    $source = file_get_contents($file);
    if (!is_string($source)) {
        fwrite(STDERR, "unreadable {$file}\n");
        exit(1);
    }
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            fwrite(STDERR, "missing {$needle} in {$file}\n");
            exit(1);
        }
    }
}

if (str_contains((string) file_get_contents($portal . '/src/app.ts'), 'id="access-token"')) {
    fwrite(STDERR, "portal must not request a pasted access token\n");
    exit(1);
}

echo "portal delivery non-PG contract checks passed\n";
