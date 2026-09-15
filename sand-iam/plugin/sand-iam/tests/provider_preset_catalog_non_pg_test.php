<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$plugin = dirname(__DIR__);
if (!class_exists('plugin\\sandadmin\\exception\\ApiException')) {
    eval('namespace plugin\\sandadmin\\exception; class ApiException extends \\RuntimeException { public function __construct(string $message = "", int $code = 0) { parent::__construct($message, $code); } }');
}
require_once $plugin . '/app/developer/ProviderPresetCatalog.php';

use plugin\SandIam\app\developer\ProviderPresetCatalog;

function presetAssert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$presets = ProviderPresetCatalog::all();
presetAssert(count($presets) === 7, 'common external IdP presets are incomplete');
$codes = [];
foreach ($presets as $preset) {
    $code = $preset['code'] ?? null;
    presetAssert(is_string($code) && preg_match('/^[a-z][a-z0-9_]{2,63}$/', $code) === 1 && !isset($codes[$code]), 'preset code is not stable and unique');
    $codes[$code] = true;
    presetAssert(is_string($preset['name'] ?? null) && preg_match('/\\p{Han}/u', $preset['name']) === 1, "{$code} lacks Chinese name");
    presetAssert(in_array($preset['protocol'] ?? null, ['oidc', 'oauth2'], true), "{$code} has unsupported protocol");
    presetAssert(in_array($preset['compatibility'] ?? null, ['compatible', 'manual_required', 'unsupported'], true), "{$code} lacks compatibility state");
    presetAssert(($preset['last_verified_at'] ?? null) === '2026-08-22', "{$code} has no verification date");
    foreach ($preset['official_source_urls'] ?? [] as $url) presetAssert(is_string($url) && str_starts_with($url, 'https://'), "{$code} has a non-HTTPS official source URL");
    foreach ($preset['endpoints'] ?? [] as $endpoint) if ($endpoint !== null) presetAssert(is_string($endpoint) && str_starts_with($endpoint, 'https://'), "{$code} has a non-HTTPS endpoint");
    foreach ($preset['required_config'] ?? [] as $field) presetAssert(is_array($field) && is_string($field['key'] ?? null) && is_bool($field['secret'] ?? null), "{$code} required config is ambiguous");
    if (($preset['compatibility'] ?? null) === 'manual_required') {
        presetAssert(($preset['endpoints'] ?? []) === ['authorization_endpoint' => null, 'token_endpoint' => null, 'userinfo_endpoint' => null, 'jwks_uri' => null, 'discovery' => null], "{$code} must not guess a manual-required endpoint");
        presetAssert(($preset['claim_mapping'] ?? []) === [] && ($preset['required_config'] ?? []) === [], "{$code} must not guess a manual-required contract");
    }
}
$compatibleCodes = array_values(array_map(static fn (array $preset): string => $preset['code'], array_filter($presets, static fn (array $preset): bool => $preset['compatibility'] === 'compatible')));
sort($compatibleCodes, SORT_STRING);
presetAssert($compatibleCodes === ['github_oauth2', 'google_oidc', 'microsoft_entra_oidc'], 'only verified GitHub, Google and Entra presets may be applied');

$github = ProviderPresetCatalog::draft('github_oauth2', [
    'client_id' => 'github-client-id', 'redirect_uri' => 'https://iam.example.test/federation/callback', 'handoff_return_uris' => ['https://app.example.test/login/complete'],
]);
presetAssert(($github['draft_only'] ?? false) === true && ($github['save_performed'] ?? true) === false, 'preset draft performed a save');
presetAssert(($github['config']['authorization_endpoint'] ?? null) === 'https://github.com/login/oauth/authorize', 'GitHub OAuth endpoint drifted');
presetAssert(!array_key_exists('client_secret', $github['config'] ?? []) && ($github['required_secret_fields'] ?? []) === ['client_secret'], 'preset draft leaked or omitted its required secret contract');

$entra = ProviderPresetCatalog::draft('microsoft_entra_oidc', [
    'tenant_id' => 'contoso.onmicrosoft.com', 'client_id' => 'entra-client-id', 'redirect_uri' => 'https://iam.example.test/federation/callback', 'handoff_return_uris' => ['https://app.example.test/login/complete'],
]);
presetAssert(($entra['config']['discovery_url'] ?? null) === 'https://login.microsoftonline.com/contoso.onmicrosoft.com/v2.0/.well-known/openid-configuration', 'Entra tenant discovery draft is invalid');

// Exercise the real, side-effect-free configuration validators: a generated
// draft must fit the same contract as the configuration editor's save payload.
require_once $plugin . '/app/service/FederationService.php';
$serviceClass = new ReflectionClass(\plugin\SandIam\app\service\FederationService::class);
$service = $serviceClass->newInstanceWithoutConstructor();
foreach ($compatibleCodes as $code) {
    $draft = ProviderPresetCatalog::draft($code, [
        'tenant_id' => 'contoso.onmicrosoft.com',
        'client_id' => 'example-client',
        'redirect_uri' => 'https://iam.example.test/federation/callback',
        'handoff_return_uris' => ['https://app.example.test/login/complete'],
    ]);
    $serviceClass->getMethod('validateConfig')->invoke($service, $draft['provider_type'], $draft['config']);
    $serviceClass->getMethod('validateMapping')->invoke($service, $draft['attribute_mapping']);
    presetAssert(!array_key_exists('client_secret', $draft['config']), "{$code} generated a secret");
}

foreach ([['dingtalk_login', 'SAND_IAM_IDP_PRESET_MANUAL_REQUIRED'], ['feishu_user_authorization', 'SAND_IAM_IDP_PRESET_MANUAL_REQUIRED']] as [$code, $expected]) {
    try {
        ProviderPresetCatalog::draft($code, []);
        presetAssert(false, "{$code} unexpectedly produced a generic draft");
    } catch (Throwable $exception) {
        presetAssert(str_contains($exception->getMessage(), $expected), "{$code} did not fail with a stable compatibility code");
    }
}
try {
    ProviderPresetCatalog::draft('google_oidc', ['client_id' => 'id', 'redirect_uri' => 'https://iam.example.test/callback', 'handoff_return_uris' => ['https://app.example.test/complete'], 'client_secret' => 'must-not-accept']);
    presetAssert(false, 'preset draft accepted a secret');
} catch (Throwable $exception) {
    presetAssert(str_contains($exception->getMessage(), 'SAND_IAM_IDP_PRESET_SECRET_NOT_ALLOWED'), 'secret rejection is not stable');
}
try {
    ProviderPresetCatalog::draft('google_oidc', ['client_id' => 'id', 'redirect_uri' => 'https://iam.example.test/callback', 'handoff_return_uris' => ['https://app.example.test/complete'], 'config' => ['client_secret' => 'must-not-accept']]);
    presetAssert(false, 'preset draft accepted a nested secret');
} catch (Throwable $exception) {
    presetAssert(str_contains($exception->getMessage(), 'SAND_IAM_IDP_PRESET_SECRET_NOT_ALLOWED'), 'nested secret rejection is not stable');
}

$controller = (string) file_get_contents($plugin . '/app/admin/controller/IdentityProviderPresetController.php');
$routes = (string) file_get_contents($plugin . '/config/route.php');
$management = (string) file_get_contents($plugin . '/app/developer/ManagementApiCatalog.php');
foreach ([$controller, $routes, $management] as $source) presetAssert($source !== '', 'preset management source is unreadable');
foreach (["'sand_iam:identity_provider:read'", 'ProviderPresetCatalog::draft', 'Cache-Control'] as $needle) presetAssert(str_contains($controller, $needle), "preset controller lacks {$needle}");
foreach (['/identity-provider-preset/index', '/identity-provider-preset/read', '/identity-provider-preset/draft'] as $needle) {
    presetAssert(str_contains($routes, $needle) && str_contains($management, $needle), "preset route/catalog lacks {$needle}");
}
presetAssert(str_contains($routes, 'IdentityProviderPresetController::class'), 'preset controller is not route-bound and default-route-guarded');

echo "IdP preset catalog non-PG checks passed\n";
