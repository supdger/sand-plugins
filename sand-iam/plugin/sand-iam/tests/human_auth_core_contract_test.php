<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/**
 * Static contract checks for IAM-T01. Execute with:
 * php plugin/sand-iam/tests/human_auth_core_contract_test.php
 */

$sandIamRoot = dirname(__DIR__, 3);
$required = [
    $sandIamRoot . '/migrations/003_human_auth_core.pgsql' => [
        'sand_iam_identity_auth', 'sand_iam_auth_session', 'sand_iam_auth_refresh_token',
        'sand_iam_auth_verification', 'sand_iam_auth_rate_limit', 'sand_iam_auth_policy',
        'FOREIGN KEY (identity_id, application_id)', 'token_hash char(64)', 'pepper_version varchar(32)',
    ],
    $sandIamRoot . '/install.sql' => ['sand_iam_auth_refresh_token', 'sand_iam_auth_policy', 'sand_iam_identity_auth'],
    dirname(__DIR__) . '/config/route.php' => [
        "Route::group('/api/sand-iam/v1/auth'", "Route::post('/register'", "Route::post('/refresh'", "Route::get('/sessions'",
    ],
    dirname(__DIR__) . '/app/service/HumanAuthService.php' => [
        'PASSWORD_ARGON2ID', 'SAND_IAM_AUTH_CONFIGURATION_UNAVAILABLE', 'SAND_IAM_AUTH_REFRESH_REPLAY_DETECTED',
        'AuthRefreshToken::where', 'IdentityAuth::where', 'consumeRateLimit', 'SAND_IAM_AUTH_VERIFICATION_CHANNEL_UNAVAILABLE',
        'verificationSatisfied', 'auth_pepper_version', 'REFRESH_RESPONSE_RETRY_TTL_SECONDS', 'encrypted_replay',
        'SessionTokenResponseReplayCipher', 'recoverSessionTokenResponse', 'recoverPasswordLoginResponse',
        'SAND_IAM_AUTH_LOGIN_RETRY_UNAVAILABLE', "'identity.login'", "'identity.login.rate_limit'",
        "'identity.login.captcha'", "'identity.login.failure'", 'consumeLoginRateOnce', 'verifyLoginCaptchaOnce',
        'failLoginOnce', 'revokeRefreshFamily',
    ],
    dirname(__DIR__) . '/app/service/SessionTokenResponseReplayCipher.php' => [
        'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt', 'session-response-retry-key:v1',
        'SAND_IAM_AUTH_SESSION_RETRY_UNAVAILABLE', 'SAND_IAM_AUTH_CONFIGURATION_UNAVAILABLE',
    ],
    dirname(__DIR__) . '/app/admin/controller/AuthPolicyController.php' => [
        'SAND_IAM_AUTH_POLICY_EXISTS', '该应用已有认证策略，请直接编辑',
    ],
];

foreach ($required as $file => $fragments) {
    $content = file_get_contents($file);
    if ($content === false) {
        fwrite(STDERR, "Cannot read {$file}\n");
        exit(1);
    }
    foreach ($fragments as $fragment) {
        if (!str_contains($content, $fragment)) {
            fwrite(STDERR, "Missing {$fragment} in {$file}\n");
            exit(1);
        }
    }
}

echo "human auth core contract checks passed\n";
