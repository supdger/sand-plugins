<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** Portal P0 source-contract checks. This test intentionally does not connect to PostgreSQL. */
$package = dirname(__DIR__);
$checks = [
    $package . '/app/middleware/PortalSensitiveResponseMiddleware.php' => [
        'use Webman\Http\Response;',
        'catch (ApiException $exception)',
        "withHeader('Cache-Control', 'no-store')",
        "withHeader('Pragma', 'no-cache')",
        "withHeader('Referrer-Policy', 'no-referrer')",
        'RequestId::fromRequestCached($request)',
        "Log::error('SandIAM portal request failed'",
        'throw $exception;',
    ],
    $package . '/config/route.php' => [
        'PortalSensitiveResponseMiddleware::class',
        "Route::group('/api/sand-iam/v1/auth'",
        "'/api/sand-iam/v1/me/profile'",
        "'/api/sand-iam/v1/oauth/interaction'",
        "'/api/sand-iam/v1/federation/handoff/exchange'",
    ],
    $package . '/app/service/HumanAuthService.php' => [
        'Organization::where(\'id\', (int) $application->organization_id)->where(\'status\', 1)->find()',
        'assertPasskeyOptionsAllowed',
        "'passkey_options'",
        'assertPasskeyFinishAllowed',
        "'passkey_finish'",
        'consumePasskeyFinishRate',
        'lockPasskeyFinishBoundaryInTransaction',
        'issueSessionAfterMfaInTransaction',
        'livePasskeyApplication',
        'assertExperienceAllows($live, \'passkey\')',
        'assertRegistrationFields',
        "'SAND_IAM_AUTH_REGISTRATION_FIELD_REQUIRED'",
        "'reason' => 'identity_not_found'",
        "'reason' => 'destination_unavailable'",
        'recordVerificationDeliveryFailure',
    ],
    $package . '/app/service/MfaService.php' => [
        'passkeyAuthenticationOptions(array $payload, string $requestId, string $ip = \'\')',
        'assertPasskeyOptionsAllowed($application, $ip)',
        'consumePasskeyFinishRate($application, $ip)',
        'lockPasskeyFinishBoundaryInTransaction($application, $ip)',
        'issueSessionAfterMfaInTransaction($application, $identity, $ip',
        "WebauthnCredential::where('application_id',(int)\$app->id)->where('credential_id',\$id)->lock(true)->find()",
        'isLoginMethodEnabled($application, \'passkey\')',
    ],
    $package . '/app/api/controller/AuthController.php' => [
        'RequestId::fromRequestCached($request)',
        'SAND_IAM_AUTHENTICATION_FAILED',
        "strncasecmp(\$value, 'Bearer ', 7)",
        'passkeyAuthenticationOptions($request->post(), $this->requestId($request), $this->ip($request))',
        '如账号存在，验证码已发送',
    ],
    $package . '/app/api/controller/SelfServiceController.php' => ['RequestId::fromRequestCached($request)', "strncasecmp(\$authorization, 'Bearer ', 7)"],
    $package . '/app/api/controller/FederationController.php' => ['RequestId::fromRequestCached($request)', "strncasecmp(\$value, 'Bearer ', 7)"],
    $package . '/app/middleware/FederationProtocolMiddleware.php' => ['RequestId::fromRequestCached($request)'],
    $package . '/app/api/controller/OAuthOidcController.php' => ['RequestId::fromRequestCached($request)', 'SAND_IAM_AUTHENTICATION_FAILED'],
    $package . '/app/service/RequestId.php' => ['fromRequestCached', "'sand_iam.request_id'", 'setAttribute', 'WeakMap'],
    $package . '/app/admin/controller/ApplicationExperienceController.php' => ['开放注册必须启用密码登录', 'normalizePayload', "array_key_exists('registration_fields', \$payload)"],
    $package . '/app/admin/controller/OrganizationController.php' => ['OrganizationHumanSessionRevoker', "(int) \$model->status === 1", "(int) \$payload['status'] === 2"],
    $package . '/app/service/OrganizationHumanSessionRevoker.php' => ['lock(true)', "whereIn('application_id', \$applicationIds)", "whereIn('session_id', \$sessionIds)", "'organization.disable'"],
];

foreach ($checks as $file => $fragments) {
    $source = file_get_contents($file);
    if (!is_string($source)) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); }
    foreach ($fragments as $fragment) {
        if (!str_contains($source, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); }
    }
}

$portalMiddleware = file_get_contents($package . '/app/middleware/PortalSensitiveResponseMiddleware.php');
if (is_string($portalMiddleware) && str_contains($portalMiddleware, "return \$this->failure(\$request, 'SAND_IAM_PORTAL_OPERATION_FAILED'")) {
    fwrite(STDERR, "portal middleware must not replace unknown throwables with a synthetic response\n");
    exit(1);
}

echo "portal security non-PG contract checks passed\n";
