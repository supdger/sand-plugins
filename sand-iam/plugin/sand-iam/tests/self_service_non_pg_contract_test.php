<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$package = dirname(__DIR__);
$checks = [
    $package . '/app/service/HumanAuthService.php' => [
        'public function changePassword(',
        'SAND_IAM_AUTH_CURRENT_PASSWORD_INVALID',
        'SAND_IAM_AUTH_PASSWORD_REUSE_FORBIDDEN',
        "'password_change'",
        "AuthSession::whereIn('id', \$sessionIds)->update",
        "AuthRefreshToken::whereIn('session_id', \$sessionIds)",
    ],
    $package . '/app/service/SelfServiceService.php' => [
        'public function profile(',
        'public function updateProfile(',
        'public function connections(',
        'public function securityOverview(',
        'IdentityProviderApplication::where',
        "where('refresh_expire_time', '>'",
        'count($this->connections($accessToken))',
        'subjectHint',
        "'identity.profile_update'",
    ],
    $package . '/app/api/controller/SelfServiceController.php' => [
        'public function profile(',
        'public function updateProfile(',
        'public function connections(',
        'public function security(',
        "header('Authorization'",
    ],
    $package . '/config/route.php' => [
        "'/password/change'",
        "'/api/sand-iam/v1/me/profile'",
        "'/api/sand-iam/v1/me/connections'",
        "'/api/sand-iam/v1/me/security'",
        'disableDefaultRoute(SelfServiceController::class)',
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

$service = file_get_contents($package . '/app/service/SelfServiceService.php');
if (!is_string($service) || str_contains($service, "'subject' =>") || str_contains($service, "'password_hash' =>")) {
    fwrite(STDERR, "self-service response exposes a raw subject or password hash\n");
    exit(1);
}

echo "self-service non-PG contract checks passed\n";
