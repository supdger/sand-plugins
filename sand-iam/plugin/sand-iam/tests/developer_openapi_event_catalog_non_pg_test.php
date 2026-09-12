<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$plugin = dirname(__DIR__);
require_once $plugin . '/app/developer/ManagementApiCatalog.php';
require_once $plugin . '/app/webhook/EventCatalog.php';

use plugin\SandIam\app\developer\ManagementApiCatalog;
use plugin\SandIam\app\webhook\EventCatalog;

function t12DeveloperFail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$catalogRoutes = [];
$operationIds = [];
foreach (ManagementApiCatalog::routes() as $route) {
    $key = $route['method'] . ' ' . $route['path'];
    if (isset($catalogRoutes[$key])) t12DeveloperFail("duplicate management route {$key}");
    if (isset($operationIds[$route['operation_id']])) t12DeveloperFail("duplicate operationId {$route['operation_id']}");
    if (!preg_match('/^sand_iam:[a-z0-9_]+:[a-z0-9_]+$/', $route['permission'])) t12DeveloperFail("invalid permission for {$key}");
    $catalogRoutes[$key] = true;
    $operationIds[$route['operation_id']] = true;
}

$routeSource = file_get_contents($plugin . '/config/route.php');
if (!is_string($routeSource)) t12DeveloperFail('cannot read route source');
$routedControllers = [];
if (preg_match_all("/(?:=>|\[)\s*([A-Za-z0-9_]+Controller)::class(?:\s*,|\s*\])?/", $routeSource, $matches)) {
    foreach ($matches[1] as $controller) $routedControllers[$controller] = true;
}
$disableMarker = strpos($routeSource, '// The package identifier contains a hyphen');
$disableEnd = strpos($routeSource, '] as $explicitController)', $disableMarker ?: 0);
if ($disableMarker === false || $disableEnd === false) t12DeveloperFail('explicit-controller default-route guard not found');
$disableBlock = substr($routeSource, $disableMarker, $disableEnd - $disableMarker);
$disabledControllers = [];
if (preg_match_all('/([A-Za-z0-9_]+Controller)::class/', $disableBlock, $matches)) {
    foreach ($matches[1] as $controller) $disabledControllers[$controller] = true;
}
$unguardedControllers = array_keys(array_diff_key($routedControllers, $disabledControllers));
sort($unguardedControllers);
if ($unguardedControllers !== []) t12DeveloperFail('controllers exposed through Webman default routes: ' . implode(', ', $unguardedControllers));
$groupStart = strpos($routeSource, "Route::group('/app/sand-iam/admin'");
$groupEnd = strpos($routeSource, '})->middleware([CheckLogin::class, CheckAuth::class, SystemLog::class]);', $groupStart ?: 0);
if ($groupStart === false || $groupEnd === false) t12DeveloperFail('management route group not found');
$group = substr($routeSource, $groupStart, $groupEnd - $groupStart);

$actual = [];
$crudControllers = [];
if (preg_match_all("/'([^']+)'\s*=>\s*([A-Za-z0-9_]+Controller)::class/", $group, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) $crudControllers[$match[1]] = $match[2];
}
foreach (array_keys((new ReflectionClass(ManagementApiCatalog::class))->getConstant('CRUD')) as $segment) {
    $controller = $crudControllers[$segment] ?? null;
    if (!is_string($controller)) t12DeveloperFail("management CRUD controller missing for {$segment}");
    foreach ([['GET', 'index'], ['GET', 'read'], ['POST', 'save'], ['POST', 'update'], ['POST', 'disable']] as [$method, $action]) {
        $actual["{$method} /{$segment}/{$action}"] = [$controller, $action];
    }
}
if (preg_match_all("/Route::(get|post)\('([^']+)',\s*\[([A-Za-z0-9_]+Controller)::class,\s*'([^']+)'\]\)/", $group, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        if ($match[2] === '/') continue;
        $actual[strtoupper($match[1]) . ' ' . $match[2]] = [$match[3], $match[4]];
    }
}
if (preg_match_all("/Route::(get|post)\('\/app\/sand-iam\/admin([^']+)',\s*\[([A-Za-z0-9_]+Controller)::class,\s*'([^']+)'\]\)/", $routeSource, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) $actual[strtoupper($match[1]) . ' ' . $match[2]] = [$match[3], $match[4]];
}
ksort($actual);
ksort($catalogRoutes);
if (array_keys($actual) !== array_keys($catalogRoutes)) {
    t12DeveloperFail('management OpenAPI route drift: missing=' . json_encode(array_keys(array_diff_key($actual, $catalogRoutes))) . ' extra=' . json_encode(array_keys(array_diff_key($catalogRoutes, $actual))));
}

$controllerPermissions = [];
foreach ($actual as [$controller, $controllerMethod]) {
    if (isset($controllerPermissions[$controller])) continue;
    $controllerSource = file_get_contents($plugin . '/app/admin/controller/' . $controller . '.php');
    if (!is_string($controllerSource)) t12DeveloperFail("cannot read management controller {$controller}");
    if (preg_match_all("/#\[Permission\([^,]+,\s*'([^']+)'\)\]\s*public function\s+([A-Za-z0-9_]+)/s", $controllerSource, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) $controllerPermissions[$controller][$match[2]] = $match[1];
    }
}
$permissionDrift = [];
foreach (ManagementApiCatalog::routes() as $route) {
    $key = $route['method'] . ' ' . $route['path'];
    [$controller, $controllerMethod] = $actual[$key];
    $expectedPermission = $controllerPermissions[$controller][$controllerMethod] ?? null;
    if (!is_string($expectedPermission)) t12DeveloperFail("Permission attribute missing for {$controller}::{$controllerMethod}");
    if ($route['permission'] !== $expectedPermission) {
        $permissionDrift[$key] = ['catalog' => $route['permission'], 'controller' => $expectedPermission];
    }
}
if ($permissionDrift !== []) t12DeveloperFail('management OpenAPI permission drift: ' . json_encode($permissionDrift, JSON_UNESCAPED_SLASHES));

$openApi = ManagementApiCatalog::openApi();
if (($openApi['openapi'] ?? null) !== '3.1.0') t12DeveloperFail('OpenAPI version is not 3.1.0');
if (count($openApi['paths'] ?? []) < 100) t12DeveloperFail('management OpenAPI is unexpectedly incomplete');
$root = dirname($plugin, 2);
$rootInfo = parse_ini_file($root . '/info.ini');
$pluginInfo = parse_ini_file($plugin . '/info.ini');
if (($rootInfo['version'] ?? null) !== '0.7.1' || ($pluginInfo['version'] ?? null) !== '0.7.1') t12DeveloperFail('plugin package version is not consistently 0.7.1');
if (($openApi['info']['version'] ?? null) !== '0.13.0-candidate') t12DeveloperFail('management OpenAPI contract version drifted');
$managementContract = file_get_contents($root . '/docs/development/sand-iam-management-api-v0.1.md');
$packageContract = file_get_contents($root . '/docs/development/sand-iam-package-integrity.md');
if (!is_string($managementContract) || !is_string($packageContract) || !str_contains($managementContract, '两者独立演进') || !str_contains($packageContract, '独立的接口契约版本')) t12DeveloperFail('package and management API version boundary is undocumented');
$encoded = json_encode($openApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded) || preg_match('/(?:client_secret|access_token|refresh_token)"\s*:\s*"/i', $encoded)) t12DeveloperFail('OpenAPI contains secret material');

$events = EventCatalog::all();
if (count($events) < 15) t12DeveloperFail('event catalog is unexpectedly incomplete');
foreach ($events as $type => $definition) {
    if (!preg_match('/^[a-z][a-z0-9.]{2,95}$/', $type)) t12DeveloperFail("invalid event type {$type}");
    if (($definition['version'] ?? 0) !== 1 || ($definition['name'] ?? '') === '' || ($definition['purpose'] ?? '') === '') t12DeveloperFail("invalid event metadata {$type}");
}
foreach ([
    ['identity.login', 'succeeded', 'identity.login.succeeded'],
    ['identity.login', 'failed', 'identity.login.failed'],
    ['identity.profile_update', 'succeeded', 'identity.profile.updated'],
    ['identity.radius_login', 'succeeded', 'identity.login.succeeded'],
    ['policy.publish', 'succeeded', 'authorization.policy.changed'],
    ['policy.rollback', 'succeeded', 'authorization.policy.changed'],
    ['credential.revoke', 'succeeded', 'credential.changed'],
    ['oauth.authorize', 'denied', 'security.operation.denied'],
] as [$action, $outcome, $expected]) {
    if (EventCatalog::fromAudit($action, $outcome) !== $expected) t12DeveloperFail("audit event mapping failed for {$action}/{$outcome}");
}
if (EventCatalog::fromAudit('webhook.delivery', 'succeeded') !== null) t12DeveloperFail('webhook delivery would recursively emit webhook events');
$policySimulation = $openApi['paths']['/policy/simulate']['post'] ?? null;
if (!is_array($policySimulation) || ($policySimulation['x-sand-iam-permission'] ?? null) !== 'sand_iam:policy:read') t12DeveloperFail('policy simulation OpenAPI permission is missing or incorrect');
$policyRollback = $openApi['paths']['/policy/rollback']['post'] ?? null;
if (!is_array($policyRollback) || ($policyRollback['x-sand-iam-permission'] ?? null) !== 'sand_iam:policy:publish') t12DeveloperFail('policy rollback OpenAPI permission is missing or incorrect');

$logoutList = $openApi['paths']['/oauth-client/logout-delivery/index']['get'] ?? null;
$logoutReissue = $openApi['paths']['/oauth-client/logout-delivery/reissue']['post'] ?? null;
$schemas = $openApi['components']['schemas'] ?? [];
if (!is_array($logoutList) || !is_array($logoutReissue) || !is_array($schemas)) t12DeveloperFail('OIDC logout recovery OpenAPI operations are missing');
$listParameters = [];
foreach ($logoutList['parameters'] ?? [] as $parameter) {
    if (is_array($parameter) && is_string($parameter['name'] ?? null)) $listParameters[$parameter['name']] = $parameter;
}
if (($listParameters['id']['required'] ?? false) !== true
    || ($listParameters['id']['schema']['minimum'] ?? null) !== 1
    || ($listParameters['state']['schema']['enum'] ?? null) !== ['pending', 'sending', 'delivered', 'dead']
    || ($listParameters['limit']['schema']['maximum'] ?? null) !== 100) {
    t12DeveloperFail('OIDC logout delivery list query contract is incomplete');
}
if (($logoutList['responses']['200']['content']['application/json']['schema']['$ref'] ?? null) !== '#/components/schemas/OidcLogoutDeliveryListEnvelope'
    || ($logoutList['responses']['404']['$ref'] ?? null) !== '#/components/responses/NotFound') {
    t12DeveloperFail('OIDC logout delivery list response contract is incomplete');
}
$reissueHeader = $logoutReissue['parameters'][0] ?? null;
if (!is_array($reissueHeader) || ($reissueHeader['name'] ?? null) !== 'X-Request-Id' || ($reissueHeader['required'] ?? false) !== true) {
    t12DeveloperFail('OIDC logout recovery does not require X-Request-Id');
}
if (($logoutReissue['requestBody']['content']['application/json']['schema']['$ref'] ?? null) !== '#/components/schemas/OidcLogoutDeliveryReissueInput'
    || ($logoutReissue['responses']['200']['content']['application/json']['schema']['$ref'] ?? null) !== '#/components/schemas/OidcLogoutDeliveryReissueEnvelope'
    || ($logoutReissue['responses']['404']['$ref'] ?? null) !== '#/components/responses/NotFound'
    || ($logoutReissue['responses']['503']['$ref'] ?? null) !== '#/components/responses/Unavailable') {
    t12DeveloperFail('OIDC logout recovery request or response schema is incomplete');
}
$reissueInput = $schemas['OidcLogoutDeliveryReissueInput'] ?? null;
$reissueResult = $schemas['OidcLogoutDeliveryReissueResult'] ?? null;
$listItem = $schemas['OidcLogoutDeliveryListItem'] ?? null;
if (!is_array($reissueInput) || ($reissueInput['required'] ?? null) !== ['id', 'delivery_id'] || ($reissueInput['additionalProperties'] ?? true) !== false) {
    t12DeveloperFail('OIDC logout recovery input is not closed and exact');
}
if (!is_array($reissueResult)
    || ($reissueResult['required'] ?? null) !== ['source_delivery_id', 'delivery_id', 'event_id', 'state', 'already_reissued']
    || ($reissueResult['additionalProperties'] ?? true) !== false) {
    t12DeveloperFail('OIDC logout recovery result is not closed and exact');
}
if (!is_array($listItem)
    || array_key_exists('encrypted_logout_token', $listItem['properties'] ?? [])
    || array_key_exists('logout_token', $listItem['properties'] ?? [])
    || array_key_exists('encrypted_logout_token', $reissueResult['properties'] ?? [])
    || array_key_exists('logout_token', $reissueResult['properties'] ?? [])) {
    t12DeveloperFail('OIDC logout recovery OpenAPI exposes logout token material');
}
$recoveryErrors = $logoutReissue['x-sand-iam-error-codes'] ?? [];
foreach ([
    'SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_DISABLED',
    'SAND_IAM_OIDC_BACKCHANNEL_CLIENT_UNAVAILABLE',
    'SAND_IAM_OIDC_BACKCHANNEL_URI_UNAVAILABLE',
    'SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_FOUND',
    'SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_RECOVERABLE',
    'SAND_IAM_OIDC_LOGOUT_SESSION_NOT_REVOKED',
    'SAND_IAM_OIDC_LOGOUT_RECOVERY_CONFLICT',
    'SAND_IAM_IDEMPOTENCY_CONFLICT',
] as $errorCode) {
    if (!in_array($errorCode, $recoveryErrors, true)) t12DeveloperFail("OIDC logout recovery OpenAPI omits {$errorCode}");
}

echo 'developer OpenAPI and event catalog non-PG checks passed' . PHP_EOL;
