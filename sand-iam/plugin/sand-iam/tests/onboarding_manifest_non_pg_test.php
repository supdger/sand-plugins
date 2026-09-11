<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

if (!class_exists('plugin\\sandadmin\\exception\\ApiException')) eval('namespace plugin\\sandadmin\\exception; class ApiException extends \\RuntimeException { public function __construct(string $message = "", int $code = 0) { parent::__construct($message, $code); } }');
require_once dirname(__DIR__) . '/app/runtime/ScopeMatcher.php';
require_once dirname(__DIR__) . '/app/developer/ApplicationBusinessActionCatalog.php';
require_once dirname(__DIR__) . '/app/initialization/InitializationPackage.php';
require_once dirname(__DIR__) . '/app/developer/RouteSyncManifest.php';
require_once dirname(__DIR__) . '/app/developer/OnboardingManifest.php';
require_once dirname(__DIR__) . '/app/service/OnboardingService.php';

use plugin\SandIam\app\developer\OnboardingManifest;

function onboardingAssert(bool $value, string $message): void { if (!$value) { fwrite(STDERR, $message . PHP_EOL); exit(1); } }
$manifest = [
 'format' => 'sand-iam.onboarding/v1', 'operation_id' => 'onboarding-20260822', 'organization' => ['code' => 'sand'],
 'initialization' => ['format' => 'sand-iam.initialization/v1', 'package_code' => 'law-v1', 'organization_code' => 'ignored', 'application' => ['code' => 'lawyer', 'name' => '律序'], 'roles' => [['code' => 'lawyer', 'name' => '律师']], 'user_types' => [], 'resources' => [['code' => 'matter', 'name' => '案件', 'owner_field' => 'owner_id', 'organization_field' => 'organization_id']], 'business_actions' => [['code' => 'matter.read', 'name' => '查看案件', 'description' => '读取案件']], 'identity_providers' => [], 'policies' => [['key' => 'read', 'resource_code' => 'matter', 'role_code' => 'lawyer', 'action' => 'matter.read', 'effect' => 'allow', 'condition' => [], 'scope' => []]],],
 'environment' => ['code' => 'production', 'name' => '生产'],
 'api_resources' => [['code' => 'matter.detail', 'name' => '查看案件详情', 'resource_code' => 'matter', 'action' => 'matter.read', 'operation' => 'read', 'api_version' => 'v1', 'audience' => 'lawyer-api', 'risk_level' => 'medium']],
 'routes' => [['method' => 'GET', 'path' => '/api/law/v1/matters/{id}', 'sand_iam' => ['api_code' => 'matter.detail']]],
 'workload_client' => ['code' => 'lawyer-api', 'name' => '律序 API', 'audience' => 'lawyer-api'],
 'service_grants' => [['service_code' => 'sand-iam', 'action_code' => 'context.issue']],
];
$normalized = OnboardingManifest::normalize($manifest);
onboardingAssert($normalized['organization']['code'] === 'sand' && $normalized['route_manifest']['routes'][0]['api_code'] === 'matter.detail', 'manifest normalization lost application route');
onboardingAssert(plugin\SandIam\app\developer\RouteSyncManifest::requireNormalized($normalized['route_manifest']) === $normalized['route_manifest'], 'onboarding route manifest is not consumable as one normalized internal contract');
$handoff = OnboardingManifest::handoff($normalized);
onboardingAssert(str_contains($handoff['env_template'], 'SAND_IAM_WORKLOAD_CREDENTIAL=') && !str_contains($handoff['env_template'], 'siam_'), 'handoff template exposed a secret');
onboardingAssert(str_contains($handoff['dart_constants'], 'sandIamActions') && str_contains($handoff['dart_constants'], 'MATTER_READ'), 'handoff did not generate Dart action constants');
$invalid = $manifest; $invalid['organization']['create'] = true;
try { OnboardingManifest::normalize($invalid); onboardingAssert(false, 'manifest accepted implicit organization creation'); } catch (Throwable) {}
$invalid = $manifest; $invalid['routes'][0]['sand_iam']['api_code'] = 'missing.api';
try { OnboardingManifest::normalize($invalid); onboardingAssert(false, 'manifest accepted route API absent from catalog'); } catch (Throwable) {}
$invalid = $manifest; $invalid['service_grants'] = [['action_code' => 'context.issue']];
try { OnboardingManifest::normalize($invalid); onboardingAssert(false, 'manifest accepted service grant without service_code'); } catch (Throwable) {}
$service = (string) file_get_contents(dirname(__DIR__) . '/app/service/OnboardingService.php');
foreach (['IdempotencyService', 'Db::startTrans()', 'Db::rollback()', 'InitializationService', 'RouteBindingSynchronizer', 'synchronizeNormalized', 'Service::where', "'service_code'", 'CredentialIssuanceService', 'childRequestId', 'verification'] as $needle) onboardingAssert(str_contains($service, $needle), "onboarding apply missing {$needle}");
foreach (['ApplicationBusinessAction', 'ApiRouteBinding', 'credentials_metadata', 'credential_intent', 'service_grants', 'Policy::where', 'lock(true)', 'SAND_IAM_ONBOARDING_PREVIEW_STALE'] as $needle) onboardingAssert(str_contains($service, $needle), "onboarding current-state plan missing {$needle}");
foreach (['SAND_IAM_IDEMPOTENCY_RETRY_REQUIRED', 'waitForCompletedReplay', 'replayIfCompleted', '->replay(', "'secret_available' => false"] as $needle) onboardingAssert(str_contains($service . (string) file_get_contents(dirname(__DIR__) . '/app/service/IdempotencyService.php'), $needle), "onboarding replay safety missing {$needle}");
onboardingAssert(\plugin\SandIam\app\service\OnboardingService::advisoryKey(42, 7) === \plugin\SandIam\app\service\OnboardingService::advisoryKey(42, 7), 'advisory lock key is not stable');
onboardingAssert(count(\plugin\SandIam\app\service\OnboardingService::advisoryKey(42, 7)) === 2, 'advisory lock key must be two PostgreSQL int4 values');
onboardingAssert(\plugin\SandIam\app\service\OnboardingService::advisoryKey(42, 7) === [753484078, -2012783371], 'advisory lock key changed its documented int4 derivation');
$pg = (string) file_get_contents(dirname(__DIR__) . '/tests/onboarding_concurrency_pg_integration_test.php');
foreach (['OnboardingService', 'onboardingPgCompete', 'proc_open', "--onboarding-worker", 'Db::connect(null, true)', 'each worker owns its database handle', 'onboardingPgApplyWorker', 'onboardingPgMutateFromAnotherProcess', 'onboardingPgCleanup', "whereIn('request_id'", "'secret_available'", 'SAND_IAM_ONBOARDING_PREVIEW_STALE'] as $needle) onboardingAssert(str_contains($pg, $needle), "PG concurrency fixture missing {$needle}");
onboardingAssert(!str_contains($pg, 'pcntl_fork'), 'PG concurrency fixture must use independent PHP workers rather than forked PDO state');
onboardingAssert(!str_contains($pg, "where('operation', 'onboarding.apply')->where('resource_type'"), 'PG cleanup must not delete every onboarding security operation');
$controller = (string) file_get_contents(dirname(__DIR__) . '/app/admin/controller/DeveloperController.php');
foreach (['onboardingPreview', 'onboardingApply', 'SAND_IAM_ONBOARDING_APPLY_REQUIRED', "withHeader('Cache-Control', 'no-store')", 'assertOrganization'] as $needle) onboardingAssert(str_contains($controller, $needle), "onboarding API missing {$needle}");
echo "onboarding manifest non-PG checks passed\n";
