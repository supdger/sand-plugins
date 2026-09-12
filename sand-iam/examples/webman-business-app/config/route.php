<?php

declare(strict_types=1);

use Example\WorkItem\WorkItem;
use Example\WorkItem\WorkItemController;
use Example\WorkItem\WorkItemRepository;
use plugin\SandIam\app\middleware\ApplicationAuthorizationMiddleware;
use Webman\Http\Request;
use Webman\Route;

$scopeAttributes = static fn (WorkItem $workItem): array => [
    'organization_id' => $workItem->organizationId,
    'owner_identity_id' => $workItem->ownerIdentityId,
];
$loadOne = static function (Request $request): WorkItem {
    $workItem = (new WorkItemRepository())->findOrFail((int) $request->route->param('id'));
    $request->resolvedWorkItem = $workItem;
    return $workItem;
};

Route::get('/api/work-items/v1/items/{id}', [WorkItemController::class, 'read'])
    ->setParams(['sand_iam' => ['organization_code' => 'demoorg', 'application_code' => 'work-item-demo', 'entity_scope' => ['mode' => 'entity', 'attributes' => $scopeAttributes, 'resolver' => $loadOne]]])
    ->middleware([ApplicationAuthorizationMiddleware::class]);
Route::post('/api/work-items/v1/items/{id}/close', [WorkItemController::class, 'close'])
    ->setParams(['sand_iam' => ['organization_code' => 'demoorg', 'application_code' => 'work-item-demo', 'entity_scope' => ['mode' => 'entity', 'attributes' => $scopeAttributes, 'resolver' => $loadOne]]])
    ->middleware([ApplicationAuthorizationMiddleware::class]);
Route::post('/api/work-items/v1/items/batch/close', [WorkItemController::class, 'batchClose'])
    ->setParams(['sand_iam' => ['organization_code' => 'demoorg', 'application_code' => 'work-item-demo', 'entity_scope' => ['mode' => 'collection', 'attributes' => $scopeAttributes, 'resolver' => static function (Request $request): array {
        $ids = $request->post('ids', []);
        if (!is_array($ids) || $ids === []) throw new \InvalidArgumentException('ids 必须是非空数组');
        $workItems = (new WorkItemRepository())->findManyOrFail(array_map('intval', $ids));
        $request->resolvedWorkItems = $workItems;
        return $workItems;
    }]]])
    ->middleware([ApplicationAuthorizationMiddleware::class]);
