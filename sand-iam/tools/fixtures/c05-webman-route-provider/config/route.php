<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use plugin\SandIam\app\middleware\ApplicationAuthorizationMiddleware;
use plugin\SandIamC05Business\app\WorkItem;
use plugin\SandIamC05Business\app\WorkItemRepository;
use plugin\SandIamC05Business\app\controller\AcceptanceCleanupController;
use plugin\SandIamC05Business\app\controller\WorkItemController;
use plugin\SandIamC05Business\app\middleware\AuthorizationProblemMiddleware;
use Webman\Http\Request;
use Webman\Route;

if (getenv('SAND_IAM_C05_ROUTE_PROVIDER_ENABLED') !== 'I_CONFIRM_C05_TEMPORARY_ROUTE_PROVIDER') {
    return;
}

$organizationCode = trim((string) getenv('SAND_IAM_C05_ORGANIZATION_CODE'));
$applicationCode = trim((string) getenv('SAND_IAM_C05_APPLICATION_CODE'));
foreach ([$organizationCode, $applicationCode] as $code) {
    if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code) !== 1) {
        throw new \RuntimeException('C05 temporary route provider requires stable organization/application codes');
    }
}

$scopeAttributes = static fn (WorkItem $item): array => [
    'organization_id' => $item->organizationId,
    'owner_identity_id' => $item->ownerIdentityId,
];
$resolver = static function (Request $request): WorkItem {
    $item = (new WorkItemRepository())->findOrFail((int) $request->route->param('id'));
    $request->sandIamC05WorkItem = $item;
    return $item;
};

Route::post('/sand-iam-c05/v1/work-items/{id}/inspect', static fn (Request $request) => (new WorkItemController())->inspect($request))
    ->setParams(['sand_iam' => [
        'organization_code' => $organizationCode,
        'application_code' => $applicationCode,
        'attributes' => ['request_channel' => 'c05-live'],
        'entity_scope' => [
            'mode' => 'entity',
            'attributes' => $scopeAttributes,
            'resolver' => $resolver,
        ],
    ]])
    ->middleware([
        AuthorizationProblemMiddleware::class,
        ApplicationAuthorizationMiddleware::class,
    ]);

Route::post('/sand-iam-c05/v1/acceptance/cleanup', static fn (Request $request) => (new AcceptanceCleanupController())->cleanup($request));
Route::get('/sand-iam-c05/v1/acceptance/status', static fn (Request $request) => (new AcceptanceCleanupController())->status($request));
