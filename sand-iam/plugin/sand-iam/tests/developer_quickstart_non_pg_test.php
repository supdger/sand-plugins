<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

use Sand\Iam\Sdk\SandIamClient;
use plugin\SandIam\app\developer\ManagementApiCatalog;

function quickstartAssert(bool $value, string $message): void { if (!$value) { fwrite(STDERR, $message . PHP_EOL); exit(1); } }

$root = dirname(__DIR__, 3);
$example = $root . '/examples/webman-business-app';
$manifest = json_decode((string) file_get_contents($example . '/onboarding.manifest.json'), true, 64, JSON_THROW_ON_ERROR);
$config = require $example . '/generated/sand_iam.php';
quickstartAssert(($config['organization_code'] ?? null) === $manifest['organization']['code'], 'PHP generated config drifted from onboarding organization');
quickstartAssert(($config['application_code'] ?? null) === $manifest['initialization']['application']['code'], 'PHP generated config drifted from onboarding application');
quickstartAssert(($config['actions']['WORK_ITEM_CLOSE'] ?? null) === 'work_item.close', 'PHP generated action constants are invalid');
foreach (['config/route.php', 'app/WorkItemRepository.php', 'app/WorkItemController.php', 'sdk/consume.php', 'sdk/consume.ts', 'sdk/consume.dart', '.env.example', 'README.md'] as $file) quickstartAssert(is_file($example . '/' . $file), "quickstart file missing: {$file}");
$route = (string) file_get_contents($example . '/config/route.php');
foreach (['ApplicationAuthorizationMiddleware', "'entity_scope'", "'resolver'", "'collection'", 'resolvedWorkItem', 'findManyOrFail'] as $needle) quickstartAssert(str_contains($route, $needle), "Webman entity guard example missing {$needle}");
$repository = (string) file_get_contents($example . '/app/WorkItemRepository.php');
foreach (['SELECT id, organization_id, owner_identity_id', 'business_work_item', 'prepare('] as $needle) quickstartAssert(str_contains($repository, $needle), "repository is not loading database-owned entity fields: {$needle}");
$c05Provider = $root . '/tools/fixtures/c05-webman-route-provider';
foreach ([
    'config/autoload.php',
    'config/route.php',
    'app/WorkItemRepository.php',
    'app/BusinessAuditWriter.php',
    'app/controller/WorkItemController.php',
    'app/controller/AcceptanceCleanupController.php',
    'app/middleware/AuthorizationProblemMiddleware.php',
] as $file) quickstartAssert(is_file($c05Provider . '/' . $file), "C05 Webman route provider file missing: {$file}");
$c05Autoload = (string) file_get_contents($c05Provider . '/config/autoload.php');
foreach (['spl_autoload_register', "plugin\\\\SandIamC05Business\\\\", 'dirname(__DIR__)'] as $needle) quickstartAssert(str_contains($c05Autoload, $needle), "C05 Webman route provider autoload is incomplete: {$needle}");
$c05Route = (string) file_get_contents($c05Provider . '/config/route.php');
foreach(["require_once __DIR__ . '/autoload.php'", 'I_CONFIRM_C05_TEMPORARY_ROUTE_PROVIDER', '/sand-iam-c05/v1/work-items/{id}/inspect', 'new WorkItemController()', 'new AcceptanceCleanupController()', 'ApplicationAuthorizationMiddleware', "'entity_scope'", "'resolver'"] as $needle) quickstartAssert(str_contains($c05Route, $needle), "C05 Webman route provider is not bound to the real authorization middleware: {$needle}");
quickstartAssert(strpos($c05Route, 'AuthorizationProblemMiddleware::class') < strpos($c05Route, 'ApplicationAuthorizationMiddleware::class'), 'C05 denial response middleware must wrap the SandIAM authorization middleware');
$c05ProblemMiddleware = (string) file_get_contents($c05Provider . '/app/middleware/AuthorizationProblemMiddleware.php');
foreach (['$response = $handler($request)', '$response->exception()', 'instanceof ApiException', "SAND_IAM_ROUTE_NOT_REGISTERED')"] as $needle) quickstartAssert(str_contains($c05ProblemMiddleware, $needle), "C05 denial audit does not inspect the exact exception response returned by Webman: {$needle}");
$c05AuditWriter = (string) file_get_contents($c05Provider . '/app/BusinessAuditWriter.php');
quickstartAssert(str_contains($c05AuditWriter, "], 'id')"), 'C05 PostgreSQL audit insert does not identify its generated identity column');
$c05Cleanup = (string) file_get_contents($c05Provider . '/app/controller/AcceptanceCleanupController.php');
foreach (['authenticatedPrincipal', 'I_CONFIRM_DELETE_ONLY_C05_BUSINESS_AUDIT', "where('action', 'c05.route.inspect')", "where('work_item_id'"] as $needle) quickstartAssert(str_contains($c05Cleanup, $needle), "C05 business audit cleanup is not narrowly scoped: {$needle}");

require_once $root . '/sdk/php/src/SandIamException.php';
require_once $root . '/sdk/php/src/AuthorizationDenied.php';
require_once $root . '/sdk/php/src/SandIamClient.php';
$client = new SandIamClient('https://iam.example.test', $config['organization_code'], $config['application_code'], 3, static fn (): array => ['status' => 200, 'body' => json_encode(['data' => ['allowed' => true, 'code' => 'allowed', 'policy_ids' => [1], 'scope' => ['equals' => ['organization_id' => 1001]], 'application_id' => 1, 'identity_id' => 2, 'api_code' => 'work_item.detail', 'api_version' => 'v1', 'resource_code' => 'work_item', 'action' => 'work_item.read', 'operation' => 'read', 'risk_level' => 'medium', 'scope_checked' => true]], JSON_THROW_ON_ERROR)]);
$decision = $client->authorizeEntity('test-token-from-environment-only', $config['actions']['WORK_ITEM_READ'], (object) ['organization_id' => 1001], static fn (object $workItem): array => ['organization_id' => $workItem->organization_id], [], 'v1', 'quickstart-sdk-001');
quickstartAssert(($decision['allowed'] ?? false) === true, 'PHP SDK could not consume generated configuration');
$integrationGuide = (string) file_get_contents($root . '/docs/user-guide/application-integration.md');
$documentJson = static function (string $marker) use ($integrationGuide): array {
    if (preg_match('/<!-- sand-iam-doc-contract: ' . preg_quote($marker, '/') . ' -->\s*```json\s*(\{.*?\})\s*```/s', $integrationGuide, $match) !== 1) {
        throw new RuntimeException('documented authorization contract marker is missing: ' . $marker);
    }
    return json_decode($match[1], true, 64, JSON_THROW_ON_ERROR);
};
$allowEnvelope = $documentJson('decide.allow');
$denyEnvelope = $documentJson('decide.deny');
$errorEnvelope = $documentJson('decide.error');
$documentAllowClient = new SandIamClient('https://iam.example.test', 'acme', 'workbench', 3, static fn (): array => ['status' => 200, 'body' => json_encode($allowEnvelope, JSON_THROW_ON_ERROR)]);
$documentAllow = $documentAllowClient->authorize('document-token', 'work_item.read', ['organization_id' => 42], 'v1', 'document-allow-001');
quickstartAssert(($documentAllow['allowed'] ?? false) === true && ($documentAllow['api_code'] ?? null) === 'work_item.read' && ($documentAllow['application_id'] ?? null) === 3 && ($documentAllow['identity_id'] ?? null) === 101, 'documented allow response is not consumable by the PHP SDK');
$documentDenyClient = new SandIamClient('https://iam.example.test', 'acme', 'workbench', 3, static fn (): array => ['status' => 200, 'body' => json_encode($denyEnvelope, JSON_THROW_ON_ERROR)]);
try { $documentDenyClient->authorize('document-token', 'work_item.read', ['organization_id' => 42], 'v1', 'document-deny-001'); quickstartAssert(false, 'documented deny response did not fail closed'); } catch (\Sand\Iam\Sdk\AuthorizationDenied $exception) { quickstartAssert($exception->errorCode === 'SAND_IAM_POLICY_DENIED', 'documented deny response changed the stable SDK error'); }
$documentErrorClient = new SandIamClient('https://iam.example.test', 'acme', 'workbench', 3, static fn (): array => ['status' => 403, 'body' => json_encode($errorEnvelope, JSON_THROW_ON_ERROR)]);
try { $documentErrorClient->authorize('document-token', 'work_item.read', ['organization_id' => 42], 'v1', 'document-error-001'); quickstartAssert(false, 'documented error response did not fail closed'); } catch (\Sand\Iam\Sdk\SandIamException $exception) { quickstartAssert($exception->errorCode === 'SAND_IAM_RESOURCE_SCOPE_DENIED' && $exception->httpStatus === 403, 'documented error response changed the stable SDK error'); }

$typescript = $root . '/sdk/typescript/node_modules/.bin/tsc';
if (is_executable($typescript)) {
    exec(escapeshellarg($typescript) . ' --noEmit -p ' . escapeshellarg($example . '/sdk/tsconfig.json'), $output, $status);
    quickstartAssert($status === 0, 'TypeScript SDK cannot parse generated configuration: ' . implode("\n", $output));
} else {
    fwrite(STDOUT, "TypeScript compiler unavailable; SDK sample source retained for its package build\n");
}
$dartSource = (string) file_get_contents($example . '/sdk/consume.dart');
quickstartAssert(str_contains($dartSource, "import '../generated/sand_iam.dart';") && str_contains($dartSource, 'SandIamClient(') && str_contains($dartSource, "Platform.environment['SAND_IAM_ACCESS_TOKEN']"), 'Dart SDK consumer no longer parses generated config or reads runtime secret');

require_once dirname(__DIR__) . '/app/developer/ManagementApiCatalog.php';
$byKey = [];
foreach (ManagementApiCatalog::routes() as $routeSpec) $byKey[$routeSpec['method'] . ' ' . $routeSpec['path']] = $routeSpec['permission'];
quickstartAssert(($byKey['POST /developer/onboarding/preview'] ?? null) === 'sand_iam:onboarding:preview', 'onboarding preview catalog permission drifted');
quickstartAssert(($byKey['POST /developer/onboarding/apply'] ?? null) === 'sand_iam:onboarding:apply', 'onboarding apply catalog permission drifted');
$routeSource = (string) file_get_contents(dirname(__DIR__) . '/config/route.php');
quickstartAssert(str_contains($routeSource, "Route::post('/app/sand-iam/admin/developer/onboarding/preview'") && str_contains($routeSource, "Route::post('/app/sand-iam/admin/developer/onboarding/apply'"), 'onboarding management routes drifted from quickstart');
$developerController = (string) file_get_contents(dirname(__DIR__) . '/app/admin/controller/DeveloperController.php');
quickstartAssert(str_contains($developerController, "#[Permission('SandIAM 开发者接入预检', 'sand_iam:onboarding:preview')]") && str_contains($developerController, "#[Permission('SandIAM 开发者接入应用', 'sand_iam:onboarding:apply')]"), 'onboarding controller permissions drifted from catalog');
$machine = $root . '/examples/machine-service-client';
foreach (['MachineServiceHttpClient.php', 'issue_context.php', 'verify_context.php', 'onboarding.service-grant.json', '.env.example', 'README.md'] as $file) quickstartAssert(is_file($machine . '/' . $file), "machine service example missing: {$file}");
$grant = json_decode((string) file_get_contents($machine . '/onboarding.service-grant.json'), true, 16, JSON_THROW_ON_ERROR);
require_once $machine . '/MachineServiceHttpClient.php';
$validMachineResponse = MachineServiceHttpClient::decode('{"data":{"context_id":"ctx-1","service_code":"document-service","audience":"document-service","actions":["document.read"]}}', 200);
quickstartAssert(($validMachineResponse['context_id'] ?? null) === 'ctx-1', 'machine response helper lost valid data');
foreach ([[403, '{"msg":"SAND_IAM_SERVICE_ACTION_FORBIDDEN: denied"}'], [200, '{"data":[] }'], [200, 'not-json']] as [$status, $response]) {
    try { MachineServiceHttpClient::decode($response, $status); quickstartAssert(false, 'machine response helper accepted a denied or malformed response'); } catch (RuntimeException) {}
}
foreach ([
    static fn (): array => MachineServiceHttpClient::postJson('http://iam.example.test/runtime/context/verify', []),
    static fn (): array => MachineServiceHttpClient::postJson('https://iam.example.test/runtime/context/verify', [], ['Authorization' => "Bearer value\r\nX-Injected: yes"]),
] as $unsafeRequest) {
    try { $unsafeRequest(); quickstartAssert(false, 'machine HTTP helper accepted an insecure URL or injected header'); } catch (InvalidArgumentException) {}
}
$issueSource = (string) file_get_contents($machine . '/issue_context.php');
$verifySource = (string) file_get_contents($machine . '/verify_context.php');
quickstartAssert(str_contains($issueSource, 'MachineServiceHttpClient::postJson') && !str_contains($issueSource, "'credential' =>"), 'machine issue example bypassed the fail-closed HTTP helper or put credential in body');
foreach (['MachineServiceHttpClient::postJson', "hash_equals(\$serviceCode", "hash_equals(\$audience", "'allowed' => true"] as $needle) quickstartAssert(str_contains($verifySource, $needle), "machine verify example missing fail-closed check: {$needle}");
quickstartAssert(!str_contains($verifySource, 'echo $response') && !str_contains($verifySource, "'context' => \$data"), 'machine verify example prints the raw response or signed context');
quickstartAssert(($grant['service_grants'][0]['service_code'] ?? null) === 'document-service' && ($grant['service_grants'][0]['action_code'] ?? null) === 'document.read', 'machine service grant must use service_code + action_code');
quickstartAssert(!str_contains((string) file_get_contents($machine . '/issue_context.php'), 'siam_'), 'machine service sample must not embed a workload secret');
echo "developer quickstart non-PG checks passed\n";
