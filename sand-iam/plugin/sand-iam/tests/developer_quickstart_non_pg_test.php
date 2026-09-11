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
quickstartAssert(($config['actions']['MATTER_ARCHIVE'] ?? null) === 'matter.archive', 'PHP generated action constants are invalid');
foreach (['config/route.php', 'app/MatterRepository.php', 'app/MatterController.php', 'sdk/consume.php', 'sdk/consume.ts', 'sdk/consume.dart', '.env.example', 'README.md'] as $file) quickstartAssert(is_file($example . '/' . $file), "quickstart file missing: {$file}");
$route = (string) file_get_contents($example . '/config/route.php');
foreach (['ApplicationAuthorizationMiddleware', "'entity_scope'", "'resolver'", "'collection'", 'resolvedMatter', 'findManyOrFail'] as $needle) quickstartAssert(str_contains($route, $needle), "Webman entity guard example missing {$needle}");
$repository = (string) file_get_contents($example . '/app/MatterRepository.php');
foreach (['SELECT id, organization_id, owner_identity_id', 'business_matter', 'prepare('] as $needle) quickstartAssert(str_contains($repository, $needle), "repository is not loading database-owned entity fields: {$needle}");

require_once $root . '/sdk/php/src/SandIamException.php';
require_once $root . '/sdk/php/src/AuthorizationDenied.php';
require_once $root . '/sdk/php/src/SandIamClient.php';
$client = new SandIamClient('https://iam.example.test', $config['organization_code'], $config['application_code'], 3, static fn (): array => ['status' => 200, 'body' => json_encode(['data' => ['allowed' => true, 'code' => 'allowed', 'policy_ids' => [1], 'scope' => ['equals' => ['organization_id' => 1001]], 'application_id' => 1, 'identity_id' => 2, 'api_code' => 'matter.detail', 'api_version' => 'v1', 'resource_code' => 'matter', 'action' => 'matter.read', 'operation' => 'read', 'risk_level' => 'medium']], JSON_THROW_ON_ERROR)]);
$decision = $client->authorizeEntity('test-token-from-environment-only', $config['actions']['MATTER_READ'], (object) ['organization_id' => 1001], static fn (object $matter): array => ['organization_id' => $matter->organization_id], [], 'v1', 'quickstart-sdk-001');
quickstartAssert(($decision['allowed'] ?? false) === true, 'PHP SDK could not consume generated configuration');

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
$machine = $root . '/examples/sandai-machine-client';
foreach (['issue_context.php', 'verify_context.php', 'onboarding.service-grant.json', '.env.example', 'README.md'] as $file) quickstartAssert(is_file($machine . '/' . $file), "SandAI machine example missing: {$file}");
$grant = json_decode((string) file_get_contents($machine . '/onboarding.service-grant.json'), true, 16, JSON_THROW_ON_ERROR);
quickstartAssert(($grant['service_grants'][0]['service_code'] ?? null) === 'sand-ai' && ($grant['service_grants'][0]['action_code'] ?? null) === 'inference.chat', 'SandAI service grant must use service_code + action_code');
quickstartAssert(!str_contains((string) file_get_contents($machine . '/issue_context.php'), 'siam_'), 'SandAI sample must not embed a workload secret');
echo "developer quickstart non-PG checks passed\n";
