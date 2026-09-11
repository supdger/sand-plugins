<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** Passkey finish and neutral verification delivery contracts without PostgreSQL. */
$package = dirname(__DIR__);
$mfa = file_get_contents($package . '/app/service/MfaService.php');
$human = file_get_contents($package . '/app/service/HumanAuthService.php');
$document = file_get_contents(dirname($package, 2) . '/docs/development/sand-iam-human-auth-api-v0.1.md');
$pg = file_get_contents($package . '/tests/mfa_passkey_boundary_pg_integration_test.php');
if (!is_string($mfa) || !is_string($human) || !is_string($document) || !is_string($pg)) throw new RuntimeException('passkey finish fixture source is unreadable');

foreach ([
    'lockPasskeyFinishBoundaryInTransaction', 'consumePasskeyFinishRate', 'issueSessionAfterMfaInTransaction',
    "WebauthnCredential::where('application_id',(int)\$app->id)->where('credential_id',\$id)->lock(true)->find()",
] as $fragment) if (!str_contains($mfa, $fragment)) throw new RuntimeException("passkey finish is missing {$fragment}");

$boundaryStart = strpos($human, 'public function lockPasskeyFinishBoundaryInTransaction');
$boundaryEnd = strpos($human, 'public function isLoginMethodEnabled', $boundaryStart ?: 0);
$boundary = $boundaryStart === false || $boundaryEnd === false ? '' : substr($human, $boundaryStart, $boundaryEnd - $boundaryStart);
$activeStart = strpos($human, 'private function lockActiveApplicationInTransaction');
$activeEnd = strpos($human, 'private function assertPasskeyExperienceAllows', $activeStart ?: 0);
$active = $activeStart === false || $activeEnd === false ? '' : substr($human, $activeStart, $activeEnd - $activeStart);
foreach ([
    "Organization::where('id', (int) \$application->organization_id)->where('status', 1)->lock(true)->find()",
    "Application::where('id', (int) \$application->id)->where('organization_id', (int) \$organization->id)->where('status', 1)->lock(true)->find()",
] as $fragment) if (!str_contains($active, $fragment)) throw new RuntimeException('passkey lock helper does not lock organization before application');
$lockOrder = [
    '$this->lockActiveApplicationInTransaction($application)',
    "ApplicationExperience::where('application_id', (int) \$live->id)->lock(true)->find()",
    "ApplicationNetworkPolicy::where('application_id', (int) \$live->id)->lock(true)->find()",
];
$position = -1;
foreach ($lockOrder as $fragment) { $next = strpos($boundary, $fragment); if ($next === false || $next <= $position) throw new RuntimeException('passkey mutable-state lock order is not organization, application, experience, network'); $position = $next; }
foreach (["'identity_not_found'", "'destination_unavailable'", "'channel_unavailable'", "'delivery_failed'", 'recordVerificationDeliveryFailure'] as $fragment) {
    if (!str_contains($human, $fragment)) throw new RuntimeException("verification delivery audit is missing {$fragment}");
}
if (str_contains($document, '以 `503 SAND_IAM_AUTH_VERIFICATION_CHANNEL_UNAVAILABLE` 明确失败') || !str_contains($document, '均返回同一中性成功响应')) throw new RuntimeException('human-auth API document contradicts neutral verification delivery');
foreach (['Db::connect(null, true)', 'proc_open', 'lock(true)', "'login_methods' => ['password']", "'deny_cidrs' => ['127.0.0.1/32']", 'passkeyAuthenticationFinish'] as $fragment) {
    if (!str_contains($pg, $fragment)) throw new RuntimeException("passkey PostgreSQL concurrency fixture is missing {$fragment}");
}
echo "passkey finish and verification non-PG contract checks passed\n";
