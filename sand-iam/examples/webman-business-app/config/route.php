<?php

declare(strict_types=1);

use Example\Matter\Matter;
use Example\Matter\MatterController;
use Example\Matter\MatterRepository;
use plugin\SandIam\app\middleware\ApplicationAuthorizationMiddleware;
use Webman\Http\Request;
use Webman\Route;

$scopeAttributes = static fn (Matter $matter): array => [
    'organization_id' => $matter->organizationId,
    'owner_identity_id' => $matter->ownerIdentityId,
];
$loadOne = static function (Request $request): Matter {
    $matter = (new MatterRepository())->findOrFail((int) $request->route->param('id'));
    $request->resolvedMatter = $matter;
    return $matter;
};

Route::get('/api/matter/v1/matters/{id}', [MatterController::class, 'read'])
    ->setParams(['sand_iam' => ['organization_code' => 'demoorg', 'application_code' => 'matter-demo', 'entity_scope' => ['mode' => 'entity', 'attributes' => $scopeAttributes, 'resolver' => $loadOne]]])
    ->middleware([ApplicationAuthorizationMiddleware::class]);
Route::post('/api/matter/v1/matters/{id}/archive', [MatterController::class, 'archive'])
    ->setParams(['sand_iam' => ['organization_code' => 'demoorg', 'application_code' => 'matter-demo', 'entity_scope' => ['mode' => 'entity', 'attributes' => $scopeAttributes, 'resolver' => $loadOne]]])
    ->middleware([ApplicationAuthorizationMiddleware::class]);
Route::post('/api/matter/v1/matters/batch/archive', [MatterController::class, 'batchArchive'])
    ->setParams(['sand_iam' => ['organization_code' => 'demoorg', 'application_code' => 'matter-demo', 'entity_scope' => ['mode' => 'collection', 'attributes' => $scopeAttributes, 'resolver' => static function (Request $request): array {
        $ids = $request->post('ids', []);
        if (!is_array($ids) || $ids === []) throw new \InvalidArgumentException('ids 必须是非空数组');
        $matters = (new MatterRepository())->findManyOrFail(array_map('intval', $ids));
        $request->resolvedMatters = $matters;
        return $matters;
    }]]])
    ->middleware([ApplicationAuthorizationMiddleware::class]);
