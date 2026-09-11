<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$plugin = dirname(__DIR__);
if (!class_exists('plugin\\sandadmin\\exception\\ApiException')) {
    eval('namespace plugin\\sandadmin\\exception; class ApiException extends \\RuntimeException { public function __construct(string $message = "", int $code = 0) { parent::__construct($message, $code); } }');
}
require_once $plugin . '/app/runtime/ScopeMatcher.php';
require_once $plugin . '/app/developer/ApplicationBusinessActionCatalog.php';
require_once $plugin . '/app/initialization/InitializationPackage.php';

use plugin\SandIam\app\initialization\InitializationPackage;

function t12Initialization(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$manifest = [
    'format' => 'sand-iam.initialization/v1',
    'package_code' => 'lvxu-base',
    'organization_code' => 'sand',
    'application' => ['code' => 'lvxu', 'name' => '律序', 'status' => 1],
    'roles' => [['code' => 'lawyer', 'name' => '律师', 'status' => 1]],
    'user_types' => [['code' => 'professional', 'name' => '专业用户', 'status' => 1]],
    'resources' => [['code' => 'case', 'name' => '案件', 'owner_field' => 'lawyer_id', 'organization_field' => 'firm_id', 'status' => 1]],
    'business_actions' => [['code' => 'case.read', 'name' => '查看案件', 'description' => '读取案件详情', 'state' => 'published', 'status' => 1]],
    'identity_providers' => [['code' => 'local', 'name' => '律序账号', 'provider_type' => 'local', 'status' => 1]],
    'policies' => [[
        'key' => 'lawyer-case-read', 'resource_code' => 'case', 'role_code' => 'lawyer', 'action' => 'case.read', 'effect' => 'allow',
        'condition' => ['equals' => ['enabled' => true]], 'scope' => ['equals' => ['lawyer_id' => 100]], 'priority' => 100, 'state' => 'published', 'status' => 1,
    ]],
];
$normalized = InitializationPackage::normalize($manifest);
t12Initialization($normalized['application']['name'] === '律序', 'human-readable application name was lost');
$reordered = $manifest;
$reordered['roles'] = array_reverse($reordered['roles']);
t12Initialization(InitializationPackage::hash($normalized) === InitializationPackage::hash(InitializationPackage::normalize($reordered)), 'canonical package hash is not stable');

foreach ([
    $manifest + ['client_secret' => 'must-not-enter-package'],
    array_replace_recursive($manifest, ['identity_providers' => [['code' => 'oidc', 'name' => '外部登录', 'provider_type' => 'oidc', 'status' => 1]]]),
    array_replace_recursive($manifest, ['policies' => [[
        'key' => 'broken', 'resource_code' => 'missing', 'role_code' => 'lawyer', 'action' => 'case.read', 'effect' => 'allow', 'condition' => [], 'scope' => [], 'priority' => 0, 'state' => 'draft', 'status' => 1,
    ]]]),
    array_replace_recursive($manifest, ['policies' => [[
        'key' => 'disabled-published', 'resource_code' => 'case', 'role_code' => 'lawyer', 'action' => 'case.read', 'effect' => 'allow', 'condition' => [], 'scope' => [], 'priority' => 0, 'state' => 'published', 'status' => 2,
    ]]]),
    array_replace_recursive($manifest, ['policies' => [[
        'key' => 'unknown-action', 'resource_code' => 'case', 'role_code' => 'lawyer', 'action' => 'case.delete', 'effect' => 'allow', 'condition' => [], 'scope' => [], 'priority' => 0, 'state' => 'draft', 'status' => 1,
    ]]]),
] as $invalid) {
    try { InitializationPackage::normalize($invalid); t12Initialization(false, 'unsafe initialization package was accepted'); } catch (Throwable) {}
}

$service = file_get_contents($plugin . '/app/service/InitializationService.php');
t12Initialization(is_string($service), 'cannot read initialization service');
foreach ([
    'SAND_IAM_INITIALIZATION_PREVIEW_STALE',
    'lock(true)',
    "whereNull('identity_id')",
    'SAND_IAM_INITIALIZATION_BINDING_DRIFT',
    'SAND_IAM_INITIALIZATION_POLICY_TARGET_CONFLICT',
    'application_business_action',
    "['resource_id', 'role_id', 'action', 'effect', 'condition', 'scope', 'priority', 'state', 'status']",
    'policyChange',
    'rollbackChange',
    'SAND_IAM_INITIALIZATION_ROLLBACK_DRIFT',
    'hash_equals($expected, $confirmation)',
    'Db::rollback()',
    'RequestId::normalize($requestId)',
    'IdempotencyService::fingerprint([\'manifest\' => $manifest, \'preview_hash\' => $expectedPreviewHash])',
    "'initialization.apply'",
    "'initialization.rollback'",
    'replayIfCompleted(',
    'SAND_IAM_IDEMPOTENCY_RETRY_REQUIRED',
    "'request_id' => \$requestId",
] as $needle) t12Initialization(str_contains($service, $needle), "initialization service missing {$needle}");

foreach ([
    $plugin . '/app/admin/controller/InitializationController.php',
    $plugin . '/app/admin/controller/MessageProviderController.php',
    $plugin . '/app/middleware/InitializationSensitiveMiddleware.php',
    $plugin . '/app/middleware/MessageProviderSensitiveMiddleware.php',
] as $requestIdSource) {
    $content = file_get_contents($requestIdSource);
    t12Initialization(is_string($content) && str_contains($content, 'RequestId::fromRequestCached($request)'), "request-id cache is missing from {$requestIdSource}");
}

$root = file_get_contents(dirname($plugin, 2) . '/migrations/020_initialization_package.pgsql');
$copy = file_get_contents($plugin . '/migrations/020_initialization_package.pgsql');
t12Initialization(is_string($root) && $root === $copy, '020 migration copies differ');
t12Initialization(str_contains($root, 'sand_iam_initialization_run') && str_contains($root, 'sand_iam_initialization_binding'), '020 migration is incomplete');
$compatibility = dirname($plugin, 2) . '/migrations/032_initialization_binding_application_business_action.pgsql';
$compatibilityCopy = $plugin . '/migrations/032_initialization_binding_application_business_action.pgsql';
t12Initialization(is_file($compatibility) && hash_file('sha256', $compatibility) === hash_file('sha256', $compatibilityCopy), '032 compatibility migration copies differ');
$compatibilitySql = (string) file_get_contents($compatibility);
foreach (['DROP CONSTRAINT IF EXISTS ck_sand_iam_initialization_binding_type', 'application_business_action', 'sand_iam_application_business_action'] as $needle) {
    t12Initialization(str_contains($compatibilitySql, $needle), "032 compatibility migration lacks {$needle}");
}

echo 'initialization package non-PG checks passed' . PHP_EOL;
