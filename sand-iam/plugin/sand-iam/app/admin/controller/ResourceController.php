<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Resource;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ResourceController extends ApplicationResourceController
{
    protected string $modelClass = Resource::class;
    protected array $writeFields = ['application_id', 'code', 'name', 'owner_field', 'organization_field', 'status'];
    protected array $requiredFields = ['application_id', 'code', 'name'];
    protected string $resourceType = 'resource';
    #[Permission('SandIAM 资源列表', 'sand_iam:resource:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 资源读取', 'sand_iam:resource:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 资源保存', 'sand_iam:resource:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 资源更新', 'sand_iam:resource:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 资源停用', 'sand_iam:resource:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['application_id']) && !Application::where('id', (int) $payload['application_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: application', 400); foreach (['owner_field', 'organization_field'] as $field) { if (isset($payload[$field]) && $payload[$field] !== '' && !preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) $payload[$field])) throw new ApiException('SAND_IAM_VALIDATION_ERROR: invalid ' . $field, 400); } }
}
