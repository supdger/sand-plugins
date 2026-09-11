<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$plugin = dirname(__DIR__);
if (!class_exists('plugin\\sandadmin\\exception\\ApiException')) {
    eval('namespace plugin\\sandadmin\\exception; class ApiException extends \\RuntimeException { public function __construct(string $message = "", int $code = 0) { parent::__construct($message, $code); } }');
}
require_once $plugin . '/app/radius/RadiusNetwork.php';
require_once $plugin . '/app/security/NetworkPolicy.php';

use plugin\SandIam\app\security\NetworkPolicy;

function t12Network(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$policy = NetworkPolicy::normalize(['allow_cidrs' => ['10.20.0.0/24', '2001:db8::/32'], 'deny_cidrs' => ['10.20.0.128/25']]);
t12Network(NetworkPolicy::allows($policy, '10.20.0.12'), 'allowed IPv4 address was denied');
t12Network(!NetworkPolicy::allows($policy, '10.20.0.200'), 'deny rule did not take precedence');
t12Network(NetworkPolicy::allows($policy, '2001:db8::9'), 'allowed IPv6 address was denied');
t12Network(!NetworkPolicy::allows($policy, '10.21.0.1'), 'address outside allowlist was accepted');
t12Network(!NetworkPolicy::allows($policy, 'not-an-ip'), 'invalid source address was accepted');
t12Network(NetworkPolicy::allows([], 'not-an-ip'), 'empty policy should not alter existing access');

foreach ([
    ['allow_cidrs' => ['10.20.0.1/24']],
    ['allow_cidrs' => ['10.20.0.0/24'], 'unknown' => []],
    ['allow_cidrs' => '10.20.0.0/24'],
] as $invalid) {
    try { NetworkPolicy::normalize($invalid); t12Network(false, 'invalid network policy was accepted'); } catch (Throwable) {}
}

$provider = file_get_contents($plugin . '/app/runtime/IdentityContextProvider.php');
$controller = file_get_contents($plugin . '/app/api/controller/RuntimeContextController.php');
$environmentVerifier = file_get_contents($plugin . '/app/runtime/EnvironmentReferenceVerifier.php');
$humanAuth = file_get_contents($plugin . '/app/service/HumanAuthService.php');
$resourceController = file_get_contents($plugin . '/app/admin/support/AdminResourceController.php');
$networkController = file_get_contents($plugin . '/app/admin/controller/ApplicationNetworkPolicyController.php');
$grantController = file_get_contents($plugin . '/app/admin/controller/ServiceGrantController.php');
t12Network(is_string($provider) && str_contains($provider, 'NetworkPolicy::allows') && str_contains($provider, 'service_grant.network_policy'), 'context issue does not enforce grant network policy');
t12Network(is_string($controller) && str_contains($controller, 'getRealIp(true)'), 'runtime controller does not use the transport peer address');
t12Network(is_string($controller) && str_contains($controller, 'bearerCredential') && !str_contains($controller, "post('credential'"), 'runtime context issue still accepts a body credential');
t12Network(is_string($controller) && substr_count($controller, "withHeader('Cache-Control', 'no-store')") >= 2, 'runtime context issue and verify responses are not explicitly no-store');
t12Network(is_string($provider) && str_contains($provider, "join('sand_iam_service service'") && str_contains($provider, "where('service.status', 1)"), 'context verification does not reject a disabled service');
t12Network(is_string($environmentVerifier) && str_contains($environmentVerifier, 'Organization::where') && str_contains($environmentVerifier, 'organization reference is unavailable'), 'environment verification does not reject a disabled organization');
t12Network(is_string($humanAuth) && substr_count($humanAuth, 'assertNetworkAllowed(') >= 9 && str_contains($humanAuth, '$this->assertNetworkAllowed($liveApplication, $ip);'), 'not every human/federated session path enforces application network policy');
t12Network(is_string($resourceController) && substr_count($resourceController, '$payload = $this->normalizePayload(') === 2, 'normalized management payload is not persisted on save and update');
t12Network(is_string($networkController) && str_contains($networkController, "\$payload['allow_cidrs'] = \$normalized['allow_cidrs']") && str_contains($networkController, 'return $payload;'), 'application network CIDRs are validated but not normalized before persistence');
t12Network(is_string($grantController) && str_contains($grantController, "\$payload['network_policy'] = NetworkPolicy::normalize"), 'service-grant network policy is validated but not normalized before persistence');

echo 'network policy non-PG checks passed' . PHP_EOL;
