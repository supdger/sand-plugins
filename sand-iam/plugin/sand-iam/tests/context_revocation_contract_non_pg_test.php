<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$package = dirname(__DIR__);

function contextRevocationAssert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$provider = file_get_contents($package . '/app/runtime/IdentityContextProvider.php');
$runtime = file_get_contents($package . '/app/api/controller/RuntimeContextController.php');
$route = file_get_contents($package . '/config/route.php');
$authorization = file_get_contents($package . '/app/api/controller/AuthorizationController.php');
$credential = file_get_contents($package . '/app/admin/controller/CredentialController.php');
$guest = file_get_contents($package . '/app/api/controller/GuestIdentityController.php');
foreach ([$provider, $runtime, $route, $authorization, $credential, $guest] as $source) contextRevocationAssert(is_string($source), 'context security source is unreadable');

foreach ([
    "join('sand_iam_service service'",
    "where('service.status', 1)",
    "service_grant.quota_policy",
    "service_grant.data_class",
    "service.code AS service_code",
    "subject_scope_trust' => 'caller_asserted'",
    'sameGrantIds($payload[\'grant_ids\'] ?? null, $grants)',
    'ServiceGrantConstraintNormalizer',
    'verifyForService',
    'action_grants',
] as $fragment) contextRevocationAssert(str_contains($provider, $fragment), "context provider is missing {$fragment}");
contextRevocationAssert(!str_contains($route, '/app/sand-iam/runtime/environment/verify'), 'environment verification remains publicly routable');
contextRevocationAssert(!str_contains($runtime, 'function verifyEnvironment'), 'runtime controller still exposes environment verification');
contextRevocationAssert(str_contains($authorization, "withHeader('Cache-Control', 'no-store')"), 'authorization decision response is cacheable');
contextRevocationAssert(substr_count($credential, "withHeader('Cache-Control', 'no-store')") >= 2, 'credential plaintext responses are cacheable');
contextRevocationAssert(str_contains($guest, 'RequestId::fromRequest($request)') && str_contains($guest, "'identity.guest.upsert', \$requestId"), 'guest identity does not reuse one normalized request id');

echo 'context revocation non-PG contract checks passed' . PHP_EOL;
