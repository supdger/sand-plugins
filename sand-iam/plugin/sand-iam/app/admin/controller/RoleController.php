<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Role;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class RoleController extends ApplicationResourceController
{
    protected string $modelClass = Role::class;
    protected array $writeFields = ['application_id', 'code', 'name', 'status'];
    protected array $requiredFields = ['application_id', 'code', 'name'];
    protected string $resourceType = 'role';
    #[Permission('SandIAM 角色列表', 'sand_iam:role:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 角色读取', 'sand_iam:role:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 角色保存', 'sand_iam:role:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 角色更新', 'sand_iam:role:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 角色停用', 'sand_iam:role:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void { if (isset($payload['application_id']) && !Application::where('id', (int) $payload['application_id'])->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: application', 400); }
}
