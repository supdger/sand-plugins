<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$plugin = dirname(__DIR__);
$root = dirname(__DIR__, 3);
if (!class_exists('plugin\\sandadmin\\exception\\ApiException')) {
    eval('namespace plugin\\sandadmin\\exception; class ApiException extends \\RuntimeException { public function __construct(string $message = "", int $code = 0) { parent::__construct($message, $code); } }');
}
require_once $plugin . '/app/developer/ApplicationBusinessActionCatalog.php';

use plugin\SandIam\app\developer\ApplicationBusinessActionCatalog;

function t28(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$action = ApplicationBusinessActionCatalog::declaration([
    'code' => 'matter.export', 'name' => '导出案件', 'description' => '将已授权案件导出为归档包', 'state' => 'published', 'status' => 1,
]);
t28($action['code'] === 'matter.export' && $action['name'] === '导出案件', 'declared action did not retain human metadata');
t28(ApplicationBusinessActionCatalog::sdkConstants([$action, ['code' => 'matter.read']]) === ['MATTER_EXPORT' => 'matter.export', 'MATTER_READ' => 'matter.read'], 'SDK constants are not stable');
foreach ([['code' => 'r', 'name' => '短'], ['code' => 'Matter.read', 'name' => '大小写'], ['code' => 'matter.read', 'name' => '有效', 'status' => 9]] as $invalid) {
    try { ApplicationBusinessActionCatalog::declaration($invalid); t28(false, 'invalid action declaration was accepted'); } catch (Throwable) {}
}
try {
    ApplicationBusinessActionCatalog::sdkConstants([['code' => 'matter.read'], ['code' => 'matter-read']]);
    t28(false, 'SDK constant collision was accepted');
} catch (Throwable) {}

$checks = [
    $plugin . '/app/model/ApplicationBusinessAction.php' => ['sand_iam_application_business_action'],
    $plugin . '/app/admin/controller/ApplicationBusinessActionController.php' => ['ApplicationBusinessActionCatalog', 'pendingClaims', "'sand_iam:api_resource:index'", 'SAND_IAM_APPLICATION_ACTION_CODE_IMMUTABLE', 'SAND_IAM_APPLICATION_ACTION_CONFLICT', 'throwWriteFailure', '从创建起即不可修改'],
    $plugin . '/app/admin/controller/ApiResourceController.php' => ['assertEnabled($applicationId, $action, true)', 'ApplicationBusinessActionCatalog'],
    $plugin . '/app/admin/controller/PolicyController.php' => ['ApplicationBusinessActionCatalog', 'assertEnabled($applicationId'],
    $plugin . '/app/runtime/ApplicationAuthorizationService.php' => ['ApplicationBusinessActionCatalog', 'assertEnabled(', 'false,', 'action_declaration_state'],
    $plugin . '/app/runtime/ApiGovernanceService.php' => ['ApplicationBusinessActionCatalog', 'assertEnabled(', 'true,'],
    $plugin . '/app/runtime/RouteBindingSynchronizer.php' => ['SAND_IAM_ROUTE_SYNC_ACTION_UNDECLARED', 'SAND_IAM_ROUTE_SYNC_ACTION_DISABLED', 'assertEnabled((int) $application->id, (string) $api->action, true)'],
    $plugin . '/app/initialization/InitializationPackage.php' => ["'business_actions'", 'SAND_IAM_INITIALIZATION_ACTION_UNDECLARED', 'ApplicationBusinessActionCatalog::declaration'],
    $plugin . '/app/service/InitializationService.php' => ["'application_business_action'", 'ApplicationBusinessAction::where', "'business_actions' => \$businessActions"],
    $plugin . '/app/developer/ManagementApiCatalog.php' => ["'application-business-action' => '应用业务动作'", '/application-business-action/pending-claims'],
    $plugin . '/config/route.php' => ["'application-business-action' => ApplicationBusinessActionController::class", 'ApplicationBusinessActionController::class'],
];
foreach ($checks as $file => $needles) {
    $content = file_get_contents($file);
    t28(is_string($content), "cannot read {$file}");
    foreach ($needles as $needle) t28(str_contains((string) $content, $needle), "missing {$needle} in {$file}");
}
$openApiSource = (string) file_get_contents($plugin . '/app/runtime/ApiGovernanceService.php');
t28(!str_contains($openApiSource, 'actionDeclarationState') && !str_contains($openApiSource, "false,\n        );"), 'OpenAPI must not expose pending_claim action declarations');

$sourceMigration = $root . '/migrations/028_application_business_action.pgsql';
$packageMigration = $plugin . '/migrations/028_application_business_action.pgsql';
t28(is_file($sourceMigration) && is_file($packageMigration) && hash_file('sha256', $sourceMigration) === hash_file('sha256', $packageMigration), '028 root/plugin migration copies differ');
$migration = (string) file_get_contents($sourceMigration);
foreach (['CREATE TABLE IF NOT EXISTS sand_iam_application_business_action', 'UNIQUE (application_id, code)', 'ON DELETE RESTRICT', "conrelid = 'sand_iam_application_business_action'::regclass", 'pending_claim', 'do not backfill', 'sand_iam_service_action'] as $needle) t28(str_contains($migration, $needle), "028 migration missing {$needle}");
t28(preg_match('/\\bAUTO_INCREMENT\\b|\\bUNSIGNED\\b|\\bENGINE\\s*=/i', $migration) !== 1, '028 migration contains non-PostgreSQL syntax');

echo 'application business action non-PG checks passed' . PHP_EOL;
